<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Intake;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Ai\AiCredits;
use App\Domains\Platform\Ai\AiGateway;
use App\Domains\Platform\Ai\AiProvider;
use App\Support\ImageMetadata;
use App\Support\Scanning\VirusScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Reading a voice note or a card photo into text, for the Drafts page and for WhatsApp alike. One credit each; the
 * audio and the photo are never kept. Photos are identified by their bytes, virus-scanned and stripped of location
 * data before the AI sees them. Problems are thrown as RuntimeExceptions with a message the member can read.
 */
final class IntakeMedia
{
    public function __construct(private readonly AiProvider $ai, private readonly AiGateway $gateway, private readonly VirusScanner $scanner) {}

    /** Check there are enough credits for reading and extracting before anything is read. */
    public static function creditsFor(User $user, int $needed): Team
    {
        $team = Team::of($user) ?? Team::forLeader($user);
        AiCredits::grantMonthly($team);
        if (AiCredits::balance($team) < $needed || AiCredits::usedToday($team) + $needed > AiCredits::setting('daily_cap')) {
            throw new RuntimeException('Not enough AI credits for this (it uses '.$needed.'). You can buy more on the Billing page.');
        }

        return $team;
    }

    public function transcribe(Team $team, User $user, string $audio, string $filename): string
    {
        try {
            $text = $this->ai->transcribe($audio, $filename, 'intake_transcribe');
        } catch (Throwable $e) {
            throw new RuntimeException('The voice note couldn’t be transcribed right now: '.$e->getMessage());
        }
        if ($text === '') {
            throw new RuntimeException('No speech was found in the recording. Try again closer to the microphone.');
        }
        $usage = DB::table('ai_usage_logs')->insertGetId(['team_id' => $team->id, 'user_id' => $user->id, 'feature' => 'intake_transcribe', 'provider' => 'openai', 'credits' => 1, 'status' => 'ok', 'created_at' => now()]);
        AiCredits::charge($team, 1, $usage);

        return $text;
    }

    public function readPhoto(Team $team, User $user, string $bytes): string
    {
        $mime = ImageMetadata::type($bytes); // the file's own bytes, not its name
        if ($mime === null) {
            throw new RuntimeException('Use a JPEG, PNG or WebP photo.');
        }
        $path = 'intake/'.bin2hex(random_bytes(16));
        Storage::disk('local')->put($path, $bytes);
        try {
            $verdict = $this->scanner->scan(Storage::disk('local')->path($path));
            if ($verdict === 'infected' || ($verdict !== 'clean' && (bool) config('attachments.require_scan'))) {
                throw new RuntimeException($verdict === 'infected' ? 'This file was flagged by the virus scan.' : 'Photos can’t be checked right now. Please try again shortly.');
            }
            $clean = ImageMetadata::strip($bytes);
        } finally {
            Storage::disk('local')->delete($path); // not kept
        }
        $read = $this->gateway->run($team, $user, 'intake_photo_read', 'intake_photo_read', [], 1, 600, ['data' => base64_encode($clean), 'mime' => $mime]);
        if ($read === null || trim($read['text']) === '') {
            throw new RuntimeException('The photo couldn’t be read right now. You can type the details instead.');
        }

        return $read['text'];
    }
}
