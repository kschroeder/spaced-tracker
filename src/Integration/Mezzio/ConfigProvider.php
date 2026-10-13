<?php

declare(strict_types=1);

namespace SpacedTracker\Integration\Mezzio;

use SpacedTracker\Integration\Psr11ClientFactory;
use SpacedTracker\SpaceTrackClientInterface;

/**
 * Mezzio and Laminas ConfigProvider.
 *
 * Register it from `config/config.php`. Put credentials under the
 * `spaced-tracker` key or in the environment.
 */
final class ConfigProvider
{
    /**
     * @return array{dependencies: array{factories: array<class-string, class-string>}}
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'factories' => [
                    SpaceTrackClientInterface::class => Psr11ClientFactory::class,
                ],
            ],
        ];
    }
}
