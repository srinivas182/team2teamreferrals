<?php

declare(strict_types=1);

namespace App\Domains\Network\Licenses;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Models\IndustryLicensing;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Team licenses: added by teams, verified by staff, reminded before expiry, and counted in waitlist ranking. */
final class Licenses
{
    public function __construct(private readonly Notifier $notifier) {}

    public function add(Team $team, Industry $industry, int $stateId, string $number, ?string $expires, ?string $holder, ?UploadedFile $doc): int
    {
        $number = strtoupper(trim($number));
        $rule = IndustryLicensing::query()->where(['industry_id' => $industry->id, 'state_id' => $stateId])->first();
        if ($rule instanceof IndustryLicensing && ! $rule->accepts($number)) {
            throw ValidationException::withMessages(['number' => 'That doesn’t match this state’s license number format.']);
        }
        if (DB::table('team_licenses')->where(['team_id' => $team->id, 'state_id' => $stateId, 'number' => $number])->exists()) {
            throw ValidationException::withMessages(['number' => 'You’ve already added this license.']);
        }

        return (int) DB::table('team_licenses')->insertGetId([
            'team_id' => $team->id, 'industry_id' => $industry->id, 'state_id' => $stateId, 'number' => $number, 'holder_name' => $holder, 'expires_on' => $expires,
            'document_path' => $doc?->store('licenses/'.$team->ulid, 'private') ?: null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function decide(int $id, User $reviewer, bool $verify, ?string $note): void
    {
        $lic = DB::table('team_licenses')->where('id', $id)->first();
        abort_if($lic === null, 404);
        if (! $verify && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Tell the team why, so they can fix it.']);
        }
        DB::table('team_licenses')->where('id', $id)->update(['status' => $verify ? 'verified' : 'rejected', 'reviewer_id' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note, 'updated_at' => now()]);
        $team = Team::query()->findOrFail((int) $lic->team_id);
        $state = (string) DB::table('states')->where('id', $lic->state_id)->value('code');
        $this->notifier->send($team->leader, 'license.update', [
            'headline' => $verify ? "License verified ({$state})" : "License not verified ({$state})",
            'body' => $verify ? "Your {$state} license {$lic->number} is verified. It now counts towards your place on waitlists." : "We couldn’t verify your {$state} license {$lic->number}: {$note}",
        ], url('/app/profile'));
        activity('admin')->causedBy($reviewer)->event($verify ? 'license-verified' : 'license-rejected')->withProperties(['license' => $id, 'team' => $team->ulid, 'state' => $state])->log(($verify ? 'Verified' : 'Rejected')." license {$lic->number} ({$state})");
        self::rerank($team);
    }

    /** Other teams using the same license number in the same state. */
    public static function duplicates(int $stateId, string $number, int $exceptTeamId): int
    {
        return DB::table('team_licenses')->where(['state_id' => $stateId, 'number' => $number])->where('team_id', '!=', $exceptTeamId)->count();
    }

    /**
     * Waitlist ranking until the trust score exists (Sprint 34): verified licenses count most,
     * then pending ones, then profile strength. Capped at 100.
     */
    public static function rankScore(Team $team): int
    {
        $verified = DB::table('team_licenses')->where(['team_id' => $team->id, 'status' => 'verified'])->count();
        $pending = DB::table('team_licenses')->where(['team_id' => $team->id, 'status' => 'pending'])->count();

        // Licenses up to 60, profile strength up to 30, plus 10 for being on the platform.
        return min(100, ($verified > 0 ? 60 : 0) + ($pending > 0 && $verified === 0 ? 20 : 0) + (int) round(\App\Domains\Network\Onboarding\ProfileStrength::for($team)['score'] * 0.3) + 10);
    }

    public static function rerank(Team $team): void
    {
        DB::table('seat_waitlist')->where('team_id', $team->id)->update(['score' => self::rankScore($team)]);
    }

    /** Daily: reminders 30 and 7 days before expiry, then mark expired licenses. */
    public function remind(): void
    {
        foreach ([30 => 'reminded_30_at', 7 => 'reminded_7_at'] as $days => $col) {
            foreach (DB::table('team_licenses')->where('status', 'verified')->whereNull($col)->whereDate('expires_on', '<=', now()->addDays($days)->toDateString())->whereDate('expires_on', '>=', now()->toDateString())->get() as $l) {
                $team = Team::query()->find((int) $l->team_id);
                if ($team instanceof Team) {
                    $state = (string) DB::table('states')->where('id', $l->state_id)->value('code');
                    $this->notifier->send($team->leader, 'license.update', ['headline' => "Your {$state} license expires in {$days} days", 'body' => "License {$l->number} expires on {$l->expires_on}. Add the renewed license so your seat stays verified."], url('/app/profile'));
                }
                DB::table('team_licenses')->where('id', $l->id)->update([$col => now()]);
            }
        }
        foreach (DB::table('team_licenses')->where('status', 'verified')->whereDate('expires_on', '<', now()->toDateString())->get() as $l) {
            DB::table('team_licenses')->where('id', $l->id)->update(['status' => 'expired', 'updated_at' => now()]);
            $team = Team::query()->find((int) $l->team_id);
            if ($team instanceof Team) {
                $this->notifier->send($team->leader, 'license.update', ['headline' => 'A license has expired', 'body' => "License {$l->number} expired on {$l->expires_on}. Add the renewed license to stay verified."], url('/app/profile'));
                self::rerank($team);
            }
        }
    }
}
