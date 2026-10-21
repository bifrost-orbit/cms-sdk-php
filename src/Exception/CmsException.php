<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Exception;

/**
 * A failed call to CMS Orbit. Every other SDK exception extends it.
 */
class CmsException extends \RuntimeException
{
    /**
     * @param int $status The HTTP status, such as 400
     * @param string $errorCode The API's error code, such as "bad_request"
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
