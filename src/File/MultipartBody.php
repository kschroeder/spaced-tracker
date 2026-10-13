<?php

declare(strict_types=1);

namespace SpacedTracker\File;

final class MultipartBody
{
    /**
     * @return array{body: string, contentType: string}
     */
    public static function singleFile(string $field, string $filename, string $contents, string $contentType): array
    {
        if ($field === '' || preg_match('/^[A-Za-z0-9_-]+$/', $field) !== 1) {
            throw new \InvalidArgumentException('Invalid multipart field name.');
        }
        if (preg_match('/[\r\n]/', $contentType) === 1 || $contentType === '') {
            throw new \InvalidArgumentException('Invalid content type.');
        }

        $safeName = str_replace(['"', "\r", "\n", '\\'], '', $filename);
        $boundary = 'spacedtracker' . bin2hex(random_bytes(12));
        $eol = "\r\n";
        $body = '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $safeName . '"' . $eol
            . 'Content-Type: ' . $contentType . $eol
            . $eol
            . $contents . $eol
            . '--' . $boundary . '--' . $eol;

        return [
            'body' => $body,
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
        ];
    }
}
