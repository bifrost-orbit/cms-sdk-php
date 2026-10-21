<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms;

/**
 * Checks publish webhooks from CMS Orbit and says which cached content they change.
 */
final class Webhook
{
    public const MAX_AGE_MS = 300000;

    /**
     * True when the `x-orbit-signature` header is the HMAC-SHA256 of `timestamp.body` with the webhook
     * secret, and the `x-orbit-timestamp` header (milliseconds) is no more than 5 minutes away from now.
     *
     * @param int|null $nowMs The current time in milliseconds. For tests.
     */
    public static function verify(string $secret, string $timestamp, string $body, string $signature, ?int $nowMs = null): bool
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('The webhook secret is required');
        }
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        if (!hash_equals($expected, $signature)) {
            return false;
        }
        $now = $nowMs ?? (int) floor(microtime(true) * 1000);
        return abs($now - (int) $timestamp) <= self::MAX_AGE_MS;
    }

    /**
     * Verifies a delivery and answers with its `event` and `data`, or null when it is not genuine or is too old.
     *
     * @param array<string, string|list<string>> $headers The request headers, names in any case
     * @return array{event: string, data: array<string, mixed>}|null
     */
    public static function parse(string $secret, array $headers, string $body, ?int $nowMs = null): ?array
    {
        $lower = array_change_key_case($headers, CASE_LOWER);
        $read = static function (string $name) use ($lower): string {
            $value = $lower[$name] ?? '';
            return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        };
        if (!self::verify($secret, $read('x-orbit-timestamp'), $body, $read('x-orbit-signature'), $nowMs)) {
            return null;
        }
        $message = json_decode($body, true);
        if (!is_array($message)) {
            return null;
        }
        return [
            'event' => is_string($message['event'] ?? null) ? $message['event'] : '',
            'data' => is_array($message['data'] ?? null) ? $message['data'] : [],
        ];
    }

    /**
     * The {@see CacheTags} an event changes, the same as the JavaScript SDK's webhook handler refreshes.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function tagsForEvent(string $event, array $data): array
    {
        if ($event === 'webhook.test') {
            return [];
        }
        if (str_starts_with($event, 'content.')) {
            $url = is_string($data['url'] ?? null) ? $data['url'] : null;
            $region = is_string($data['region'] ?? null) ? $data['region'] : null;
            if (($data['contentType'] ?? null) === 'sharedSection') {
                return [CacheTags::ALL];
            }
            if ($url === null) {
                return [CacheTags::GLOBALS, CacheTags::LISTS, CacheTags::POPUPS, CacheTags::BANNERS, CacheTags::REFERENCES];
            }
            if (is_string($data['previousUrl'] ?? null) && $data['previousUrl'] !== $url) {
                return [CacheTags::ALL];
            }
            return [
                $region !== null ? CacheTags::page($region, $url) : CacheTags::ALL,
                CacheTags::LISTS,
                CacheTags::SITEMAP,
                CacheTags::REFERENCES,
            ];
        }
        if ($event === 'navigation.changed' && is_string($data['key'] ?? null)) {
            return [CacheTags::navigation($data['key'])];
        }
        if ($event === 'release.published') {
            return [CacheTags::LISTS, CacheTags::SITEMAP, CacheTags::REFERENCES];
        }
        return [CacheTags::ALL];
    }
}
