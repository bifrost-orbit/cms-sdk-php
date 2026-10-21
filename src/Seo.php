<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms;

/**
 * Metadata, JSON-LD and sitemap XML from what the delivery API answers.
 */
final class Seo
{
    /**
     * The page's metadata in the same shape as the JavaScript SDK's `toMetadata`: title, description,
     * self-canonical, robots, Open Graph, X card, hreflang alternates and verification. Empty values are left out.
     *
     * @param array<string, mixed> $page A page from {@see Client::getPage()} or {@see Client::resolve()}
     * @return array{title: string, description?: string, alternates: array{canonical: string, languages?: array<string, string>}, robots: array{index: bool, follow: bool}, openGraph: array<string, mixed>, twitter: array<string, mixed>, verification?: array{google?: string, other?: array<string, string>}}
     */
    public static function toMetadata(array $page): array
    {
        $seo = $page['seo'] ?? [];
        $og = $seo['openGraph'] ?? [];
        $twitter = $seo['twitter'] ?? [];
        $image = $og['image'] ?? null;
        $twitterImage = $twitter['image'] ?? null;

        $metadata = ['title' => (string) ($seo['title'] ?? $page['name'] ?? '')];
        if (!empty($seo['description'])) {
            $metadata['description'] = (string) $seo['description'];
        }
        $metadata['alternates'] = ['canonical' => (string) ($seo['canonical'] ?? '')];
        if (!empty($seo['alternates'])) {
            $metadata['alternates']['languages'] = self::hreflang($page);
        }
        $metadata['robots'] = [
            'index' => (bool) ($seo['robots']['index'] ?? true),
            'follow' => (bool) ($seo['robots']['follow'] ?? true),
        ];

        $openGraph = [
            'type' => (string) ($og['type'] ?? 'website'),
            'title' => (string) ($og['title'] ?? $metadata['title']),
        ];
        if (!empty($og['description'])) {
            $openGraph['description'] = (string) $og['description'];
        }
        $openGraph['url'] = (string) ($og['url'] ?? $metadata['alternates']['canonical']);
        $openGraph['siteName'] = (string) ($og['siteName'] ?? '');
        $openGraph['locale'] = (string) ($og['locale'] ?? '');
        if (is_array($image)) {
            $openGraph['images'] = [array_filter([
                'url' => $image['url'] ?? null,
                'width' => $image['width'] ?? null,
                'height' => $image['height'] ?? null,
                'alt' => ($image['alt'] ?? '') !== '' ? $image['alt'] : null,
            ], static fn ($value): bool => $value !== null)];
        }
        $metadata['openGraph'] = $openGraph;

        $card = ['card' => (string) ($twitter['card'] ?? 'summary_large_image')];
        if (!empty($twitter['site'])) {
            $card['site'] = (string) $twitter['site'];
        }
        $card['title'] = (string) ($twitter['title'] ?? $metadata['title']);
        if (!empty($twitter['description'])) {
            $card['description'] = (string) $twitter['description'];
        }
        if (is_array($twitterImage) && isset($twitterImage['url'])) {
            $card['images'] = [(string) $twitterImage['url']];
        }
        $metadata['twitter'] = $card;

        $google = $seo['verification']['google'] ?? null;
        $bing = $seo['verification']['bing'] ?? null;
        if (!empty($google) || !empty($bing)) {
            $metadata['verification'] = [];
            if (!empty($google)) {
                $metadata['verification']['google'] = (string) $google;
            }
            if (!empty($bing)) {
                $metadata['verification']['other'] = ['msvalidate.01' => (string) $bing];
            }
        }

        return $metadata;
    }

    /**
     * The page's language alternates, keyed by hreflang.
     *
     * @param array<string, mixed> $page
     * @return array<string, string>
     */
    public static function hreflang(array $page): array
    {
        $languages = [];
        foreach ($page['seo']['alternates'] ?? [] as $entry) {
            $languages[(string) $entry['hreflang']] = (string) $entry['href'];
        }
        return $languages;
    }

    /**
     * The `<head>` tags for a page: title, description, canonical, robots, hreflang alternates,
     * Open Graph, X card and verification, escaped and ready to print.
     *
     * @param array<string, mixed> $page
     */
    public static function headTags(array $page): string
    {
        $metadata = self::toMetadata($page);
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $meta = static fn (string $attribute, string $name, mixed $content): string => '<meta ' . $attribute . '="' . $e($name) . '" content="' . $e($content) . '">';

        $tags = ['<title>' . $e($metadata['title']) . '</title>'];
        if (isset($metadata['description'])) {
            $tags[] = $meta('name', 'description', $metadata['description']);
        }
        if ($metadata['alternates']['canonical'] !== '') {
            $tags[] = '<link rel="canonical" href="' . $e($metadata['alternates']['canonical']) . '">';
        }
        foreach ($metadata['alternates']['languages'] ?? [] as $hreflang => $href) {
            $tags[] = '<link rel="alternate" hreflang="' . $e($hreflang) . '" href="' . $e($href) . '">';
        }
        $tags[] = $meta('name', 'robots', ($metadata['robots']['index'] ? 'index' : 'noindex') . ', ' . ($metadata['robots']['follow'] ? 'follow' : 'nofollow'));

        $og = $metadata['openGraph'];
        foreach (['type' => 'og:type', 'title' => 'og:title', 'description' => 'og:description', 'url' => 'og:url', 'siteName' => 'og:site_name', 'locale' => 'og:locale'] as $key => $property) {
            if (isset($og[$key]) && $og[$key] !== '') {
                $tags[] = $meta('property', $property, $og[$key]);
            }
        }
        foreach ($og['images'] ?? [] as $image) {
            $tags[] = $meta('property', 'og:image', $image['url']);
            foreach (['width', 'height', 'alt'] as $key) {
                if (isset($image[$key])) {
                    $tags[] = $meta('property', 'og:image:' . $key, $image[$key]);
                }
            }
        }

        $twitter = $metadata['twitter'];
        foreach (['card', 'site', 'title', 'description'] as $key) {
            if (isset($twitter[$key])) {
                $tags[] = $meta('name', 'twitter:' . $key, $twitter[$key]);
            }
        }
        foreach ($twitter['images'] ?? [] as $url) {
            $tags[] = $meta('name', 'twitter:image', $url);
        }

        if (isset($metadata['verification']['google'])) {
            $tags[] = $meta('name', 'google-site-verification', $metadata['verification']['google']);
        }
        foreach ($metadata['verification']['other'] ?? [] as $name => $content) {
            $tags[] = $meta('name', $name, $content);
        }

        return implode("\n", $tags);
    }

    /**
     * The page's JSON-LD as a script tag, or an empty string when it has none. `<` is escaped so the JSON cannot close the tag.
     *
     * @param array<string, mixed> $page
     */
    public static function jsonLd(array $page): string
    {
        $items = $page['seo']['jsonLd'] ?? [];
        if (!is_array($items) || $items === []) {
            return '';
        }
        $json = json_encode(
            count($items) === 1 ? $items[0] : $items,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG,
        );
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * A sitemap file from {@see Client::getSitemap()}.
     *
     * @param array{entries: list<array<string, mixed>>} $sitemap
     */
    public static function sitemapXml(array $sitemap): string
    {
        $urls = [];
        foreach ($sitemap['entries'] as $entry) {
            $parts = ['<loc>' . self::xml($entry['loc']) . '</loc>'];
            if (!empty($entry['lastmod'])) {
                $parts[] = '<lastmod>' . self::xml($entry['lastmod']) . '</lastmod>';
            }
            if (!empty($entry['changefreq'])) {
                $parts[] = '<changefreq>' . self::xml($entry['changefreq']) . '</changefreq>';
            }
            if (isset($entry['priority'])) {
                $parts[] = '<priority>' . number_format((float) $entry['priority'], 1, '.', '') . '</priority>';
            }
            foreach ($entry['alternates'] ?? [] as $alternate) {
                $parts[] = '<xhtml:link rel="alternate" hreflang="' . self::xml($alternate['hreflang']) . '" href="' . self::xml($alternate['href']) . '"/>';
            }
            foreach ($entry['images'] ?? [] as $image) {
                $parts[] = '<image:image><image:loc>' . self::xml($image['loc']) . '</image:loc>'
                    . (!empty($image['title']) ? '<image:title>' . self::xml($image['title']) . '</image:title>' : '')
                    . '</image:image>';
            }
            $urls[] = '<url>' . implode('', $parts) . '</url>';
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" '
            . "xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n"
            . implode("\n", $urls)
            . "\n</urlset>\n";
    }

    /**
     * A sitemap index from {@see Client::getSitemapIndex()}, listing each region's sitemap files.
     *
     * @param array{sitemaps: list<array{base: string|null, files: int}>} $index
     */
    public static function sitemapIndexXml(array $index): string
    {
        $files = [];
        foreach ($index['sitemaps'] as $entry) {
            if (empty($entry['base'])) {
                continue;
            }
            for ($n = 0; $n < (int) $entry['files']; $n++) {
                $files[] = '<sitemap><loc>' . self::xml($entry['base'] . '/' . ($n === 0 ? 'sitemap.xml' : 'sitemap-' . ($n + 1) . '.xml')) . '</loc></sitemap>';
            }
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . implode("\n", $files)
            . "\n</sitemapindex>\n";
    }

    private static function xml(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
