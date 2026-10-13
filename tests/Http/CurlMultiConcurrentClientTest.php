<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Http;

use SpacedTracker\Exception\TransportException;
use SpacedTracker\Http\CurlMultiConcurrentClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class CurlMultiConcurrentClientTest extends TestCase
{
    private string $router;

    private string $log;

    /** @var resource|null */
    private $process;

    private int $port = 0;

    protected function setUp(): void
    {
        $this->router = sys_get_temp_dir() . '/spaced-curl-' . bin2hex(random_bytes(4)) . '.php';
        $this->log = $this->router . '.log';
        file_put_contents($this->router, <<<'PHP'
<?php
header('X-Trace: catalog');
header('X-Empty: ');
http_response_code(201);
echo ($_SERVER['REQUEST_METHOD'] ?? 'NONE') . ':' . file_get_contents('php://input');
PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->router],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->log, 'wb'],
                2 => ['file', $this->log, 'wb'],
            ],
            $pipes,
        );
        self::assertIsResource($this->process);
        fclose($pipes[0]);
        $this->waitUntilListening();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if (is_file($this->router)) {
            unlink($this->router);
        }
        if (is_file($this->log)) {
            unlink($this->log);
        }
    }

    public function testSendsSeveralRequestsToALocalServer(): void
    {
        $factory = new Psr17Factory();
        $client = new CurlMultiConcurrentClient($factory, $factory, 2.0, false);
        $base = 'http://127.0.0.1:' . $this->port;

        $responses = $client->send([
            'read' => $factory->createRequest('GET', $base . '/gp')->withHeader('X-Trace', '1'),
            'write' => $factory->createRequest('POST', $base . '/upload')->withBody($factory->createStream('payload')),
        ]);

        self::assertSame(201, $responses['read']->getStatusCode());
        self::assertSame('catalog', $responses['read']->getHeaderLine('X-Trace'));
        self::assertSame('GET:', (string) $responses['read']->getBody());
        self::assertSame('POST:payload', (string) $responses['write']->getBody());
        self::assertSame([], $client->send([]));
    }

    public function testAClosedPortReportsTheCurlError(): void
    {
        $factory = new Psr17Factory();
        $client = new CurlMultiConcurrentClient($factory, $factory, 2.0, true);

        try {
            $client->send([
                'down' => $factory->createRequest('GET', 'http://127.0.0.1:1/'),
            ]);
            self::fail('A closed port must fail the batch.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('failed:', $exception->getMessage());
        }
    }

    public function testAnEmptyMethodOrUrlFailsBeforeTheTransfer(): void
    {
        $factory = new Psr17Factory();
        $client = new CurlMultiConcurrentClient($factory, $factory, 2.0, true);

        try {
            $client->send([
                'bad' => $factory->createRequest('GET', 'http://127.0.0.1/')->withMethod(''),
            ]);
            self::fail('An empty method must fail the batch.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('method and a URL', $exception->getMessage());
        }

        try {
            $client->send([
                'bad' => $factory->createRequest('GET', 'http://127.0.0.1/')->withUri($factory->createUri('')),
            ]);
            self::fail('An empty URL must fail the batch.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('method and a URL', $exception->getMessage());
        }
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }

        $log = is_file($this->log) ? (string) file_get_contents($this->log) : '';
        self::fail('The local curl server did not start. ' . $log);
    }
}
