<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Industries\Agreements\AgreementRenderer;
use App\Domains\Network\Industries\Agreements\Agreements;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Built-in e-signature for referral agreements (ESIGN/UETA style): the approved wording filled in for this referral,
 * each side's leader signs with a typed name after agreeing to sign electronically, and we keep time, IP and device.
 * When both have signed, a PDF with an audit certificate goes to both. Directory (Tier C) industries have no agreement.
 */
final class ReferralAgreements
{
    public function __construct(private readonly Agreements $agreements, private readonly Notifier $notifier) {}

    public function start(Referral $ref): void
    {
        $ref->loadMissing(['group.industry', 'seat', 'sender.leader', 'receiver.leader']);
        $industry = $ref->group->industry;
        if ($industry->tier === Tier::Directory || DB::table('referral_agreements')->where('referral_id', $ref->id)->exists()) {
            return;
        }
        $v = $this->agreements->forSigning($industry, $ref->group_id);
        if ($v === null) {
            // No counsel-approved wording yet: the referral goes ahead and the agreement waits for approval.
            DB::table('referral_agreements')->insert(['referral_id' => $ref->id, 'status' => 'awaiting_approval', 'created_at' => now(), 'updated_at' => now()]);

            return;
        }
        $data = $this->data($ref);
        $stateId = (int) DB::table('states')->where('code', $ref->seat->state_code)->value('id');
        $addenda = DB::table('agreement_state_addenda')->join('states', 'states.id', '=', 'agreement_state_addenda.state_id')->where(['industry_id' => $industry->id, 'state_id' => $stateId])
            ->get(['states.name as state', 'text'])->map(fn ($a) => ['state' => (string) $a->state, 'text' => (string) $a->text])->values()->all();
        $sections = AgreementRenderer::render($v['clauses'], $data, $addenda);
        DB::table('referral_agreements')->insert([
            'referral_id' => $ref->id, 'status' => 'pending', 'scope' => $v['scope'], 'version' => $v['version'], 'sections' => json_encode($sections),
            'summary' => json_encode(AgreementRenderer::summary($v['clauses'], $data)), 'content_hash' => hash('sha256', (string) json_encode($sections)), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$ref->sender->leader, $ref->receiver?->leader] as $u) {
            if ($u !== null) {
                $this->notifier->send($u, 'referral.agreement', ['headline' => "Agreement ready to sign: {$ref->client_name}", 'body' => 'Please review and sign the referral agreement. It takes a minute, and work can continue meanwhile.'], url('/app/referrals/'.$ref->ulid), $industry);
            }
        }
    }

    public function sign(Referral $ref, User $user, string $typedName, string $ip, string $device): void
    {
        $a = DB::table('referral_agreements')->where('referral_id', $ref->id)->first();
        abort_if($a === null || $a->status !== 'pending', 422, 'There is no agreement waiting to be signed.');
        $team = Team::of($user);
        $side = match (true) {
            $team?->id === $ref->sender_team_id => 'sender',
            $team?->id === $ref->receiver_team_id => 'receiver',
            default => null,
        };
        abort_if($side === null || $team === null || $team->leader_id !== $user->id, 403, 'Only the team leader can sign for the team.');
        if ($a->{$side.'_signed_at'} !== null) {
            throw ValidationException::withMessages(['name' => 'Your team has already signed.']);
        }
        if (mb_strtolower(trim($typedName)) !== mb_strtolower(trim($user->name))) {
            throw ValidationException::withMessages(['name' => 'Type your full name exactly as on your account: '.$user->name]);
        }
        DB::table('referral_agreements')->where('id', $a->id)->update([$side.'_signer_id' => $user->id, $side.'_signed_name' => $user->name, $side.'_signed_at' => now(), $side.'_ip' => $ip, $side.'_device' => mb_substr($device, 0, 255), 'updated_at' => now()]);
        DB::table('referral_events')->insert(['referral_id' => $ref->id, 'event' => 'signed', 'team_id' => $team->id, 'actor_id' => $user->id, 'meta' => json_encode(['side' => $side]), 'created_at' => now()]);
        $a = DB::table('referral_agreements')->where('id', $a->id)->first();
        if ($a !== null && $a->sender_signed_at !== null && $a->receiver_signed_at !== null) {
            $this->complete($ref, $a);
        }
    }

    /** Hourly: a reminder to the side that hasn't signed after 24 hours (once). */
    public function remind(): void
    {
        foreach (DB::table('referral_agreements')->where('status', 'pending')->whereNull('reminded_at')->where('created_at', '<=', now()->subDay())->get() as $a) {
            $ref = Referral::query()->with(['sender.leader', 'receiver.leader', 'group.industry'])->find((int) $a->referral_id);
            if ($ref === null) {
                continue;
            }
            foreach (['sender' => $ref->sender->leader, 'receiver' => $ref->receiver?->leader] as $side => $u) {
                if ($u !== null && $a->{$side.'_signed_at'} === null) {
                    $this->notifier->send($u, 'referral.agreement', ['headline' => "Reminder: sign the agreement for {$ref->client_name}", 'body' => 'The other side is waiting for your signature. It takes a minute.'], url('/app/referrals/'.$ref->ulid), $ref->group->industry);
                }
            }
            DB::table('referral_agreements')->where('id', $a->id)->update(['reminded_at' => now()]);
        }
    }

    private function complete(Referral $ref, stdClass $a): void
    {
        $sections = (array) json_decode((string) $a->sections, true);
        abort_unless(hash_equals((string) $a->content_hash, hash('sha256', (string) json_encode($sections))), 409, 'The agreement text changed after it was generated.');
        $path = 'agreements/'.$ref->ulid.'.pdf';
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.signed-agreement', ['ref' => $ref, 'a' => $a, 'sections' => $sections])->output();
        Storage::disk('private')->put($path, $pdf);
        DB::table('referral_agreements')->where('id', $a->id)->update(['status' => 'signed', 'pdf_path' => $path, 'updated_at' => now()]);
        foreach ([$ref->sender->leader, $ref->receiver?->leader] as $u) {
            if ($u !== null) {
                $this->notifier->send($u, 'referral.agreement', ['headline' => "Agreement signed: {$ref->client_name}", 'body' => 'Both sides have signed. The signed PDF is on the referral.'], url('/app/referrals/'.$ref->ulid), $ref->group->industry);
            }
        }
    }

    /** @return array<string, string> */
    private function data(Referral $ref): array
    {
        $industry = $ref->group->industry;
        // Joined in PHP: SQL "||" means OR on MySQL, not concatenation.
        $lic = function (Team $t) use ($industry): string {
            $l = DB::table('team_licenses')->join('states', 'states.id', '=', 'team_licenses.state_id')->where(['team_id' => $t->id, 'industry_id' => $industry->id, 'status' => 'verified'])->first(['states.code', 'team_licenses.number']);

            return $l === null ? '' : $l->code.' '.$l->number;
        };
        $stateName = (string) DB::table('states')->where('code', $ref->seat->state_code)->value('name');
        $receiver = $ref->receiver;

        return [
            ...$industry->mergeTerms(),
            'sender_team' => $ref->sender->name, 'sender_name' => $ref->sender->leader->name, 'sender_license' => $lic($ref->sender) ?: 'on file', 'sender_state' => '',
            'receiver_team' => (string) $receiver?->name, 'receiver_name' => (string) $receiver?->leader->name, 'receiver_license' => $receiver ? ($lic($receiver) ?: 'on file') : '', 'receiver_state' => $stateName,
            'client' => $ref->client_name, 'city' => $ref->seat->territory_name, 'date' => now()->toFormattedDateString(), 'governing_state' => $stateName,
            'protection_months' => (string) data_get($ref->group->rules, 'protection_months', 12),
            'fee_terms' => (string) data_get($ref->group->rules, 'fee_terms', 'as agreed between the parties'), 'fee_due_days' => (string) data_get($ref->group->rules, 'fee_due_days', 10),
        ];
    }
}
