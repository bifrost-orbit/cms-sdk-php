<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Exception;

/**
 * The CMS could not be reached, did not answer in time, or failed on its side (5xx), after every retry.
 */
final class UnavailableException extends CmsException
{
    public function __construct(string $message, int $status = 503, ?\Throwable $previous = null)
    {
        parent::__construct($status, 'unavailable', $message, $previous);
    }
}
