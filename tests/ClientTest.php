<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Tests;

use BifrostOrbit\Cms\Client;
use BifrostOrbit\Cms\Exception\AuthException;
use BifrostOrbit\Cms\Exception\CmsException;
use BifrostOrbit\Cms\Exception\NotFoundException;
use BifrostOrbit\Cms\Exception\RateLimitException;
use BifrostOrbit\Cms\Exception\UnavailableException;
use BifrostOrbit\Cms\Testing\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private FakeTransport $transport;
    /** @var list<int> */
    private array $waits = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->waits = [];
    }

    private function client(array $options = []): Client
    {
        return new Client(...[
            'apiKey' => 'cms_pk_public',
            'baseUrl' => 'https://api.example.com/cms',
            'httpClient' => $this->transport,
            'sleep' => function (int $ms): void {
                $this->waits[] = $ms;
            },
            ...$options,
        ]);
    }

    private static function page(array $overrides = []): array
    {
        return [
            'url' => '/services/consultations',
            'name' => 'Consultations',
            'contentType' => 'page',
            'locale' => 'en-IE',
            'region' => 'ireland',
            'requestedLocale' => 'en-IE',
            'requestedRegion' => 'ireland',
            'fallbackApplied' => false,
            'regionFallbackApplied' => false,
            'draft' => false,
            'publishedAt' => '2026-10-01T09:30:00.000Z',
            'version' => 7,
            'fields' => ['title' => 'Book a consultation'],
            'components' => [],
            'seo' => ['title' => 'Consultations | Acme Clinic'],
            ...$overrides,
        ];
    }

    public function testFindsTheDeliveryApiUnderTheBaseUrl(): void
    {
        $this->assertSame('https://api.bifrostorbit.com/cms/api/cdn/v1', (new Client(apiKey: 'cms_pk_x', httpClient: $this->transport))->api);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1', $this->client(['baseUrl' => 'https://api.example.com/cms/'])->api);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1', $this->client(['baseUrl' => 'https://api.example.com/cms/api/cdn'])->api);
        $this->assertSame('https://api.example.com/cms/api/cdn/v2', $this->client(['baseUrl' => 'https://api.example.com/cms/api/cdn/v2'])->api);
        $this->assertSame('https://api.example.com/cms', $this->client()->origin);
    }

    public function testRefusesMissingAndWrongKeys(): void
    {
        foreach ([['apiKey' => ''], ['apiKey' => 'sk_live_123'], ['previewKey' => 'cms_pk_public'], ['timeout' => 0]] as $options) {
            try {
                $this->client($options);
                $this->fail('Expected the options to be refused: ' . json_encode($options));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAllowsASecretKeyOnTheServer(): void
    {
        $this->transport->json(['data' => []]);
        $this->client(['apiKey' => 'cms_sk_secret'])->getRegions();
        $this->assertSame('Bearer cms_sk_secret', $this->transport->lastRequest()->headers['Authorization']);
    }

    public function testRefusesUnknownOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option for getPage: regoin');
        $this->client()->getPage('/', ['regoin' => 'ireland']);
    }

    public function testGetPageBuildsTheRequestAndAddsMeta(): void
    {
        $this->transport->json(['data' => self::page()]);
        $page = $this->client(['defaultRegion' => 'ireland'])->getPage('/services/consultations', ['locale' => 'en-IE', 'strict' => true, 'include' => ['references', 'references']]);

        $request = $this->transport->lastRequest();
        $this->assertSame('GET', $request->method);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/content?url=%2Fservices%2Fconsultations&region=ireland&locale=en-IE&strict=true&include=references', $request->url);
        $this->assertSame('Bearer cms_pk_public', $request->headers['Authorization']);
        $this->assertSame('application/json', $request->headers['Accept']);
        $this->assertStringStartsWith('bifrost-orbit-cms-php/', $request->headers['User-Agent']);
        $this->assertSame(5000, $request->timeoutMs);

        $this->assertSame('Consultations', $page['name']);
        $this->assertSame([
            'region' => 'ireland',
            'locale' => 'en-IE',
            'requestedRegion' => 'ireland',
            'requestedLocale' => 'en-IE',
            'fallbackApplied' => false,
            'regionFallbackApplied' => false,
            'draft' => false,
            'references' => true,
        ], $page['meta']);
    }

    public function testLeavesOutEmptyParametersAndUsesTheDefaultLocale(): void
    {
        $this->transport->json(['data' => self::page()]);
        $this->client(['defaultLocale' => 'ga-IE'])->getPage('/', ['strict' => false]);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/content?url=%2F&locale=ga-IE', $this->transport->lastRequest()->url);
    }

    public function testFindPageAnswersNullWhenNothingIsPublished(): void
    {
        $this->transport->json(['error' => ['code' => 'not_found', 'message' => 'Content not found']], 404);
        $this->assertNull($this->client()->findPage('/missing'));
    }

    public function testResolveAnswersAPage(): void
    {
        $this->transport->json(['data' => ['kind' => 'page', 'page' => self::page()]]);
        $result = $this->client()->resolve('/services/consultations', ['region' => 'ie', 'referrer' => 'https://www.example.com/']);

        $request = $this->transport->lastRequest();
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/resolve?url=%2Fservices%2Fconsultations&region=ie', $request->url);
        $this->assertSame('https://www.example.com/', $request->headers['x-cms-referrer']);
        $this->assertSame('page', $result['kind']);
        $this->assertFalse($result['page']['meta']['references']);
    }

    public function testResolveNormalisesARedirect(): void
    {
        $this->transport->json(['data' => ['kind' => 'redirect', 'destination' => '/new', 'statusCode' => 308, 'hops' => 2]]);
        $this->assertSame(
            ['kind' => 'redirect', 'to' => '/new', 'status' => 308, 'permanent' => true, 'hops' => 2],
            $this->client()->resolve('/old'),
        );

        $this->transport->json(['data' => ['kind' => 'redirect', 'destination' => '/later', 'statusCode' => 302, 'permanent' => false, 'hops' => 1]]);
        $this->assertFalse($this->client()->resolve('/old')['permanent']);
    }

    public function testResolveTreatsNotFoundAndGoneAsAnswers(): void
    {
        $this->transport->json(['data' => ['kind' => 'notFound']], 404);
        $this->transport->json(['data' => ['kind' => 'gone']], 410);
        $this->assertSame(['kind' => 'notFound'], $this->client()->resolve('/missing'));
        $this->assertSame(['kind' => 'gone'], $this->client()->resolve('/removed'));
        $this->assertArrayNotHasKey('x-cms-referrer', $this->transport->lastRequest()->headers);
    }

    public function testListSendsFiltersSortAndInclude(): void
    {
        $this->transport->json(['data' => ['items' => [], 'total' => 0, 'page' => 2, 'hasMore' => false]]);
        $result = $this->client(['defaultRegion' => 'ireland'])->list('treatment', [
            'page' => 2,
            'perPage' => 10,
            'sort' => '-publishedAt',
            'tag' => 'laser',
            'category' => 'body',
            'q' => 'hair removal',
            'include' => ['references', 'fields'],
            'filter' => ['duration' => '60'],
        ]);

        $this->assertSame(
            'https://api.example.com/cms/api/cdn/v1/list?type=treatment&region=ireland&page=2&perPage=10&tag=laser&category=body&sort=-publishedAt&q=hair%20removal&include=fields%2Creferences&filter%5Bduration%5D=60',
            $this->transport->lastRequest()->url,
        );
        $this->assertSame(2, $result['page']);
    }

    public function testNavigationGlobalAndTaxonomy(): void
    {
        $this->transport
            ->json(['data' => ['key' => 'main', 'name' => 'Main menu', 'items' => []]])
            ->json(['data' => ['key' => 'footer', 'name' => 'Footer', 'contentType' => 'footer', 'locale' => 'en-IE', 'fields' => [], 'components' => []]])
            ->json(['data' => ['key' => 'skin concern', 'name' => 'Skin concern', 'terms' => []]])
            ->json(['error' => ['code' => 'not_found', 'message' => 'Navigation not found']], 404)
            ->json(['error' => ['code' => 'not_found', 'message' => 'Global not found']], 404);
        $cms = $this->client(['defaultRegion' => 'ireland']);

        $this->assertSame('Main menu', $cms->getNavigation('main')['name']);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/navigation?key=main&region=ireland', $this->transport->lastRequest()->url);

        $cms->getGlobal('footer', ['region' => 'uk', 'include' => ['references']]);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/global?key=footer&region=uk&include=references', $this->transport->lastRequest()->url);

        $cms->getTaxonomy('skin concern', ['locale' => 'en-IE']);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/taxonomies/skin%20concern?locale=en-IE', $this->transport->lastRequest()->url);

        $this->assertNull($cms->findNavigation('missing'));
        $this->assertNull($cms->findGlobal('missing'));
    }

    public function testRegionsAndLocales(): void
    {
        $regions = ['data' => [
            ['key' => 'ireland', 'name' => 'Ireland', 'locales' => [['code' => 'en-IE', 'name' => 'English', 'isDefault' => true], ['code' => 'ga-IE', 'name' => 'Irish', 'isDefault' => false]]],
            ['key' => 'uk', 'name' => 'United Kingdom', 'locales' => [['code' => 'en-GB', 'name' => 'English', 'isDefault' => true]]],
        ]];
        $this->transport->json($regions)->json($regions)->json($regions)->json($regions);
        $cms = $this->client();

        $this->assertCount(2, $cms->getRegions());
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/regions', $this->transport->lastRequest()->url);
        $this->assertSame(['en-IE', 'ga-IE', 'en-GB'], array_column($cms->getLocales(), 'code'));
        $this->assertSame(['en-GB'], array_column($cms->getLocales('uk'), 'code'));
        $this->assertSame([], $cms->getLocales('france'));
    }

    public function testSitemapsRobotsAndSchema(): void
    {
        $this->transport
            ->json(['data' => ['indexable' => true, 'sitemaps' => []]])
            ->json(['data' => ['region' => 'ireland', 'locale' => 'en-IE', 'page' => 2, 'totalPages' => 2, 'entries' => []]])
            ->json(['data' => ['indexable' => false, 'content' => "User-agent: *\nDisallow: /\n"]])
            ->json(['data' => ['contentTypes' => [], 'components' => []]]);
        $cms = $this->client(['defaultRegion' => 'ireland']);

        $cms->getSitemapIndex();
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/sitemap', $this->transport->lastRequest()->url);
        $cms->getSitemap(['page' => 2]);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/sitemap?region=ireland&page=2', $this->transport->lastRequest()->url);
        $this->assertFalse($cms->getRobots()['indexable']);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/robots', $this->transport->lastRequest()->url);
        $this->assertSame([], $cms->getSchema()['components']);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/schema', $this->transport->lastRequest()->url);
    }

    public function testCampaignsAndProducts(): void
    {
        $timed = ['data' => ['serverTime' => '2026-10-17T10:00:00.000Z', 'items' => []]];
        $this->transport->json($timed)->json($timed)->json($timed)->json(['data' => ['items' => [], 'total' => 0, 'page' => 1, 'hasMore' => false]]);
        $cms = $this->client(['defaultRegion' => 'ireland']);

        $cms->getBanners('/services', ['loggedIn' => true]);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/banners?path=%2Fservices&loggedIn=true&region=ireland', $this->transport->lastRequest()->url);
        $cms->getBanners(null, ['everyPage' => true]);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/banners?everyPage=true&region=ireland', $this->transport->lastRequest()->url);
        $cms->getPopups('/', ['loggedIn' => false, 'locale' => 'ga-IE']);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/popups?path=%2F&region=ireland&locale=ga-IE', $this->transport->lastRequest()->url);
        $cms->getProducts(['page' => 1, 'perPage' => 24, 'filter' => 'acne']);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/products?region=ireland&page=1&perPage=24&filter=acne', $this->transport->lastRequest()->url);
    }

    public function testBannersNeedEitherAPathOrEveryPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->getBanners();
    }

    public function testPreviewSendsThePreviewKeyAndAsksForDrafts(): void
    {
        $this->transport->json(['data' => self::page(['draft' => true])]);
        $page = $this->client(['previewKey' => 'cms_sk_secret'])->getPage('/new', ['preview' => true]);

        $request = $this->transport->lastRequest();
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/content?url=%2Fnew&draft=true', $request->url);
        $this->assertSame('Bearer cms_sk_secret', $request->headers['Authorization']);
        $this->assertTrue($page['meta']['draft']);
    }

    public function testPreviewNeedsAPreviewKey(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Previewing needs a previewKey on the client');
        $this->client()->getPage('/', ['preview' => true]);
    }

    public function testVerifiesPreviewTokens(): void
    {
        $this->transport
            ->json(['data' => ['url' => '/services/consultations', 'region' => 'ireland']])
            ->json(['error' => ['code' => 'unauthorized', 'message' => 'That preview link is invalid or has expired']], 401);
        $cms = $this->client();

        $this->assertSame('/services/consultations', $cms->verifyPreviewToken('eyJ+abc/=')['url']);
        $this->assertSame('https://api.example.com/cms/preview?token=eyJ%2Babc%2F%3D', $this->transport->requests[0]->url);
        $this->assertArrayNotHasKey('Authorization', $this->transport->requests[0]->headers);
        $this->assertNull($cms->verifyPreviewToken('expired'));
        $this->assertNull($cms->verifyPreviewToken(''));
        $this->assertCount(2, $this->transport->requests);
    }

    public function testMapsErrors(): void
    {
        $cases = [
            [401, ['error' => ['code' => 'unauthorized', 'message' => 'Bad key']], AuthException::class, 'unauthorized'],
            [404, ['error' => ['code' => 'not_found', 'message' => 'Region not found']], NotFoundException::class, 'not_found'],
            [400, ['error' => ['code' => 'bad_request', 'message' => 'Unknown sort']], CmsException::class, 'bad_request'],
        ];
        foreach ($cases as [$status, $body, $class, $code]) {
            $this->transport->json($body, $status);
            try {
                $this->client()->getRegions();
                $this->fail("Expected {$class}");
            } catch (CmsException $error) {
                $this->assertInstanceOf($class, $error);
                $this->assertSame($status, $error->status);
                $this->assertSame($code, $error->errorCode);
                $this->assertSame($body['error']['message'], $error->getMessage());
            }
        }
    }

    public function testMapsAnUnknownEndpoint(): void
    {
        $this->transport->json(['error' => 'not_found', 'message' => 'No such endpoint'], 404);
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No such endpoint');
        $this->client()->getRegions();
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        $this->transport->json(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests; try again shortly']], 429, ['Retry-After' => '23']);
        try {
            $this->client()->getRegions();
            $this->fail('Expected a rate limit');
        } catch (RateLimitException $error) {
            $this->assertSame(23, $error->retryAfter);
            $this->assertSame(429, $error->status);
            $this->assertSame([], $this->waits, 'A rate limit is not retried');
        }

        $this->transport->json(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests']], 429);
        try {
            $this->client()->getRegions();
        } catch (RateLimitException $error) {
            $this->assertSame(60, $error->retryAfter);
        }
    }

    public function testRetriesServerAndNetworkErrorsWithBackOff(): void
    {
        $this->transport
            ->json(['error' => ['code' => 'internal_error', 'message' => 'The request failed']], 500)
            ->fail()
            ->json(['data' => []]);
        $this->assertSame([], $this->client()->getRegions());
        $this->assertSame([250, 750], $this->waits);
        $this->assertCount(3, $this->transport->requests);
    }

    public function testGivesUpAfterTheRetries(): void
    {
        $this->transport->fail()->fail('timed out', true);
        try {
            $this->client(['retries' => 1, 'timeout' => 1500])->getRegions();
            $this->fail('Expected the CMS to be unavailable');
        } catch (UnavailableException $error) {
            $this->assertSame('The CMS did not answer within 1500ms', $error->getMessage());
            $this->assertSame(503, $error->status);
            $this->assertSame('unavailable', $error->errorCode);
        }
        $this->assertSame([250], $this->waits);

        $this->transport->json(['error' => ['code' => 'internal_error', 'message' => 'The request failed']], 502);
        try {
            $this->client(['retries' => 0])->getRegions();
        } catch (UnavailableException $error) {
            $this->assertSame(502, $error->status);
            $this->assertSame('The request failed', $error->getMessage());
        }
    }

    public function testGraphqlPostsTheQuery(): void
    {
        $this->transport->json(['data' => ['page' => ['name' => 'Consultations']]]);
        $data = $this->client(['previewKey' => 'cms_sk_secret'])->graphql(
            'query ($url: String!) { page(url: $url) { name } }',
            ['variables' => ['url' => '/services/consultations'], 'preview' => true],
        );

        $request = $this->transport->lastRequest();
        $this->assertSame('POST', $request->method);
        $this->assertSame('https://api.example.com/cms/api/cdn/graphql', $request->url);
        $this->assertSame('Bearer cms_sk_secret', $request->headers['Authorization']);
        $this->assertSame('application/json', $request->headers['Content-Type']);
        $this->assertSame(
            ['query' => 'query ($url: String!) { page(url: $url) { name } }', 'variables' => ['url' => '/services/consultations']],
            json_decode((string) $request->body, true),
        );
        $this->assertSame(['page' => ['name' => 'Consultations']], $data);
    }

    public function testGraphqlThrowsErrorsInTheAnswer(): void
    {
        $this->transport->json(['data' => null, 'errors' => [['message' => 'Cannot query field "nme"', 'extensions' => ['code' => 'GRAPHQL_VALIDATION_FAILED']]]], 400);
        try {
            $this->client()->graphql('{ page(url: "/") { nme } }');
            $this->fail('Expected a GraphQL error');
        } catch (CmsException $error) {
            $this->assertSame('GRAPHQL_VALIDATION_FAILED', $error->errorCode);
            $this->assertSame(400, $error->status);
        }

        $this->transport->json(['errors' => [['message' => 'Too many requests']]], 429, ['retry-after' => '5']);
        try {
            $this->client()->graphql('{ regions { key } }');
        } catch (RateLimitException $error) {
            $this->assertSame(5, $error->retryAfter);
        }
    }

    public function testCreateAndFromEnvironment(): void
    {
        $cms = Client::create(['apiKey' => 'cms_pk_public', 'baseUrl' => 'https://api-stage.example.com/cms', 'defaultRegion' => 'ireland']);
        $this->assertSame('https://api-stage.example.com/cms/api/cdn/v1', $cms->api);
        $this->assertSame('ireland', $cms->defaultRegion);

        $_ENV['CMS_PUBLIC_KEY'] = 'cms_pk_env';
        $_ENV['CMS_API_URL'] = 'https://api.example.com/cms';
        $_ENV['CMS_DEFAULT_REGION'] = 'uk';
        try {
            $cms = Client::fromEnvironment();
            $this->assertSame('https://api.example.com/cms/api/cdn/v1', $cms->api);
            $this->assertSame('uk', $cms->defaultRegion);
        } finally {
            unset($_ENV['CMS_PUBLIC_KEY'], $_ENV['CMS_API_URL'], $_ENV['CMS_DEFAULT_REGION']);
        }

        $this->expectException(\InvalidArgumentException::class);
        Client::create(['apiKey' => 'cms_pk_public', 'cache' => true]);
    }
}
