<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Tests;

use BifrostOrbit\Cms\BlockRenderer;
use BifrostOrbit\Cms\CacheTags;
use BifrostOrbit\Cms\Client;
use BifrostOrbit\Cms\Images;
use BifrostOrbit\Cms\Preview;
use BifrostOrbit\Cms\Seo;
use BifrostOrbit\Cms\Testing\FakeTransport;
use BifrostOrbit\Cms\Webhook;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    private static function page(): array
    {
        return [
            'name' => 'Consultations',
            'seo' => [
                'title' => 'Consultations | Acme Clinic',
                'description' => 'Book a free consultation.',
                'canonical' => 'https://www.example.com/ie/services/consultations',
                'robots' => ['index' => true, 'follow' => false],
                'openGraph' => [
                    'type' => 'website',
                    'title' => 'Consultations',
                    'description' => 'Book a free consultation.',
                    'url' => 'https://www.example.com/ie/services/consultations',
                    'siteName' => 'Acme Clinic',
                    'locale' => 'en_IE',
                    'image' => ['url' => 'https://cdn.example.com/uploads/hero.jpg', 'width' => 1200, 'height' => 630, 'alt' => ''],
                ],
                'twitter' => ['card' => 'summary_large_image', 'site' => null, 'title' => 'Consultations', 'description' => '', 'image' => null],
                'verification' => ['google' => null, 'bing' => 'abc123'],
                'alternates' => [
                    ['hreflang' => 'en-IE', 'href' => 'https://www.example.com/ie/services/consultations'],
                    ['hreflang' => 'ga-IE', 'href' => 'https://www.example.com/ie/ga/services/consultations'],
                ],
                'breadcrumbs' => [],
                'jsonLd' => [['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => '</script><script>alert(1)</script>']],
            ],
        ];
    }

    public function testToMetadataMatchesTheJavaScriptShape(): void
    {
        $this->assertSame([
            'title' => 'Consultations | Acme Clinic',
            'description' => 'Book a free consultation.',
            'alternates' => [
                'canonical' => 'https://www.example.com/ie/services/consultations',
                'languages' => [
                    'en-IE' => 'https://www.example.com/ie/services/consultations',
                    'ga-IE' => 'https://www.example.com/ie/ga/services/consultations',
                ],
            ],
            'robots' => ['index' => true, 'follow' => false],
            'openGraph' => [
                'type' => 'website',
                'title' => 'Consultations',
                'description' => 'Book a free consultation.',
                'url' => 'https://www.example.com/ie/services/consultations',
                'siteName' => 'Acme Clinic',
                'locale' => 'en_IE',
                'images' => [['url' => 'https://cdn.example.com/uploads/hero.jpg', 'width' => 1200, 'height' => 630]],
            ],
            'twitter' => ['card' => 'summary_large_image', 'title' => 'Consultations'],
            'verification' => ['other' => ['msvalidate.01' => 'abc123']],
        ], Seo::toMetadata(self::page()));
    }

    public function testHeadTagsAreEscaped(): void
    {
        $page = self::page();
        $page['seo']['title'] = 'Fees & "offers"';
        $html = Seo::headTags($page);
        $this->assertStringContainsString('<title>Fees &amp; &quot;offers&quot;</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://www.example.com/ie/services/consultations">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="ga-IE" href="https://www.example.com/ie/ga/services/consultations">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, nofollow">', $html);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        $this->assertStringContainsString('<meta name="msvalidate.01" content="abc123">', $html);
    }

    public function testJsonLdCannotCloseTheScriptTag(): void
    {
        $html = Seo::jsonLd(self::page());
        $this->assertStringStartsWith('<script type="application/ld+json">{"@context":"https://schema.org"', $html);
        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertStringContainsString('</script>', $html);
        $this->assertSame('', Seo::jsonLd(['seo' => ['jsonLd' => []]]));
    }

    public function testSitemapXml(): void
    {
        $xml = Seo::sitemapXml(['entries' => [[
            'loc' => 'https://www.example.com/ie/a?b=1&c=2',
            'lastmod' => '2026-10-01T09:30:00.000Z',
            'changefreq' => 'monthly',
            'priority' => 0.8,
            'images' => [['loc' => 'https://cdn.example.com/uploads/a.jpg', 'title' => 'A']],
            'alternates' => [['hreflang' => 'en-IE', 'href' => 'https://www.example.com/ie/a']],
        ]]]);
        $this->assertStringContainsString('<loc>https://www.example.com/ie/a?b=1&amp;c=2</loc>', $xml);
        $this->assertStringContainsString('<priority>0.8</priority>', $xml);
        $this->assertStringContainsString('<image:image><image:loc>https://cdn.example.com/uploads/a.jpg</image:loc><image:title>A</image:title></image:image>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));

        $index = Seo::sitemapIndexXml(['sitemaps' => [
            ['region' => 'ireland', 'locale' => 'en-IE', 'base' => 'https://www.example.com/ie', 'pages' => 60000, 'files' => 2],
            ['region' => 'uk', 'locale' => 'en-GB', 'base' => null, 'pages' => 1, 'files' => 1],
        ]]);
        $this->assertStringContainsString('<loc>https://www.example.com/ie/sitemap.xml</loc>', $index);
        $this->assertStringContainsString('<loc>https://www.example.com/ie/sitemap-2.xml</loc>', $index);
        $this->assertSame(2, substr_count($index, '<sitemap>'));
    }

    public function testImages(): void
    {
        $url = 'https://api.example.com/cms/uploads/hero.jpg';
        $this->assertSame($url . '?w=640&format=webp', Images::url($url, 640, 'webp'));
        $this->assertSame('https://elsewhere.example.com/a.jpg', Images::url('https://elsewhere.example.com/a.jpg', 640));
        $this->assertSame("{$url}?w=640 640w, {$url}?w=960 960w, {$url} 1000w", Images::srcSet($url, 1000));
        $this->assertNull(Images::srcSet($url, 500));
        $this->assertNull(Images::srcSet('https://elsewhere.example.com/a.jpg', 2000));
    }

    public function testWebhookSignatures(): void
    {
        $body = '{"event":"content.published","data":{"url":"/a","region":"ireland"}}';
        $timestamp = '1760000000000';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'whsec');

        $this->assertTrue(Webhook::verify('whsec', $timestamp, $body, $signature, 1760000000000 + 1000));
        $this->assertFalse(Webhook::verify('whsec', $timestamp, $body, $signature, 1760000000000 + 301000));
        $this->assertFalse(Webhook::verify('other', $timestamp, $body, $signature, 1760000000000));
        $this->assertFalse(Webhook::verify('whsec', $timestamp, $body . ' ', $signature, 1760000000000));
        $this->assertFalse(Webhook::verify('whsec', '', $body, $signature));

        $message = Webhook::parse('whsec', ['X-Orbit-Timestamp' => [$timestamp], 'X-Orbit-Signature' => $signature], $body, 1760000000000);
        $this->assertSame(['event' => 'content.published', 'data' => ['url' => '/a', 'region' => 'ireland']], $message);
        $this->assertSame([CacheTags::page('ireland', '/a'), 'cms:list', 'cms:sitemap', 'cms:references'], Webhook::tagsForEvent($message['event'], $message['data']));
        $this->assertNull(Webhook::parse('whsec', [], $body));
    }

    public function testTagsForEvent(): void
    {
        $this->assertSame([], Webhook::tagsForEvent('webhook.test', []));
        $this->assertSame(['cms'], Webhook::tagsForEvent('content.published', ['url' => '/a', 'contentType' => 'sharedSection']));
        $this->assertSame(['cms:global', 'cms:list', 'cms:popups', 'cms:banners', 'cms:references'], Webhook::tagsForEvent('content.published', ['url' => null]));
        $this->assertSame(['cms'], Webhook::tagsForEvent('content.published', ['url' => '/b', 'previousUrl' => '/a']));
        $this->assertSame(['cms:nav:main'], Webhook::tagsForEvent('navigation.changed', ['key' => 'main']));
        $this->assertSame(['cms:list', 'cms:sitemap', 'cms:references'], Webhook::tagsForEvent('release.published', []));
        $this->assertSame(['cms'], Webhook::tagsForEvent('schema.changed', []));
    }

    public function testPreviewHelpers(): void
    {
        $this->assertSame('/services', Preview::redirectPath('/services', ['url' => '/other']));
        $this->assertSame('/other', Preview::redirectPath('//evil.example.com', ['url' => '/other']));
        $this->assertSame('/', Preview::redirectPath('https://evil.example.com', null));
        $this->assertNull(Preview::safePath('/\\evil.example.com'));

        $cms = new Client(apiKey: 'cms_pk_public', baseUrl: 'https://api.example.com/cms', httpClient: new FakeTransport());
        $this->assertSame('<script src="https://api.example.com/cms/bridge.js" defer></script>', Preview::bridgeScript($cms));
    }

    public function testBlockRenderer(): void
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
        $renderer = new BlockRenderer([
            'hero' => static fn (array $block, BlockRenderer $blocks): string => '<section data-cms-id="' . $e($block['id']) . '"><h1>' . $e($block['fields']['heading']) . '</h1>' . $blocks->renderOne($block['fields']['button'] ?? null) . '</section>',
            'button' => static fn (array $block): string => '<a>' . $e($block['fields']['label']) . '</a>',
            'cardGrid' => static fn (array $block, BlockRenderer $blocks): string => '<div>' . $blocks->slot($block, 'cards') . '</div>',
            'card' => static fn (array $block): string => '<p>' . $e($block['fields']['title']) . '</p>',
        ]);
        $button = ['id' => 'b1', 'type' => 'button', 'schemaVersion' => 1, 'fields' => ['label' => 'Book'], 'children' => [], 'variant' => null];
        $components = [
            ['id' => 'c1', 'type' => 'hero', 'schemaVersion' => 1, 'fields' => ['heading' => 'Free <consultations>', 'button' => $button], 'children' => ['button' => [$button]], 'variant' => null],
            ['id' => 'c2', 'type' => 'cardGrid', 'schemaVersion' => 1, 'fields' => [], 'children' => ['cards' => [
                ['id' => 'k1', 'type' => 'card', 'schemaVersion' => 1, 'fields' => ['title' => 'One'], 'children' => [], 'variant' => null],
                ['id' => 'k2', 'type' => 'card', 'schemaVersion' => 1, 'fields' => ['title' => 'Two'], 'children' => [], 'variant' => null],
            ]], 'variant' => null],
            ['id' => 'c3', 'type' => 'unknownThing', 'schemaVersion' => 1, 'fields' => [], 'children' => [], 'variant' => null],
        ];

        $this->assertSame(
            '<section data-cms-id="c1"><h1>Free &lt;consultations&gt;</h1><a>Book</a></section><div><p>One</p><p>Two</p></div>',
            $renderer->render($components),
        );
        $this->assertTrue($renderer->has('hero'));

        $noisy = new BlockRenderer([], static fn (array $block): string => '<!-- no renderer for ' . $block['type'] . ' -->');
        $this->assertSame('<!-- no renderer for unknownThing -->', $noisy->renderOne($components[2]));
    }
}
