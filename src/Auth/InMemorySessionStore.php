<?php

declare(strict_types=1);

namespace SpacedTracker\Auth;

final class InMemorySessionStore implements SessionStoreInterface
{
    private ?Session $session = null;

    public function get(): ?Session
    {
        return $this->session;
    }

    public function put(Session $session): void
    {
        $this->session = $session;
    }

    public function clear(): void
    {
        $this->session = null;
    }
}
