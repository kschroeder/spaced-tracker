<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Log;

use SpacedTracker\Auth\Credentials;
use SpacedTracker\Log\RedactingLogger;
use SpacedTracker\Log\Redactor;
use SpacedTracker\Testing\ArrayLogger;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function testRedactsSecretsInMessagesAndContext(): void
    {
        $logger = new ArrayLogger();
        $safe = new RedactingLogger($logger);
        $safe->info('login identity=ada@example.com&password=correct-horse-battery', [
            'cookie' => 'chocolatechip=session-token',
            'authorization' => 'Bearer super-secret-token',
            'path' => '/ajaxauth/login',
            'nested' => ['api_key' => 'abcd', 'class' => 'gp'],
        ]);

        $encoded = (string) json_encode($logger->records);
        self::assertStringNotContainsString('correct-horse-battery', $encoded);
        self::assertStringNotContainsString('session-token', $encoded);
        self::assertStringNotContainsString('super-secret-token', $encoded);
        self::assertStringNotContainsString('abcd', $encoded);
        self::assertStringContainsString('gp', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
    }

    public function testCredentialsDoNotLeakThroughDebugOrStringCasts(): void
    {
        $credentials = new Credentials('ada@example.com', 'correct-horse-battery');
        $printed = print_r($credentials, true);

        self::assertStringNotContainsString('correct-horse-battery', $printed);
        self::assertStringNotContainsString('correct-horse-battery', (string) $credentials);
        self::assertSame('a***@e***', $credentials->redactedIdentity());

        $parameter = (new \ReflectionMethod(Credentials::class, '__construct'))->getParameters()[1];
        self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class));
    }

    public function testCredentialsCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new Credentials('ada@example.com', 'correct-horse-battery'));
    }

    public function testRedactorLeavesOrdinaryPredicatesAlone(): void
    {
        $redactor = new Redactor();

        self::assertSame('25544', $redactor->redactString('25544'));
        self::assertSame(25544, $redactor->redact(25544));
        self::assertNull($redactor->redact(null));
        self::assertSame(
            ['user-password' => '[redacted]', 'note' => '[redacted]'],
            $redactor->redact(['user-password' => 'secret', 'note' => new \stdClass()]),
        );
    }

    public function testIdentityWithoutAnAtSignIsStillRedacted(): void
    {
        $credentials = new Credentials('ada', 'correct-horse-battery');

        self::assertSame('a***', $credentials->redactedIdentity());
        self::assertSame('ada', $credentials->identity());
        self::assertSame('correct-horse-battery', $credentials->password());
        self::assertSame('Credentials(a***)', (string) $credentials);
    }

    public function testEmptyCredentialsAreRejectedAndCannotBeUnserialized(): void
    {
        try {
            new Credentials('', 'correct-horse-battery');
            self::fail('An empty identity must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            new Credentials('ada@example.com', '');
            self::fail('An empty password must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $credentials = new Credentials('ada@example.com', 'correct-horse-battery');
        $this->expectException(\LogicException::class);
        $credentials->__unserialize([]);
    }
}
