<?php

declare(strict_types=1);

namespace SpacedTracker\Auth;

interface SessionStoreInterface
{
    public function get(): ?Session;

    public function put(Session $session): void;

    public function clear(): void;
}
