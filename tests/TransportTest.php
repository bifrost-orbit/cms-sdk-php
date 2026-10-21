<?php

declare(strict_types=1);

namespace BifrostOrbit\Cms\Tests;

use BifrostOrbit\Cms\Client;
use BifrostOrbit\Cms\Exception\NotFoundException;
use BifrostOrbit\Cms\Exception\UnavailableException;
use BifrostOrbit\Cms\Http\CurlTransport;
use BifrostOrbit\Cms\Http\HttpRequest;
use BifrostOrbit\Cms\Http\Psr18Transport;
use BifrostOrbit\Cms\Http\StreamTransport;
use BifrostOrbit\Cms\Http\Transport;
use BifrostOrbit\Cms\Http\TransportException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $address = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        self::$address = "http://127.0.0.1:{$port}";
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    /** @return iterable<string, array{Transport}> */
    public static function transports(): iterable
    {
        yield 'curl' => [new CurlTransport()];
        yield 'streams' => [new StreamTransport()];
    }

    #[DataProvider('transports')]
    public function testSendsRealRequests(Transport $transport): void
    {
        $cms = new Client(apiKey: 'cms_pk_public', baseUrl: self::$address . '/cms', httpClient: $transport, sleep: static function (): void {
        });
        $echo = $cms->list('treatment', ['filter' => ['duration' => '60'], 'q' => 'a b']);
        $this->assertSame('/cms/api/cdn/v1/list?type=treatment&q=a%20b&filter%5Bduration%5D=60', $echo['uri']);
        $this->assertSame('Bearer cms_pk_public', $echo['authorization']);

        $graphql = $cms->graphql('{ regions { key } }');
        $this->assertSame('POST', $graphql['method']);
        $this->assertSame('{"query":"{ regions { key } }"}', $graphql['body']);

        $this->expectException(NotFoundException::class);
        $cms->getPage('/missing');
    }

    #[DataProvider('transports')]
    public function testReadsHeadersAndTimesOut(Transport $transport): void
    {
        $response = $transport->send(new HttpRequest('GET', self::$address . '/cms/api/cdn/v1/limited'));
        $this->assertSame(429, $response->status);
        $this->assertSame('23', $response->header('Retry-After'));

        try {
            $transport->send(new HttpRequest('GET', self::$address . '/slow', [], null, 300));
            $this->fail('Expected a timeout');
        } catch (TransportException $error) {
            $this->assertTrue($error->timedOut);
        }
    }

    public function testUnreachableServerIsUnavailable(): void
    {
        $cms = new Client(apiKey: 'cms_pk_public', baseUrl: 'http://127.0.0.1:1/cms', retries: 0, httpClient: new CurlTransport());
        $this->expectException(UnavailableException::class);
        $cms->getRegions();
    }

    public function testUsesAnyPsr18Client(): void
    {
        $client = new class () implements ClientInterface {
            public ?RequestInterface $request = null;
            public bool $failNext = false;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                if ($this->failNext) {
                    $this->failNext = false;
                    throw new class ('down', $request) extends \RuntimeException implements NetworkExceptionInterface {
                        public function __construct(string $message, private readonly RequestInterface $request)
                        {
                            parent::__construct($message);
                        }

                        public function getRequest(): RequestInterface
                        {
                            return $this->request;
                        }
                    };
                }
                return new Response(200, ['Content-Type' => 'application/json'], '{"data":{"key":"main","name":"Main menu","items":[]}}');
            }
        };
        $factory = new Psr17Factory();
        $cms = new Client(apiKey: 'cms_pk_public', baseUrl: 'https://api.example.com/cms', retries: 1, httpClient: $client, requestFactory: $factory, streamFactory: $factory, sleep: static function (): void {
        });

        $this->assertSame('Main menu', $cms->getNavigation('main')['name']);
        $this->assertSame('https://api.example.com/cms/api/cdn/v1/navigation?key=main', (string) $client->request->getUri());
        $this->assertSame('Bearer cms_pk_public', $client->request->getHeaderLine('Authorization'));

        $client->failNext = true;
        $this->assertSame('main', $cms->getNavigation('main')['key']);

        $this->expectException(\InvalidArgumentException::class);
        new Client(apiKey: 'cms_pk_public', httpClient: $client);
    }

    public function testPsr18TransportSendsBodies(): void
    {
        $factory = new Psr17Factory();
        $client = new class () implements ClientInterface {
            public string $body = '';

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->body = (string) $request->getBody();
                return new Response(201, ['X-Thing' => ['a', 'b']], 'ok');
            }
        };
        $response = (new Psr18Transport($client, $factory, $factory))->send(new HttpRequest('POST', 'https://api.example.com/', [], '{"a":1}'));
        $this->assertSame('{"a":1}', $client->body);
        $this->assertSame(201, $response->status);
        $this->assertSame('a, b', $response->header('x-thing'));
    }
}
