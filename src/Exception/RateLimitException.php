<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Exception;

/**
 * Too many requests (429). Wait {@see $retryAfter} seconds before trying again.
 */
final class RateLimitException extends CmsException
{
    public function __construct(string $message, public readonly int $retryAfter, ?\Throwable $previous = null)
    {
        parent::__construct(429, 'rate_limited', $message, $previous);
    }
}
