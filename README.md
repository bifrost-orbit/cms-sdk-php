# bifrost-orbit/cms-sdk

The official PHP SDK for CMS Orbit. It reads content from the delivery API: pages, redirects, menus, site-wide content, lists, banners, popups, products, taxonomies, sitemaps and robots rules, with drafts for the live preview. It also builds `<head>` tags, JSON-LD and sitemap XML, checks preview tokens and publish webhooks, and draws a page's components with your own templates.

It works with any PHP website, including Laravel, Symfony, WordPress and plain PHP. It is open source under the MIT licence.

Full documentation: [docs.bifrostorbit.com/cms/developers/sdk-php](https://docs.bifrostorbit.com/cms/developers/sdk-php).

## Install

```bash
composer require bifrost-orbit/cms-sdk
```

It needs PHP 8.1 or later and `ext-json`. Requests go through `ext-curl` when it is installed and PHP streams when it is not, so there is nothing else to install. To use your own HTTP client, pass any PSR-18 client.

## Quick start

Create the keys in **Configuration › API keys**: a public key (`cms_pk_…`) for published content and, for previews, a secret key (`cms_sk_…`) from a staging environment.

```bash
# .env
CMS_API_URL=https://api.bifrostorbit.com/cms
CMS_PUBLIC_KEY=cms_pk_...
CMS_PREVIEW_KEY=cms_sk_...      # staging only
CMS_DEFAULT_REGION=ireland
```

```php
use BifrostOrbit\Cms\Client;

$cms = new Client(
    apiKey: getenv('CMS_PUBLIC_KEY'),
    baseUrl: getenv('CMS_API_URL'),
    previewKey: getenv('CMS_PREVIEW_KEY') ?: null,
    defaultRegion: 'ireland',
);

// or, reading CMS_API_URL, CMS_PUBLIC_KEY, CMS_PREVIEW_KEY and CMS_DEFAULT_REGION:
$cms = Client::fromEnvironment();

$result = $cms->resolve('/services/consultations');

if ($result['kind'] === 'page') {
    echo $result['page']['name'];
}
```

| Option | What it does |
| --- | --- |
| `apiKey` | A public key (`cms_pk_…`) for published content. Required. A secret key also works, since PHP runs on the server. |
| `baseUrl` | The API address. `https://api.bifrostorbit.com/cms` by default; `https://api-stage.bifrostorbit.com/cms` for staging. |
| `previewKey` | A secret key (`cms_sk_…`) for drafts. Needed for `'preview' => true`. Public keys are refused here, since they cannot read drafts. |
| `defaultRegion` | Used when a call names no region. |
| `defaultLocale` | Used when a call names no language. Without it, the region's own language answers. |
| `timeout` | Per attempt, in milliseconds. 5000 by default. |
| `retries` | Extra attempts after a network error or a server error. 2 by default. |
| `httpClient` | A `Transport`, or any PSR-18 client with `requestFactory` and `streamFactory`. |

`Client::create([...])` takes the same options as an array, for framework configuration. An unknown option is refused, so a typo never goes unnoticed.

## Answers

Every method answers with plain associative arrays, in the same shapes as the [delivery API](https://docs.bifrostorbit.com/cms/developers/delivery-api) and the JavaScript SDK. The PHPDoc on each method describes the shape, so PHPStan, Psalm and your editor know the keys.

- Pages gain a `meta` array: `region`, `locale`, `requestedRegion`, `requestedLocale`, `fallbackApplied`, `regionFallbackApplied`, `draft` and `references`.
- `resolve()` answers `['kind' => 'page', 'page' => …]`, `['kind' => 'redirect', 'to' => …, 'status' => 301, 'permanent' => true, 'hops' => 1]`, `['kind' => 'gone']` or `['kind' => 'notFound']`.
- JSON objects with no keys, such as a component's empty `children`, arrive as empty arrays.

## Methods

Every method that reads content takes `region` and `locale`, and `preview` where drafts exist.

| Method | Answers with |
| --- | --- |
| `resolve($path, [region, locale, preview, strict, include, referrer])` | A page, a redirect, gone or not found. Call it before rendering. |
| `getPage($path, [region, locale, preview, strict, include])` | One page. Throws `NotFoundException` when nothing is published there. |
| `findPage($path, [...])` | The same, or `null` |
| `list($type, [region, locale, preview, include, page, perPage, filter, tag, category, sort, q])` | Entries of one content type, paged: `items`, `total`, `page`, `hasMore` |
| `getGlobal($key, [region, locale, preview, strict, include])`, `findGlobal(...)` | Site-wide content, such as a footer |
| `getNavigation($key, [region, locale, preview])`, `findNavigation(...)` | A menu |
| `getBanners($path, [loggedIn, region, locale, preview])` | Banners live now for a page. Pass `null` and `'everyPage' => true` for every page. |
| `getPopups($path, [loggedIn, region, locale, preview])` | Popups live now for a page |
| `getProducts([page, perPage, filter, region, locale, preview])` | Shop products with live prices |
| `getTaxonomy($key, [locale])` | A taxonomy and its terms |
| `getRegions()`, `getLocales(?$region)` | The regions and languages the key may read |
| `getSitemapIndex()`, `getSitemap([region, locale, page])`, `getRobots()` | Sitemaps and robots rules |
| `getSchema()` | Content types and components, with their fields |
| `graphql($query, [variables, operationName, preview])` | Any GraphQL query's `data` |
| `verifyPreviewToken($token)` | What a preview token is for, or `null` when it is invalid or expired |

```php
$header = $cms->getNavigation('header');
$footer = $cms->getGlobal('footer', ['region' => 'uk']);
$articles = $cms->list('article', ['sort' => '-publishedAt', 'perPage' => 12]);
$faqs = $cms->list('faqEntry', ['category' => 'pricing', 'include' => ['fields']]);
$treatments = $cms->list('treatment', ['filter' => ['duration' => '60']]);
$page = $cms->getPage('/pricing', ['include' => ['references']]);
$banners = $cms->getBanners('/services/consultations', ['loggedIn' => false]);
$data = $cms->graphql('query ($url: String!) { page(url: $url) { name } }', ['variables' => ['url' => '/']]);
```

`include => ['references']` replaces Reference and References fields with the entries they point at, one level deep. On `list`, `include => ['fields']` adds each entry's fields.

## A page, start to finish

```php
use BifrostOrbit\Cms\Seo;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$result = $cms->resolve($path, ['referrer' => $_SERVER['HTTP_REFERER'] ?? null]);

switch ($result['kind']) {
    case 'redirect':
        header('Location: ' . $result['to'], true, $result['status']);
        exit;
    case 'gone':
        http_response_code(410);
        exit;
    case 'notFound':
        http_response_code(404);
        exit;
}

$page = $result['page'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars($page['locale']) ?>">
<head>
    <?= Seo::headTags($page) ?>
    <?= Seo::jsonLd($page) ?>
</head>
<body>
    <?= $blocks->render($page['components']) ?>
</body>
</html>
```

## Rendering components

Each component in `$page['components']` has `id`, `type` (the component's key), `schemaVersion`, `fields`, `children` (lists by slot) and `variant`. Nested blocks in a slot are on the slot's field too: `$block['fields']['button']` is one node or `null`, and a Components slot is a list.

A plain `switch` is all it takes:

```php
function renderBlock(array $block): string
{
    $f = $block['fields'];
    $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES);

    switch ($block['type']) {
        case 'hero':
            $image = $f['image'] ?? null;
            return '<section data-cms-id="' . $e($block['id']) . '">'
                . '<h1>' . $e($f['heading'] ?? '') . '</h1>'
                . ($f['description'] ?? '')          // rich text arrives as safe HTML
                . ($image ? '<img src="' . $e($image['url']) . '" alt="' . $e($image['alt']) . '" width="' . $e($image['width']) . '" height="' . $e($image['height']) . '">' : '')
                . (isset($f['button']) ? renderBlock($f['button']) : '')
                . '</section>';
        case 'button':
            $link = $f['link'] ?? null;
            return '<a href="' . $e($link['href'] ?? '#') . '">' . $e($f['label'] ?? $link['label'] ?? '') . '</a>';
        case 'cardGrid':
            return '<div class="grid" data-cms-id="' . $e($block['id']) . '">'
                . implode('', array_map('renderBlock', $f['cards'] ?? []))
                . '</div>';
        default:
            return '';
    }
}

echo implode('', array_map('renderBlock', $page['components']));
```

Or register one renderer per component with `BlockRenderer`, which also draws slots:

```php
use BifrostOrbit\Cms\BlockRenderer;

$blocks = new BlockRenderer([
    'hero' => fn (array $block, BlockRenderer $blocks) => view('blocks.hero', ['block' => $block, 'button' => $blocks->renderOne($block['fields']['button'] ?? null)]),
    'cardGrid' => fn (array $block, BlockRenderer $blocks) => '<div class="grid">' . $blocks->slot($block, 'cards') . '</div>',
    'card' => fn (array $block) => view('blocks.card', ['block' => $block]),
]);

echo $blocks->render($page['components']);
```

A component with no renderer draws nothing. Pass a second argument, such as `fn ($block) => "<!-- no renderer for {$block['type']} -->"`, to see them while developing. Put `data-cms-id` with the block's `id` on each component's outer element, so editors can click it in the live preview.

`Images::url($url, width: 960, format: 'webp')` and `Images::srcSet($url, $width)` give resized addresses for uploaded images.

## Preview

The editor opens your website at the space's Preview URL with `?preview=<token>`. Check the token, remember that the visitor is previewing, and read with `'preview' => true` while they are:

```php
use BifrostOrbit\Cms\Preview;

// /preview?preview=…&path=/services/consultations
$token = $cms->verifyPreviewToken($_GET['preview'] ?? $_GET['token'] ?? '');
if ($token === null) {
    http_response_code(401);
    exit('That preview link is invalid or has expired');
}
setcookie('cms_preview', '1', ['httponly' => true, 'secure' => true, 'samesite' => 'None', 'path' => '/']);
header('Location: ' . Preview::redirectPath($_GET['path'] ?? null, $token));
```

```php
$preview = isset($_COOKIE['cms_preview']);
$result = $cms->resolve($path, ['preview' => $preview]);
// in the page, while previewing:
echo $preview ? Preview::bridgeScript($cms) : '';
```

Previews send the `previewKey` and `draft=true`. Drafts exist in staging environments only, and the secret key stays on your server. Never cache draft answers.

## Refresh on publish

```php
use BifrostOrbit\Cms\Webhook;

$message = Webhook::parse(getenv('CMS_WEBHOOK_SECRET'), getallheaders(), file_get_contents('php://input'));
if ($message === null) {
    http_response_code(401);
    exit;
}
$tags = Webhook::tagsForEvent($message['event'], $message['data']);  // such as ['cms:page:ireland:/pricing', 'cms:list', …]
// clear the cache entries tagged with $tags, then:
http_response_code(204);
```

`Webhook::verify()` checks the HMAC-SHA256 signature and refuses messages over 5 minutes old. `CacheTags` gives the same tags the JavaScript SDK uses, for tag-aware caches.

## Sitemaps and robots

```php
use BifrostOrbit\Cms\Seo;

header('Content-Type: application/xml; charset=utf-8');
echo Seo::sitemapXml($cms->getSitemap(['region' => 'ireland', 'page' => 1]));
echo Seo::sitemapIndexXml($cms->getSitemapIndex());

header('Content-Type: text/plain; charset=utf-8');
echo $cms->getRobots()['content'];
```

`Seo::toMetadata($page)` gives the same metadata array as the JavaScript SDK's `toMetadata`, and `Seo::hreflang($page)` the language alternates.

## Errors

Failed calls throw `BifrostOrbit\Cms\Exception\CmsException`, or one of its kinds. Each has the HTTP `status` and the API's `errorCode`.

| Exception | When |
| --- | --- |
| `NotFoundException` | Nothing is published there, or no menu, global entry, taxonomy, region or language has that key (404) |
| `AuthException` | No key, a wrong or expired key, a public key asking for drafts, or a region or language the key may not read (401) |
| `RateLimitException` | Over 15,000 requests a minute. `retryAfter` is the number of seconds to wait. Not retried. (429) |
| `UnavailableException` | The CMS could not be reached, did not answer in time, or failed on its side, after every retry |
| `CmsException` | Anything else, such as an unknown `sort` (400) |

Network errors and server errors are retried twice, after 250 ms and 750 ms. `resolve()` answers `notFound` and `gone` rather than throwing. Calling with `'preview' => true` on a client without a `previewKey` throws a `LogicException`.

```php
use BifrostOrbit\Cms\Exception\NotFoundException;
use BifrostOrbit\Cms\Exception\RateLimitException;

try {
    $footer = $cms->getGlobal('footer');
} catch (NotFoundException) {
    $footer = null;
} catch (RateLimitException $error) {
    header('Retry-After: ' . $error->retryAfter);
    http_response_code(503);
    exit;
}
```

## Laravel

```php
// config/services.php
'cms' => [
    'url' => env('CMS_API_URL', 'https://api.bifrostorbit.com/cms'),
    'key' => env('CMS_PUBLIC_KEY'),
    'preview_key' => env('CMS_PREVIEW_KEY'),
    'region' => env('CMS_DEFAULT_REGION'),
],
```

```php
// app/Providers/AppServiceProvider.php
use BifrostOrbit\Cms\Client;

public function register(): void
{
    $this->app->singleton(Client::class, fn () => new Client(
        apiKey: config('services.cms.key'),
        baseUrl: config('services.cms.url'),
        previewKey: config('services.cms.preview_key'),
        defaultRegion: config('services.cms.region'),
    ));
}
```

```php
// app/Http/Controllers/PageController.php
use BifrostOrbit\Cms\Client;
use Illuminate\Support\Facades\Cache;

public function show(Client $cms, ?string $path = null)
{
    $preview = session()->has('cms_preview');
    $load = fn () => $cms->resolve('/' . $path, ['preview' => $preview]);
    $result = $preview ? $load() : Cache::remember('cms:page:/' . $path, 60, $load);

    return match ($result['kind']) {
        'redirect' => redirect($result['to'], $result['status']),
        'gone' => abort(410),
        'notFound' => abort(404),
        'page' => view('page', ['page' => $result['page']]),
    };
}
```

```php
// routes/web.php
Route::get('/{path?}', [PageController::class, 'show'])->where('path', '.*');
```

## Symfony

```yaml
# config/services.yaml
services:
    BifrostOrbit\Cms\Client:
        arguments:
            $apiKey: '%env(CMS_PUBLIC_KEY)%'
            $baseUrl: '%env(CMS_API_URL)%'
            $previewKey: '%env(default::CMS_PREVIEW_KEY)%'
            $defaultRegion: '%env(default::CMS_DEFAULT_REGION)%'
```

To send requests through Symfony HttpClient, add `$httpClient: '@Psr\Http\Client\ClientInterface'` with `$requestFactory` and `$streamFactory` set to a PSR-17 factory, such as `nyholm/psr7`'s `Psr17Factory`.

```php
// src/Controller/PageController.php
use BifrostOrbit\Cms\Client;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    #[Route('/{path}', requirements: ['path' => '.*'], priority: -100)]
    public function show(Client $cms, string $path = ''): Response
    {
        $result = $cms->resolve('/' . $path);

        return match ($result['kind']) {
            'redirect' => new RedirectResponse($result['to'], $result['status']),
            'gone' => new Response('', 410),
            'notFound' => throw new NotFoundHttpException(),
            'page' => $this->render('page.html.twig', ['page' => $result['page']]),
        };
    }
}
```

## Tests

`BifrostOrbit\Cms\Testing\FakeTransport` answers from a queue and records each request, so your tests never call the CMS:

```php
use BifrostOrbit\Cms\Client;
use BifrostOrbit\Cms\Testing\FakeTransport;

$transport = (new FakeTransport())->json(['data' => ['key' => 'main', 'name' => 'Main menu', 'items' => []]]);
$cms = new Client(apiKey: 'cms_pk_test', httpClient: $transport);

$cms->getNavigation('main');
$transport->lastRequest()->url; // https://api.bifrostorbit.com/cms/api/cdn/v1/navigation?key=main
```

## Developing the SDK

```bash
composer install
composer test
```
