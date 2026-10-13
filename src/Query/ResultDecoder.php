<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Exception\DecodeException;

final class ResultDecoder
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function decode(Query $query, int $statusCode, string $body, array $headers, float $durationSeconds): QueryResult
    {
        $metadata = null;
        $records = [];

        if (trim($body) !== '') {
            try {
                [$records, $metadata] = $this->decodeBody($query->outputFormat(), $body);
            } catch (DecodeException $exception) {
                throw new DecodeException(
                    sprintf('Could not decode %s response for %s/%s.', $query->outputFormat()->value, $query->controller(), $query->className()),
                    0,
                    $exception,
                );
            }
        }

        return new QueryResult($query, $statusCode, $body, $records, $metadata, $durationSeconds, $headers);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null}
     */
    private function decodeBody(OutputFormat $format, string $body): array
    {
        return match ($format) {
            OutputFormat::Json => $this->decodeJson($body),
            OutputFormat::Csv => [$this->decodeCsv($body), null],
            OutputFormat::Tle, OutputFormat::ThreeLine => [$this->decodeTle($body), null],
            default => [[], null],
        };
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null}
     */
    private function decodeJson(string $body): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DecodeException('Response was not valid JSON.', 0, $exception);
        }

        if ($decoded === null) {
            return [[], null];
        }

        if (!is_array($decoded)) {
            throw new DecodeException('JSON response was not an object or a list.');
        }

        if ($this->isList($decoded)) {
            return [$this->normalizeList($decoded), null];
        }

        $metadata = null;
        if (isset($decoded['request_metadata']) && is_array($decoded['request_metadata'])) {
            /** @var array<string, mixed> $metadata */
            $metadata = $decoded['request_metadata'];
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            if ($this->isList($decoded['data'])) {
                return [$this->normalizeList($decoded['data']), $metadata];
            }

            return [[$this->normalizeRow($decoded['data'])], $metadata];
        }

        return [[$this->normalizeRow($decoded)], $metadata];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeCsv(string $body): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($body)) ?: [];
        if ($lines === [] || $lines === ['']) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines), ',', '"', '\\');
        $records = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '\\');
            $row = [];
            foreach ($headers as $index => $header) {
                $name = trim((string) $header);
                if ($name === '') {
                    continue;
                }
                $row[$name] = $values[$index] ?? null;
            }
            $records[] = $row;
        }

        return $records;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeTle(string $body): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($body)) ?: [];
        $records = [];
        $pendingName = null;

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '1 ')) {
                $pendingName = $pendingName ?? null;
                $records[] = [
                    'TLE_LINE0' => $pendingName,
                    'TLE_LINE1' => $line,
                    'TLE_LINE2' => null,
                ];
                $pendingName = null;
                continue;
            }
            if (str_starts_with($line, '2 ') && $records !== []) {
                $last = count($records) - 1;
                $records[$last]['TLE_LINE2'] = $line;
                continue;
            }
            $pendingName = $line;
        }

        return $records;
    }

    /**
     * @param array<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeList(array $rows): array
    {
        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new DecodeException('JSON list contained a non-object record.');
            }
            $records[] = $this->normalizeRow($row);
        }

        return $records;
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_is_list($value);
    }
}
