<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Bot;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Referrals\Referrals;
use App\Domains\Seo\SeoData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * The one-tap confirmation from the chat bot: a signed link, valid 30 minutes and usable once, for the member who
 * built the draft. It shows the summary and one button that states the client's consent.
 */
final class BotConfirmController
{
    public function show(Request $request, string $draft): Response
    {
        $d = $this->draft($draft);

        return Inertia::render('BotConfirm', ['summary' => $this->summary($d), 'action' => $request->fullUrl(), 'done' => $d->status === 'sent' ? (string) $d->ulid : null,
            'fullForm' => url('/app/send?draft='.$d->ulid), 'seo' => SeoData::private('Confirm your referral')->toArray()]);
    }

    public function send(Request $request, string $draft, Referrals $referrals): Response
    {
        $d = $this->draft($draft);
        abort_if($d->status !== 'pending', 410, 'This link has already been used.');
        $user = User::query()->findOrFail((int) $d->user_id);
        $answers = [...(array) json_decode((string) ($d->answers ?? '[]'), true), ...array_filter(['client_name' => $d->client_name, 'client_phone' => $d->client_phone, 'client_email' => $d->client_email])];
        $summary = $this->summary($d);
        try {
            $r = $referrals->send($user, Group::query()->findOrFail((int) $d->group_id), City::query()->findOrFail((int) $d->city_id), $answers, true, $d->notes, $request->boolean('confirm_duplicate'));
        } catch (ValidationException $e) {
            return Inertia::render('BotConfirm', ['summary' => $summary, 'action' => $request->fullUrl(), 'done' => null, 'problem' => collect($e->errors())->flatten()->first(),
                'fullForm' => url('/app/send?draft='.$d->ulid), 'seo' => SeoData::private('Confirm your referral')->toArray()]);
        }
        if ($r['referral'] === null) {
            return Inertia::render('BotConfirm', ['summary' => $summary, 'action' => $request->fullUrl(), 'done' => null, 'duplicate' => true,
                'fullForm' => url('/app/send?draft='.$d->ulid), 'seo' => SeoData::private('Confirm your referral')->toArray()]);
        }
        DB::table('referral_drafts')->where('id', $d->id)->update(['status' => 'sent', 'referral_id' => $r['referral']->id, 'updated_at' => now()]);
        DB::table('intake_messages')->where('id', $d->intake_id)->update(['body' => null, 'subject' => null, 'media_path' => null, 'purged_at' => now()]);
        $sessions = DB::table('bot_sessions')->where('draft_id', $d->id)->pluck('id');
        DB::table('bot_messages')->whereIn('session_id', $sessions)->delete(); // the conversation goes with the draft
        DB::table('bot_sessions')->whereIn('id', $sessions)->update(['state' => 'done', 'updated_at' => now()]);
        activity('referral')->causedBy($user)->event('sent-by-chat')->withProperties(['referral' => $r['referral']->ulid])->log('Referral confirmed from the chat link');

        return Inertia::render('BotConfirm', ['summary' => $summary, 'action' => null, 'done' => (string) $r['referral']->ulid, 'team' => $r['referral']->receiver?->name,
            'fullForm' => null, 'seo' => SeoData::private('Referral sent')->toArray()]);
    }

    private function draft(string $ulid): stdClass
    {
        $d = DB::table('referral_drafts')->where('ulid', $ulid)->first();
        abort_if($d === null || $d->group_id === null || $d->city_id === null, 404);

        return $d;
    }

    /** @return array{network: string, city: string, client: string, contact: string, notes: ?string} */
    private function summary(stdClass $d): array
    {
        $c = City::query()->with('state')->findOrFail((int) $d->city_id);

        return ['network' => (string) DB::table('groups')->where('id', $d->group_id)->value('name'), 'city' => $c->name.', '.$c->state->code, 'client' => (string) $d->client_name,
            'contact' => (string) ($d->client_phone ?? $d->client_email), 'notes' => $d->notes];
    }
}
