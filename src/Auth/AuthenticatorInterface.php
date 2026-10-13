<?php

declare(strict_types=1);

namespace SpacedTracker\Auth;

interface AuthenticatorInterface
{
    public function login(Credentials $credentials): Session;

    public function logout(Session $session): void;
}
