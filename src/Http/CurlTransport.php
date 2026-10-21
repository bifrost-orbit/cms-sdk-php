<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Http;

final class CurlTransport implements Transport
{
    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new TransportException('The CMS could not be reached');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $received = [];

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $request->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $request->timeoutMs,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        $body = curl_exec($handle);
        if ($body === false) {
            $timedOut = curl_errno($handle) === CURLE_OPERATION_TIMEDOUT;
            $message = curl_error($handle);
            throw new TransportException($message !== '' ? $message : 'The CMS could not be reached', $timedOut);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, $received, (string) $body);
    }
}
