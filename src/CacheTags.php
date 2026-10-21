<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms;

/**
 * The same cache tags as the JavaScript SDK, for tag-aware caches such as Symfony's or Laravel's.
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
}
