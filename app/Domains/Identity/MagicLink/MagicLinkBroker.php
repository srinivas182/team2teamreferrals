<?php

declare(strict_types=1);

namespace App\Domains\Identity\MagicLink;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\MagicLinkNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** One-tap email sign-in: single-use, signed, expires after 15 minutes. */
final class MagicLinkBroker
{
    private const TTL_MINUTES = 15;

    public function send(string $email): void
    {
        $user = User::query()->where('email', strtolower(trim($email)))->where('is_active', true)->first();
        if ($user === null) {
            return; // Same response either way, so the form can't be used to discover accounts.
        }
        $token = Str::random(48);
        Cache::put($this->key($token), $user->getKey(), now()->addMinutes(self::TTL_MINUTES));
        $url = URL::temporarySignedRoute('login.magic.consume', now()->addMinutes(self::TTL_MINUTES), ['token' => $token]);
        $user->notify(new MagicLinkNotification($url, self::TTL_MINUTES));
    }

    /** Returns the user and burns the token; null if it was used, expired or never existed. */
    public function consume(string $token): ?User
    {
        $id = Cache::pull($this->key($token));

        if ($id === null) {
            return null;
        }
        $user = User::query()->where('is_active', true)->find($id);

        return $user instanceof User ? $user : null;
    }

    private function key(string $token): string
    {
        return 'magic-link:'.hash('sha256', $token);
    }
}
