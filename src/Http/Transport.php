<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Http;

/**
 * Sends one HTTP request. Throw a {@see TransportException} when no answer arrives.
 */
interface Transport
{
    public function send(HttpRequest $request): HttpResponse;
}
