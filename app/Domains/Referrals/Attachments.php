<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Events\ReferralChanged;
use App\Support\Scanning\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use stdClass;

/**
 * Files shared on a referral: private storage, a virus scan before anyone can open them, quarantine when infected,
 * and the same rule as contact details: the receiver sees file names only until they accept.
 */
final class Attachments
{
    /** @param list<UploadedFile> $files */
    public static function store(Referral $ref, Team $team, User $user, ?int $messageId, array $files): void
    {
        foreach ($files as $f) {
            $ext = strtolower($f->getClientOriginalExtension() ?: (string) $f->extension());
            $path = $f->storeAs('referrals/'.$ref->ulid, Str::random(32).'.'.$ext, 'private');
            $id = DB::table('referral_attachments')->insertGetId([
                'referral_id' => $ref->id, 'message_id' => $messageId, 'team_id' => $team->id, 'user_id' => $user->id,
                'original_name' => mb_substr(preg_replace('/[^\w .()-]+/u', '_', $f->getClientOriginalName()) ?: 'file', 0, 180),
                'path' => (string) $path, 'mime' => (string) $f->getMimeType(), 'size' => (int) $f->getSize(), 'status' => 'pending', 'created_at' => now(),
            ]);
            ScanAttachment::dispatch($id);
        }
    }

    public static function scan(int $id, VirusScanner $scanner): void
    {
        $a = DB::table('referral_attachments')->where('id', $id)->first();
        if ($a === null || $a->status !== 'pending') {
            return;
        }
        $result = $scanner->scan(Storage::disk('private')->path((string) $a->path));
        $status = match ($result) {
            'clean' => 'clean',
            'infected' => 'infected',
            default => 'unscanned',
        };
        $path = (string) $a->path;
        if ($status === 'infected') {
            // Quarantined: moved out of the referral's folder and never served.
            $q = 'quarantine/'.basename($path);
            Storage::disk('private')->move($path, $q);
            $path = $q;
        }
        DB::table('referral_attachments')->where('id', $id)->update(['status' => $status, 'path' => $path, 'scanned_at' => now()]);
        $ref = Referral::query()->find((int) $a->referral_id);
        if ($ref !== null) {
            ReferralChanged::dispatch($ref, 'attachment');
        }
    }

    public static function openable(stdClass $a): bool
    {
        return $a->status === 'clean' || ($a->status === 'unscanned' && ! config('attachments.require_scan'));
    }

    /** @return array<int, list<array{id: int, name: string, size: int, status: string, url: ?string}>> attachments by message id */
    public static function forViewer(Referral $ref, bool $locked): array
    {
        $out = [];
        foreach (DB::table('referral_attachments')->where('referral_id', $ref->id)->orderBy('id')->get() as $a) {
            $out[(int) $a->message_id][] = [
                'id' => (int) $a->id, 'name' => (string) $a->original_name, 'size' => (int) $a->size, 'status' => (string) $a->status,
                // Short-lived signed link, only when the file may be opened by this viewer.
                'url' => ! $locked && self::openable($a) ? URL::temporarySignedRoute('referrals.attachment', now()->addMinutes(10), ['referral' => $ref->ulid, 'attachment' => (int) $a->id]) : null,
            ];
        }

        return $out;
    }
}
