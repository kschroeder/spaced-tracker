<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Testing;

use SpacedTracker\Testing\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class MockHttpClientTest extends TestCase
{
    public function testAnEmptyQueueFailsInsteadOfInventingAResponse(): void
    {
        $client = new MockHttpClient([]);
        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/gp');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mock HTTP client has no queued responses.');
        $client->sendRequest($request);
    }
}
