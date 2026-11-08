<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms;

/**
 * The Insights Orbit script for a page, with the same output as the JavaScript SDK's `CmsInsights`.
 */
final class Insights
{
    public const SCRIPT = 'https://api.bifrostorbit.com/insights/v1/script.js';

    /**
     * The Insights Orbit site key of the page's space, or null when the space is not connected.
     *
     * @param array<string, mixed>|null $page
     */
    public static function site(?array $page): ?string
    {
        $site = $page['insights']['site'] ?? null;
        return is_string($site) && $site !== '' ? $site : null;
    }

    // [EXPLAIN-D271]
    /**
     * The tags to print in `<head>`: `<meta name="insights-content">` with the page's entry id and the
     * script with the site key. An empty string without a site key, so it can stay in a layout.
     *
     * @param array<string, mixed>|null $page A page from {@see Client::getPage()} or {@see Client::resolve()}
     * @param array{site?: string|null, contentId?: string|null, src?: string, api?: string|null, disable?: list<string>, campaigns?: bool, waitForConsent?: bool} $options
     */
    public static function tags(?array $page, array $options = []): string
    {
        $unknown = array_diff(array_keys($options), ['site', 'contentId', 'src', 'api', 'disable', 'campaigns', 'waitForConsent']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown option for Insights::tags: ' . implode(', ', $unknown));
        }
        $site = ($options['site'] ?? null) ?: self::site($page);
        if ($site === null) {
            return '';
        }
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $entry = $options['contentId'] ?? (isset($page['id']) && is_string($page['id']) && $page['id'] !== '' ? $page['id'] : null);
        $disable = $options['disable'] ?? [];

        $script = '<script defer data-site="' . $e($site) . '"';
        if (!empty($options['api'])) {
            $script .= ' data-api="' . $e($options['api']) . '"';
        }
        if ($disable !== []) {
            $script .= ' data-disable="' . $e(implode(' ', $disable)) . '"';
        }
        if ($options['campaigns'] ?? true) {
            $script .= ' data-campaigns';
        }
        if ($options['waitForConsent'] ?? false) {
            $script .= ' data-consent="wait"';
        }
        $script .= ' src="' . $e($options['src'] ?? self::SCRIPT) . '"></script>';

        return ($entry !== null && $entry !== '' ? '<meta name="insights-content" content="' . $e($entry) . '">' . "\n" : '') . $script;
    }
}
