<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Integration;

use SpacedTracker\Integration\Mezzio\ConfigProvider;
use SpacedTracker\Integration\Psr11ClientFactory;
use SpacedTracker\SpaceTrackClientInterface;
use PHPUnit\Framework\TestCase;

final class MezzioConfigProviderTest extends TestCase
{
    public function testRegistersThePsr11FactoryForTheClient(): void
    {
        $config = (new ConfigProvider())();

        self::assertSame(
            [SpaceTrackClientInterface::class => Psr11ClientFactory::class],
            $config['dependencies']['factories'],
        );
    }
}
