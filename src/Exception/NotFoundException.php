<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Exception;

/**
 * Nothing is published there, or no menu, global entry, taxonomy, region or language has that key (404).
 */
final class NotFoundException extends CmsException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(404, 'not_found', $message, $previous);
    }
}
