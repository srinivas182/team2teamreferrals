<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Push;

use App\Domains\Identity\Models\User;

interface PushSender
{
    /** Sends to every browser the member enabled; returns how many accepted it. */
    public function send(User $user, string $title, string $body, ?string $url): int;
}
