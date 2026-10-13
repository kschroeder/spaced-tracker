<?php

declare(strict_types=1);

namespace SpacedTracker\Cache;

final class InvalidArgumentException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
