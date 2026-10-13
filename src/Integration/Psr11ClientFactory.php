<?php

declare(strict_types=1);

namespace SpacedTracker\Integration;

use SpacedTracker\ClientBuilder;
use SpacedTracker\Config\EnvironmentConfig;
use SpacedTracker\SpaceTrackClientInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * One factory for any PSR-11 container.
 *
 * Looks for an optional `config` service whose `spaced-tracker` key matches
 * the published configuration, then optional PSR-18, PSR-17, PSR-3, and PSR-20
 * services. Environment variables fill anything the container does not.
 */
final class Psr11ClientFactory
{
    public function __invoke(ContainerInterface $container): SpaceTrackClientInterface
    {
        $config = EnvironmentConfig::read();
        if ($container->has('config')) {
            $all = $container->get('config');
            if (is_array($all) && isset($all['spaced-tracker']) && is_array($all['spaced-tracker'])) {
                /** @var array<string, mixed> $package */
                $package = $all['spaced-tracker'];
                $config = array_merge($config, $package);
            }
        }

        $builder = ClientBuilder::configure($config);
        $logger = $this->get($container, LoggerInterface::class);
        if ($logger instanceof LoggerInterface) {
            $builder = $builder->withLogger($logger);
        }
        $http = $this->get($container, ClientInterface::class);
        if ($http instanceof ClientInterface) {
            $builder = $builder->withHttpClient($http);
        }
        $requests = $this->get($container, RequestFactoryInterface::class);
        if ($requests instanceof RequestFactoryInterface) {
            $builder = $builder->withRequestFactory($requests);
        }
        $streams = $this->get($container, StreamFactoryInterface::class);
        if ($streams instanceof StreamFactoryInterface) {
            $builder = $builder->withStreamFactory($streams);
        }
        $responses = $this->get($container, ResponseFactoryInterface::class);
        if ($responses instanceof ResponseFactoryInterface) {
            $builder = $builder->withResponseFactory($responses);
        }
        $clock = $this->get($container, ClockInterface::class);
        if ($clock instanceof ClockInterface) {
            $builder = $builder->withClock($clock);
        }

        return $builder->build();
    }

    private function get(ContainerInterface $container, string $id): mixed
    {
        if (!$container->has($id)) {
            return null;
        }

        return $container->get($id);
    }
}
