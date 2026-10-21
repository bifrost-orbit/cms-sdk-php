<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms;

/**
 * Addresses for resized images. Only images uploaded to CMS Orbit can be resized; other addresses are left as they are.
 */
final class Images
{
    private const SRCSET_WIDTHS = [640, 960, 1280, 1920];

    /**
     * @param 'webp'|'avif'|null $format
     */
    public static function url(string $url, ?int $width = null, ?string $format = null): string
    {
        if (!str_contains($url, '/uploads/')) {
            return $url;
        }
        [$path, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $params);
        if ($width !== null && $width > 0) {
            $params['w'] = (string) $width;
        }
        if ($format !== null) {
            $params['format'] = $format;
        }
        $search = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        return $search === '' ? $path : $path . '?' . $search;
    }

    /**
     * A srcset for an uploaded image, or null when it cannot be resized or is already small.
     */
    public static function srcSet(string $url, ?int $width): ?string
    {
        if (!str_contains($url, '/uploads/')) {
            return null;
        }
        $widths = array_values(array_filter(self::SRCSET_WIDTHS, static fn (int $entry): bool => $width === null || $entry < $width));
        if ($widths === []) {
            return null;
        }
        $entries = array_map(static fn (int $entry): string => self::url($url, $entry) . ' ' . $entry . 'w', $widths);
        if ($width !== null) {
            $entries[] = $url . ' ' . $width . 'w';
        }
        return implode(', ', $entries);
    }
}
