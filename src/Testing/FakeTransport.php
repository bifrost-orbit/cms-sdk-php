<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Testing;

use BifrostOrbit\Cms\Http\HttpRequest;
use BifrostOrbit\Cms\Http\HttpResponse;
use BifrostOrbit\Cms\Http\Transport;
use BifrostOrbit\Cms\Http\TransportException;

/**
 * A transport for tests: answers from a queue, or from a callback, and records every request.
 *
 *     $transport = new FakeTransport();
 *     $transport->json(['data' => ['key' => 'main', 'name' => 'Main menu', 'items' => []]]);
 *     $cms = new Client(apiKey: 'cms_pk_test', httpClient: $transport);
 */
final class FakeTransport implements Transport
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse|TransportException> */
    private array $queue = [];

    /**
     * @param (callable(HttpRequest): HttpResponse)|null $handler Answers when the queue is empty
     */
    public function __construct(private readonly mixed $handler = null)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function json(mixed $body, int $status = 200, array $headers = []): self
    {
        $this->queue[] = new HttpResponse(
            $status,
            ['content-type' => 'application/json', ...$headers],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        return $this;
    }

    public function respond(HttpResponse $response): self
    {
        $this->queue[] = $response;
        return $this;
    }

    public function fail(string $message = 'Connection refused', bool $timedOut = false): self
    {
        $this->queue[] = new TransportException($message, $timedOut);
        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next instanceof TransportException) {
            throw $next;
        }
        if ($next instanceof HttpResponse) {
            return $next;
        }
        if ($this->handler !== null) {
            return ($this->handler)($request);
        }
        throw new \LogicException('FakeTransport has no answer queued for ' . $request->method . ' ' . $request->url);
    }

    public function lastRequest(): ?HttpRequest
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }
}
