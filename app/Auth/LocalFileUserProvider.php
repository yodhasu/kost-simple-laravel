<?php

namespace App\Auth;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Arr;

class LocalFileUserProvider implements UserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        $credentials = $this->readCredentials();

        if (($credentials['id'] ?? null) !== (string) $identifier) {
            return null;
        }

        return $this->makeUser($credentials);
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return $this->retrieveById($identifier);
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        // Local file auth is development-only, so remember tokens are not persisted.
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        if (empty($credentials['email'])) {
            return null;
        }

        $stored = $this->readCredentials();

        return strcasecmp($stored['email'] ?? '', (string) $credentials['email']) === 0
            ? $this->makeUser($stored)
            : null;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        if (empty($credentials['password'])) {
            return false;
        }

        $storedPassword = (string) ($user->getAuthPassword() ?? '');

        return hash_equals($storedPassword, (string) $credentials['password']);
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[\SensitiveParameter] array $credentials,
        bool $force = false
    ): void {
        // Plain-text local dev credentials are intentionally not re-hashed.
    }

    /**
     * @return array<string, string>
     */
    private function readCredentials(): array
    {
        $path = config('auth.local_file.path');

        if (! is_string($path) || ! is_file($path)) {
            return [];
        }

        $parsed = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));

            if ($key !== '') {
                $parsed[$key] = $value;
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function makeUser(array $credentials): GenericUser
    {
        return new GenericUser([
            'id' => Arr::get($credentials, 'id', Arr::get($credentials, 'email', 'local-tester')),
            'name' => Arr::get($credentials, 'name', 'Local Tester'),
            'email' => Arr::get($credentials, 'email', 'tester@kost.local'),
            'password' => Arr::get($credentials, 'password', ''),
            'role' => Arr::get($credentials, 'role', 'owner'),
        ]);
    }
}
