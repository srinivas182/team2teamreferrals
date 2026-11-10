<?php

use App\Domains\Identity\Models\User;
use App\Domains\Referrals\Conversations;
use Illuminate\Support\Facades\Broadcast;

// Private real-time channels (Mercure). A team hears about its own referrals; a referral's channel is only for the
// two teams on it (typing indicators travel here as client events).
Broadcast::channel('teams.{team}', fn (User $user, string $team) => Conversations::canListenToTeam($user, $team));
Broadcast::channel('referrals.{referral}', fn (User $user, string $referral) => Conversations::canListenToReferral($user, $referral));
