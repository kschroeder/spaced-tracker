<?php

declare(strict_types=1);

namespace SpacedTracker;

use SpacedTracker\Query\Query;
use SpacedTracker\Query\QueryResult;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface SpaceTrackClientInterface
{
    public function login(): void;

    public function logout(): void;

    public function hasSession(): bool;

    public function sessionExpiresAt(): ?\DateTimeImmutable;

    public function execute(Query $query): QueryResult;

    /**
     * @param array<int|string, Query> $queries
     * @return array<int|string, QueryResult>
     */
    public function executeMany(array $queries): array;

    /**
     * @return array<mixed>
     */
    public function modelDefinition(string $class, ?string $controller = null): array;

    public function uploadFile(
        string $folderId,
        string $filename,
        string $contents,
        string $contentType = 'application/octet-stream',
    ): QueryResult;

    /**
     * Send any Space-Track URL with the session cookie attached.
     *
     * Absolute URLs must use the configured Space-Track host. The cookie is
     * added inside the client and is not returned.
     */
    public function sendAuthenticated(RequestInterface $request): ResponseInterface;
}
