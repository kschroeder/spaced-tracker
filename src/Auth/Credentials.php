<?php

declare(strict_types=1);

namespace SpacedTracker\Auth;

final class Credentials
{
    public function __construct(
        private readonly string $identity,
        #[\SensitiveParameter]
        private readonly string $password,
    ) {
        if ($this->identity === '' || $this->password === '') {
            throw new \InvalidArgumentException('Identity and password cannot be empty.');
        }
    }

    public function identity(): string
    {
        return $this->identity;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function redactedIdentity(): string
    {
        $at = strpos($this->identity, '@');
        if ($at === false) {
            return substr($this->identity, 0, 1) . '***';
        }

        $name = substr($this->identity, 0, $at);
        $domain = substr($this->identity, $at + 1);

        return substr($name, 0, 1) . '***@' . substr($domain, 0, 1) . '***';
    }

    /**
     * @return array{identity: string, password: string}
     */
    public function __debugInfo(): array
    {
        return [
            'identity' => $this->redactedIdentity(),
            'password' => '[redacted]',
        ];
    }

    public function __toString(): string
    {
        return 'Credentials(' . $this->redactedIdentity() . ')';
    }

    /**
     * @return array{}
     */
    public function __serialize(): array
    {
        throw new \LogicException('Credentials cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        unset($data);
        throw new \LogicException('Credentials cannot be unserialized.');
    }
}
