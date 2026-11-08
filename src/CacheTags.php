<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms;

/**
 * The same cache tags as the JavaScript SDK, for tag-aware caches such as Symfony's or Laravel's.
 * Symfony Cache refuses `:` and `/` in tags and keys, so pass them through {@see safe()} or {@see safeAll()} there.
 */
final class CacheTags
{
    public const ALL = 'cms';
    public const GLOBALS = 'cms:global';
    public const LISTS = 'cms:list';
    public const REGIONS = 'cms:regions';
    public const SITEMAP = 'cms:sitemap';
    public const POPUPS = 'cms:popups';
    public const BANNERS = 'cms:banners';
    public const REFERENCES = 'cms:references';

    public static function page(string $region, string $path): string
    {
        return "cms:page:{$region}:{$path}";
    }

    public static function navigation(string $key): string
    {
        return "cms:nav:{$key}";
    }

    public static function global(string $key): string
    {
        return "cms:global:{$key}";
    }

    /**
     * A tag, or any cache key, without the characters PSR-6 and Symfony Cache reserve (`{}()/\@:`),
     * such as `cms%3Apage%3Agermany%3A%2Fpricing` for `cms:page:germany:/pricing`.
     */
    public static function safe(string $tag): string
    {
        // [EXPLAIN-D526]
        return rawurlencode($tag);
    }

    /**
     * {@see safe()} for each tag, such as those from {@see Webhook::tagsForEvent()}.
     *
     * @param iterable<string> $tags
     * @return list<string>
     */
    public static function safeAll(iterable $tags): array
    {
        $safe = [];
        foreach ($tags as $tag) {
            $safe[] = self::safe($tag);
        }
        return $safe;
    }
}
