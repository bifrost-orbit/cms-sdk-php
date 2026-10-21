<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Http;

/**
 * Sends requests with PHP streams, for servers without ext-curl.
 */
final class StreamTransport implements Transport
{
    public function send(HttpRequest $request): HttpResponse
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $request->method,
                'header' => implode("\r\n", $headers),
                'content' => $request->body ?? '',
                'timeout' => $request->timeoutMs / 1000,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        $started = microtime(true);
        $body = @file_get_contents($request->url, false, $context);
        $lines = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : (get_defined_vars()['http_response_header'] ?? []);
        if ($body === false || $lines === []) {
            $timedOut = (microtime(true) - $started) * 1000 >= $request->timeoutMs;
            throw new TransportException('The CMS could not be reached', $timedOut);
        }

        $status = 0;
        $received = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $received = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $received[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return new HttpResponse($status, $received, $body);
    }
}
