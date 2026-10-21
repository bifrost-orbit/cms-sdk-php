<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms;

/**
 * Helpers for the live preview route.
 */
final class Preview
{
    public const BRIDGE_PATH = '/bridge.js';

    /**
     * The address to send the editor to after checking a token: `$path` when it is a path on this
     * website, otherwise the page the token is for, otherwise `/`. Never another website.
     *
     * @param array<string, mixed>|null $token What {@see Client::verifyPreviewToken()} answered
     */
    public static function redirectPath(?string $path, ?array $token = null): string
    {
        $url = $token['url'] ?? null;
        return self::safePath($path) ?? self::safePath(is_string($url) ? $url : null) ?? '/';
    }

    public static function safePath(?string $path): ?string
    {
        if ($path === null || $path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }
        return $path;
    }

    /**
     * The script tag for the preview bridge, so editors can click blocks. Add it only while previewing.
     */
    public static function bridgeScript(Client $client): string
    {
        return '<script src="' . htmlspecialchars($client->origin . self::BRIDGE_PATH, ENT_QUOTES) . '" defer></script>';
    }
}
