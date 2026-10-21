<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms;

use BifrostOrbit\Cms\Exception\AuthException;
use BifrostOrbit\Cms\Exception\CmsException;
use BifrostOrbit\Cms\Exception\NotFoundException;
use BifrostOrbit\Cms\Exception\RateLimitException;
use BifrostOrbit\Cms\Exception\UnavailableException;
use BifrostOrbit\Cms\Http\CurlTransport;
use BifrostOrbit\Cms\Http\HttpRequest;
use BifrostOrbit\Cms\Http\HttpResponse;
use BifrostOrbit\Cms\Http\Psr18Transport;
use BifrostOrbit\Cms\Http\StreamTransport;
use BifrostOrbit\Cms\Http\Transport;
use BifrostOrbit\Cms\Http\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Reads content from the CMS Orbit delivery API.
 *
 * Every method answers with the API's `data` as associative arrays, in the same shapes as the
 * JavaScript SDK. Pages gain a `meta` array, and {@see resolve()} answers with `kind` and,
 * for a redirect, `to`, `status`, `permanent` and `hops`.
 *
 * @phpstan-type RequestOptions array{region?: string|null, locale?: string|null, preview?: bool, strict?: bool, include?: list<string>}
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.bifrostorbit.com/cms';

    private const RETRY_DELAYS_MS = [250, 750, 2000];
    private const PAGE_OPTIONS = ['region', 'locale', 'preview', 'strict', 'include'];

    /** The delivery API address, such as https://api.bifrostorbit.com/cms/api/cdn/v1 */
    public readonly string $api;
    /** The CMS address that preview and GraphQL live under, such as https://api.bifrostorbit.com/cms */
    public readonly string $origin;
    public readonly ?string $defaultRegion;
    public readonly ?string $defaultLocale;

    private readonly string $key;
    private readonly Transport $transport;
    private readonly int $retries;
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param string $apiKey A public key (cms_pk_) for published content. A secret key (cms_sk_) also works, since PHP runs on the server.
     * @param string|null $baseUrl The API address. https://api.bifrostorbit.com/cms by default; use https://api-stage.bifrostorbit.com/cms for staging.
     * @param string|null $previewKey A secret key (cms_sk_) for drafts, from a staging environment. Needed for `preview => true`.
     * @param string|null $defaultRegion Used when a call names no region.
     * @param string|null $defaultLocale Used when a call names no language. Without it, the region's own language answers.
     * @param int $timeout Per attempt, in milliseconds.
     * @param int $retries Extra attempts after a network error or a server error.
     * @param Transport|ClientInterface|null $httpClient A transport, or any PSR-18 client together with $requestFactory and $streamFactory. ext-curl, or PHP streams, by default.
     * @param (\Closure(int): void)|null $sleep Waits between retries, given milliseconds. For tests.
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        private readonly ?string $previewKey = null,
        ?string $defaultRegion = null,
        ?string $defaultLocale = null,
        private readonly int $timeout = 5000,
        int $retries = 2,
        Transport|ClientInterface|null $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?\Closure $sleep = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('An apiKey is required');
        }
        if (!str_starts_with($apiKey, 'cms_pk_') && !str_starts_with($apiKey, 'cms_sk_')) {
            throw new \InvalidArgumentException('The apiKey must be a CMS delivery key, starting with cms_pk_ or cms_sk_');
        }
        if ($previewKey !== null && $previewKey !== '' && !str_starts_with($previewKey, 'cms_sk_')) {
            throw new \InvalidArgumentException('The previewKey must be a secret key, starting with cms_sk_. Public keys cannot read drafts.');
        }
        if ($timeout < 1) {
            throw new \InvalidArgumentException('The timeout must be at least 1 millisecond');
        }

        $this->key = $apiKey;
        $this->api = self::apiBase($baseUrl === null || $baseUrl === '' ? self::DEFAULT_BASE_URL : $baseUrl);
        $this->origin = (string) preg_replace('#/api/cdn/v\d+$#', '', $this->api);
        $this->defaultRegion = $defaultRegion === '' ? null : $defaultRegion;
        $this->defaultLocale = $defaultLocale === '' ? null : $defaultLocale;
        $this->retries = max(0, $retries);
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };

        if ($httpClient instanceof ClientInterface) {
            if ($requestFactory === null || $streamFactory === null) {
                throw new \InvalidArgumentException('A PSR-18 httpClient needs a requestFactory and a streamFactory');
            }
            $this->transport = new Psr18Transport($httpClient, $requestFactory, $streamFactory);
        } else {
            $this->transport = $httpClient ?? (extension_loaded('curl') ? new CurlTransport() : new StreamTransport());
        }
    }

    /**
     * Builds a client from an options array, as kept in a framework's configuration.
     *
     * @param array<string, mixed> $options The constructor's arguments by name: apiKey, baseUrl, previewKey, defaultRegion, defaultLocale, timeout, retries, httpClient, requestFactory, streamFactory
     */
    public static function create(array $options): self
    {
        $known = ['apiKey', 'baseUrl', 'previewKey', 'defaultRegion', 'defaultLocale', 'timeout', 'retries', 'httpClient', 'requestFactory', 'streamFactory'];
        self::checkOptions($options, $known, 'create');
        if (!isset($options['apiKey'])) {
            throw new \InvalidArgumentException('An apiKey is required');
        }
        return new self(...$options);
    }

    /**
     * Builds a client from CMS_API_URL, CMS_PUBLIC_KEY, CMS_PREVIEW_KEY and CMS_DEFAULT_REGION.
     */
    public static function fromEnvironment(): self
    {
        $read = static function (string $name): ?string {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            return is_string($value) && $value !== '' ? $value : null;
        };
        return new self(
            apiKey: $read('CMS_PUBLIC_KEY') ?? '',
            baseUrl: $read('CMS_API_URL'),
            previewKey: $read('CMS_PREVIEW_KEY'),
            defaultRegion: $read('CMS_DEFAULT_REGION'),
        );
    }

    /**
     * One page by its address. Throws {@see NotFoundException} when nothing is published there.
     *
     * @param array{region?: string|null, locale?: string|null, preview?: bool, strict?: bool, include?: list<'references'>} $options
     * @return array<string, mixed> The page, with `meta`
     */
    public function getPage(string $path, array $options = []): array
    {
        self::checkOptions($options, self::PAGE_OPTIONS, 'getPage');
        $page = $this->request('/content', [
            'url' => $path,
            ...$this->scope($options),
            'strict' => $options['strict'] ?? null,
            'include' => self::include($options),
        ], $options);
        return self::withMeta($page, $options);
    }

    /**
     * Like {@see getPage()}, but answers null instead of throwing when nothing is published there.
     *
     * @param array{region?: string|null, locale?: string|null, preview?: bool, strict?: bool, include?: list<'references'>} $options
     * @return array<string, mixed>|null
     */
    public function findPage(string $path, array $options = []): ?array
    {
        try {
            return $this->getPage($path, $options);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Says what an address is: a page, a redirect, gone or not found. Call it before rendering.
     *
     * @param array{region?: string|null, locale?: string|null, preview?: bool, strict?: bool, include?: list<'references'>, referrer?: string|null} $options
     * @return array{kind: 'page', page: array<string, mixed>}|array{kind: 'redirect', to: string, status: int, permanent: bool, hops: int}|array{kind: 'gone'}|array{kind: 'notFound'}
     */
    public function resolve(string $path, array $options = []): array
    {
        self::checkOptions($options, [...self::PAGE_OPTIONS, 'referrer'], 'resolve');
        $referrer = $options['referrer'] ?? null;
        $result = $this->request('/resolve', [
            'url' => $path,
            ...$this->scope($options),
            'strict' => $options['strict'] ?? null,
            'include' => self::include($options),
        ], $options, [404, 410], $referrer !== null && $referrer !== '' ? ['x-cms-referrer' => $referrer] : []);

        $kind = $result['kind'] ?? null;
        if ($kind === 'page') {
            return ['kind' => 'page', 'page' => self::withMeta($result['page'], $options)];
        }
        if ($kind === 'redirect') {
            $status = (int) $result['statusCode'];
            return [
                'kind' => 'redirect',
                'to' => (string) $result['destination'],
                'status' => $status,
                'permanent' => (bool) ($result['permanent'] ?? ($status === 301 || $status === 308)),
                'hops' => (int) ($result['hops'] ?? 0),
            ];
        }
        return ['kind' => $kind === 'gone' ? 'gone' : 'notFound'];
    }

    /**
     * A menu by its key.
     *
     * @param array{region?: string|null, locale?: string|null, preview?: bool} $options
     * @return array{key: string, name: string, items: list<array<string, mixed>>}
     */
    public function getNavigation(string $key, array $options = []): array
    {
        self::checkOptions($options, ['region', 'locale', 'preview'], 'getNavigation');
        return $this->request('/navigation', ['key' => $key, ...$this->scope($options)], $options);
    }

    /**
     * @param array{region?: string|null, locale?: string|null, preview?: bool} $options
     * @return array{key: string, name: string, items: list<array<string, mixed>>}|null
     */
    public function findNavigation(string $key, array $options = []): ?array
    {
        try {
            return $this->getNavigation($key, $options);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Site-wide content, such as a footer, by its key.
     *
     * @param array{region?: string|null, locale?: string|null, preview?: bool, strict?: bool, include?: list<'references'>} $options
     * @return array{key: string, name: string, contentType: string, locale: string, fields: array<string, mixed>, components: list<array<string, mixed>>}
     */
    public function getGlobal(string $key, array $options = []): array
    {
        self::checkOptions($options, self::PAGE_OPTIONS, 'getGlobal');
        return $this->request('/global', [
            'key' => $key,
            ...$this->scope($options),
            'strict' => $options['strict'] ?? null,
            'include' => self::include($options),
        ], $options);
    }

    /**
     * @param array{region?: string|null, locale?: string|null, preview?: bool, strict?: bool, include?: list<'references'>} $options
     * @return array{key: string, name: string, contentType: string, locale: string, fields: array<string, mixed>, components: list<array<string, mixed>>}|null
     */
    public function findGlobal(string $key, array $options = []): ?array
    {
        try {
            return $this->getGlobal($key, $options);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Entries of one content type, filtered and paged.
     *
     * @param array{region?: string|null, locale?: string|null, preview?: bool, include?: list<'fields'|'references'>, page?: int, perPage?: int, filter?: array<string, string>, tag?: string, category?: string, sort?: 'name'|'-name'|'publishedAt'|'-publishedAt'|'updatedAt'|'-updatedAt'|'sortOrder', q?: string} $options
     * @return array{items: list<array{id: string, url: string|null, name: string, contentType: string, publishedAt: string|null, seo: array<string, mixed>|null, fields?: array<string, mixed>}>, total: int, page: int, hasMore: bool}
     */
    public function list(string $type, array $options = []): array
    {
        self::checkOptions($options, ['region', 'locale', 'preview', 'include', 'page', 'perPage', 'filter', 'tag', 'category', 'sort', 'q'], 'list');
        $filters = [];
        foreach ($options['filter'] ?? [] as $field => $value) {
            $filters['filter[' . $field . ']'] = $value;
        }
        return $this->request('/list', [
            'type' => $type,
            ...$this->scope($options),
            'page' => $options['page'] ?? null,
            'perPage' => $options['perPage'] ?? null,
            'tag' => $options['tag'] ?? null,
            'category' => $options['category'] ?? null,
            'sort' => $options['sort'] ?? null,
            'q' => $options['q'] ?? null,
            'include' => self::include($options),
            ...$filters,
        ], $options);
    }

    /**
     * The regions the key may read, with their languages.
     *
     * @return list<array{key: string, name: string, locales: list<array{code: string, name: string, isDefault: bool}>}>
     */
    public function getRegions(): array
    {
        return $this->request('/regions', [], []);
    }

    /**
     * The languages of one region, or of the default region, or of every region when neither is known.
     *
     * @return list<array{code: string, name: string, isDefault: bool}>
     */
    public function getLocales(?string $region = null): array
    {
        $regions = $this->getRegions();
        $wanted = $region ?? $this->defaultRegion;
        if ($wanted === null) {
            return array_merge([], ...array_map(static fn (array $entry): array => $entry['locales'], $regions));
        }
        foreach ($regions as $entry) {
            if ($entry['key'] === $wanted) {
                return $entry['locales'];
            }
        }
        return [];
    }

    /**
     * The index of every region's sitemap.
     *
     * @return array{indexable: bool, sitemaps: list<array{region: string, locale: string, base: string|null, pages: int, files: int}>}
     */
    public function getSitemapIndex(): array
    {
        return $this->request('/sitemap', [], []);
    }

    /**
     * One region's sitemap entries.
     *
     * @param array{region?: string|null, locale?: string|null, page?: int} $options
     * @return array{region: string, locale: string, page: int, totalPages: int, entries: list<array<string, mixed>>}
     */
    public function getSitemap(array $options = []): array
    {
        self::checkOptions($options, ['region', 'locale', 'page'], 'getSitemap');
        return $this->request('/sitemap', [
            'region' => $options['region'] ?? $this->defaultRegion,
            'locale' => $options['locale'] ?? null,
            'page' => $options['page'] ?? null,
        ], []);
    }

    /**
     * The robots.txt for the environment.
     *
     * @return array{indexable: bool, content: string}
     */
    public function getRobots(): array
    {
        return $this->request('/robots', [], []);
    }

    /**
     * Popups live now for a page.
     *
     * @param array{loggedIn?: bool, region?: string|null, locale?: string|null, preview?: bool} $options
     * @return array{serverTime: string, items: list<array<string, mixed>>}
     */
    public function getPopups(string $path, array $options = []): array
    {
        self::checkOptions($options, ['loggedIn', 'region', 'locale', 'preview'], 'getPopups');
        return $this->request('/popups', [
            'path' => $path,
            'loggedIn' => $options['loggedIn'] ?? null,
            ...$this->scope($options),
        ], $options);
    }

    /**
     * Banners live now for a page, or with `everyPage => true` for any page, each with the `paths` it targets.
     *
     * @param array{everyPage?: bool, loggedIn?: bool, region?: string|null, locale?: string|null, preview?: bool} $options
     * @return array{serverTime: string, items: list<array<string, mixed>>}
     */
    public function getBanners(?string $path = null, array $options = []): array
    {
        self::checkOptions($options, ['everyPage', 'loggedIn', 'region', 'locale', 'preview'], 'getBanners');
        $everyPage = (bool) ($options['everyPage'] ?? false);
        if ($everyPage === ($path !== null)) {
            throw new \InvalidArgumentException('Pass either a path or everyPage => true to getBanners');
        }
        return $this->request('/banners', [
            'path' => $path,
            'everyPage' => $everyPage,
            'loggedIn' => $options['loggedIn'] ?? null,
            ...$this->scope($options),
        ], $options);
    }

    /**
     * Shop products with live prices and stock.
     *
     * @param array{page?: int, perPage?: int, filter?: string, region?: string|null, locale?: string|null, preview?: bool} $options
     * @return array{items: list<array<string, mixed>>, total: int, page: int, hasMore: bool}
     */
    public function getProducts(array $options = []): array
    {
        self::checkOptions($options, ['page', 'perPage', 'filter', 'region', 'locale', 'preview'], 'getProducts');
        return $this->request('/products', [
            ...$this->scope($options),
            'page' => $options['page'] ?? null,
            'perPage' => $options['perPage'] ?? null,
            'filter' => $options['filter'] ?? null,
        ], $options);
    }

    /**
     * A taxonomy and its terms, in their set order.
     *
     * @param array{locale?: string|null} $options
     * @return array{key: string, name: string, terms: list<array{value: string, label: string, sortOrder: int}>}
     */
    public function getTaxonomy(string $key, array $options = []): array
    {
        self::checkOptions($options, ['locale'], 'getTaxonomy');
        return $this->request('/taxonomies/' . rawurlencode($key), [
            'locale' => $options['locale'] ?? $this->defaultLocale,
        ], []);
    }

    /**
     * The space's content types and components, with their fields.
     *
     * @return array{contentTypes: list<array<string, mixed>>, components: list<array<string, mixed>>}
     */
    public function getSchema(): array
    {
        return $this->request('/schema', [], []);
    }

    /**
     * Runs a GraphQL query and answers with its `data`. Errors in the answer are thrown, not returned beside partial data.
     *
     * @param array{variables?: array<string, mixed>, operationName?: string, preview?: bool} $options
     * @return array<string, mixed>|null
     */
    public function graphql(string $query, array $options = []): ?array
    {
        self::checkOptions($options, ['variables', 'operationName', 'preview'], 'graphql');
        $preview = (bool) ($options['preview'] ?? false);
        $payload = ['query' => $query];
        if (isset($options['variables'])) {
            $payload['variables'] = $options['variables'];
        }
        if (isset($options['operationName'])) {
            $payload['operationName'] = $options['operationName'];
        }

        try {
            $response = $this->transport->send(new HttpRequest(
                'POST',
                $this->origin . '/api/cdn/graphql',
                [...$this->headers($preview), 'Content-Type' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $this->timeout,
            ));
        } catch (TransportException $error) {
            throw new UnavailableException(
                $error->timedOut ? "The CMS did not answer within {$this->timeout}ms" : 'The CMS could not be reached',
                503,
                $error,
            );
        }

        $body = self::decode($response);
        $first = is_array($body['errors'] ?? null) ? ($body['errors'][0] ?? null) : null;
        $message = is_array($first) && is_string($first['message'] ?? null) ? $first['message'] : null;
        if ($response->status === 401) {
            throw new AuthException($message ?? 'The API key was refused');
        }
        if ($response->status === 429) {
            throw new RateLimitException($message ?? 'Too many requests', self::retryAfter($response));
        }
        if ($response->status >= 500) {
            throw new UnavailableException($message ?? "The CMS returned {$response->status}", $response->status);
        }
        if ($message !== null) {
            $code = $first['extensions']['code'] ?? 'graphql_error';
            throw new CmsException($response->status, is_string($code) ? $code : 'graphql_error', $message);
        }
        $data = $body['data'] ?? null;
        return is_array($data) ? $data : null;
    }

    /**
     * Checks a preview token from the editor's `?preview=` or `?token=` link. Answers with what the
     * token is for, including the page's `url`, or null when it is invalid or has expired.
     *
     * @return array<string, mixed>|null
     */
    public function verifyPreviewToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        try {
            $response = $this->transport->send(new HttpRequest(
                'GET',
                $this->origin . '/preview?token=' . rawurlencode($token),
                ['Accept' => 'application/json', 'User-Agent' => self::userAgent()],
                null,
                $this->timeout,
            ));
        } catch (TransportException $error) {
            throw new UnavailableException('The CMS could not be reached', 503, $error);
        }
        if ($response->status >= 500) {
            throw new UnavailableException("The CMS returned {$response->status}", $response->status);
        }
        if ($response->status < 200 || $response->status >= 300) {
            return null;
        }
        $data = self::decode($response)['data'] ?? null;
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, string|int|bool|null> $query
     * @param array<string, mixed> $options
     * @param list<int> $answers Statuses that carry a normal answer rather than an error
     * @param array<string, string> $headers
     * @return mixed The answer's `data`
     */
    private function request(string $path, array $query, array $options, array $answers = [], array $headers = []): mixed
    {
        $preview = (bool) ($options['preview'] ?? false);
        if ($preview && ($this->previewKey === null || $this->previewKey === '')) {
            throw new \LogicException('Previewing needs a previewKey on the client');
        }

        $query['draft'] = $preview ?: null;
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode($value === true ? 'true' : (string) $value);
        }
        $url = $this->api . $path . ($pairs === [] ? '' : '?' . implode('&', $pairs));
        $request = new HttpRequest('GET', $url, [...$this->headers($preview), ...$headers], null, $this->timeout);

        $lastError = null;
        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            if ($attempt > 0) {
                ($this->sleep)(self::RETRY_DELAYS_MS[$attempt - 1] ?? 2000);
            }
            try {
                $response = $this->transport->send($request);
            } catch (TransportException $error) {
                $lastError = new UnavailableException(
                    $error->timedOut ? "The CMS did not answer within {$this->timeout}ms" : 'The CMS could not be reached',
                    503,
                    $error,
                );
                continue;
            }

            $body = self::decode($response);
            if (($response->status >= 200 && $response->status < 300) || in_array($response->status, $answers, true)) {
                if (!is_array($body) || !array_key_exists('data', $body)) {
                    throw new CmsException($response->status, 'invalid_response', 'The CMS answered with something other than JSON');
                }
                return $body['data'];
            }

            $error = is_array($body['error'] ?? null) ? $body['error'] : [];
            $message = is_string($error['message'] ?? null) ? $error['message'] : (is_string($body['message'] ?? null) ? $body['message'] : "The CMS returned {$response->status}");
            if ($response->status >= 500) {
                $lastError = new UnavailableException($message, $response->status);
                continue;
            }
            if ($response->status === 401) {
                throw new AuthException($message);
            }
            if ($response->status === 404) {
                throw new NotFoundException($message);
            }
            if ($response->status === 429) {
                throw new RateLimitException($message, self::retryAfter($response));
            }
            $code = $error['code'] ?? (is_string($body['error'] ?? null) ? $body['error'] : 'error');
            throw new CmsException($response->status, is_string($code) ? $code : 'error', $message);
        }
        throw $lastError;
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $preview): array
    {
        return [
            'Authorization' => 'Bearer ' . ($preview ? $this->previewKey : $this->key),
            'Accept' => 'application/json',
            'User-Agent' => self::userAgent(),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{region: string|null, locale: string|null}
     */
    private function scope(array $options): array
    {
        return [
            'region' => $options['region'] ?? $this->defaultRegion,
            'locale' => $options['locale'] ?? $this->defaultLocale,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function include(array $options): ?string
    {
        $include = array_values(array_unique(array_map('strval', $options['include'] ?? [])));
        sort($include);
        return $include === [] ? null : implode(',', $include);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function withMeta(array $page, array $options): array
    {
        $page['meta'] = [
            'region' => $page['region'] ?? null,
            'locale' => $page['locale'] ?? null,
            'requestedRegion' => $page['requestedRegion'] ?? $page['region'] ?? null,
            'requestedLocale' => $page['requestedLocale'] ?? null,
            'fallbackApplied' => (bool) ($page['fallbackApplied'] ?? false),
            'regionFallbackApplied' => (bool) ($page['regionFallbackApplied'] ?? false),
            'draft' => (bool) ($page['draft'] ?? false),
            'references' => in_array('references', $options['include'] ?? [], true),
        ];
        return $page;
    }

    private static function decode(HttpResponse $response): mixed
    {
        if ($response->body === '') {
            return null;
        }
        try {
            return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function retryAfter(HttpResponse $response): int
    {
        $value = (int) ($response->header('retry-after') ?? 0);
        return $value > 0 ? $value : 60;
    }

    private static function apiBase(string $baseUrl): string
    {
        $trimmed = rtrim($baseUrl, '/');
        if (preg_match('#/api/cdn/v\d+$#', $trimmed) === 1) {
            return $trimmed;
        }
        if (str_ends_with($trimmed, '/api/cdn')) {
            return $trimmed . '/v1';
        }
        return $trimmed . '/api/cdn/v1';
    }

    private static function userAgent(): string
    {
        return 'bifrost-orbit-cms-php/' . self::VERSION;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $known
     */
    private static function checkOptions(array $options, array $known, string $method): void
    {
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown option%s for %s: %s', count($unknown) > 1 ? 's' : '', $method, implode(', ', $unknown)));
        }
    }
}
