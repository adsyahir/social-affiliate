<?php

namespace App\Social\Data;

final readonly class AccountProfile
{
    /**
     * @param  bool  $isHandle  Whether $username is an @handle (Threads, public channels) or a display name (Facebook Pages).
     */
    public function __construct(
        public string $id,
        public string $username,
        public bool $isHandle = true,
    ) {}

    public function displayName(): string
    {
        return $this->isHandle ? '@'.$this->username : $this->username;
    }

    /**
     * Whether a stored platform ID refers to this account: by its numeric ID, or for accounts
     * with a handle, by @handle (Telegram channels are often saved as @name).
     */
    public function identifies(string $value): bool
    {
        return $value === $this->id
            || ($this->isHandle && $this->username !== '' && strcasecmp(ltrim($value, '@'), $this->username) === 0);
    }
}
