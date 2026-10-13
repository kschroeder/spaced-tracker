<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
