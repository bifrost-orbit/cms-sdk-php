<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Exception;

/**
 * The organisation's CMS Orbit trial or plan has ended, so delivery is paused (402). It is never retried.
 */
final class SubscriptionInactiveException extends CmsException
{
    public const MESSAGE = "Delivery is paused because the organisation's CMS Orbit trial or plan has ended. Nothing is deleted, and it comes back as soon as an owner chooses a plan.";

    public function __construct(string $message = self::MESSAGE, ?\Throwable $previous = null)
    {
        parent::__construct(402, 'subscription_inactive', $message, $previous);
    }
}
