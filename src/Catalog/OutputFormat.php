<?php

declare(strict_types=1);

namespace SpacedTracker\Catalog;

enum OutputFormat: string
{
    case Json = 'json';
    case Xml = 'xml';
    case Html = 'html';
    case Csv = 'csv';
    case Tle = 'tle';
    case ThreeLine = '3le';
    case Kvn = 'kvn';
    case Stream = 'stream';

    public function isStructured(): bool
    {
        return $this === self::Json || $this === self::Csv || $this === self::Tle || $this === self::ThreeLine;
    }
}
