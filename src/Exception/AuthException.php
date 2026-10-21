<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Exception;

/**
 * The key is missing, wrong, expired or may not read what was asked for (401).
 */
final class AuthException extends CmsException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(401, 'unauthorized', $message, $previous);
    }
}
