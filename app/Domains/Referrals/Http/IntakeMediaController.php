<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Ai\AiCredits;
use App\Domains\Platform\Ai\AiGateway;
use App\Domains\Platform\Ai\AiProvider;
use App\Domains\Referrals\Intake\Extractor;
use App\Support\ImageMetadata;
use App\Support\Scanning\VirusScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Voice notes and card photos into draft referrals (2 credits each: reading, then extracting). Neither the audio nor
 * the photo is kept: each is deleted as soon as it has been read. A voice transcript is shown for the member to edit
 * before the draft is made. Photos are identified by their bytes, virus-scanned and stripped of location data first.
 */
final class IntakeMediaController
{
    public function voice(Request $request, AiProvider $ai): RedirectResponse
    {
        $user = $this->user($request);
        $request->validate(['audio' => ['required', 'file', 'max:5120', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/wav,audio/x-wav,video/webm']]);
        $team = $this->creditsFor($user, 2);
        $file = $request->file('audio');
        abort_if(! $file instanceof \Illuminate\Http\UploadedFile, 422);
        try {
            $text = $ai->transcribe((string) $file->get(), 'voice-note.'.($file->guessExtension() ?: 'webm'), 'intake_transcribe');
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['audio' => 'The voice note couldn’t be transcribed right now: '.$e->getMessage()]);
        }
        if ($text === '') {
            throw ValidationException::withMessages(['audio' => 'No speech was found in the recording. Try again closer to the microphone.']);
        }
        $usage = DB::table('ai_usage_logs')->insertGetId(['team_id' => $team->id, 'user_id' => $user->id, 'feature' => 'intake_transcribe', 'provider' => 'openai', 'credits' => 1, 'status' => 'ok', 'created_at' => now()]);
        AiCredits::charge($team, 1, $usage);
        $id = DB::table('intake_messages')->insertGetId(['user_id' => $user->id, 'team_id' => $team->id, 'channel' => 'voice', 'body' => mb_substr($text, 0, 8000), 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);

        return redirect('/app/drafts?transcript='.$id)->with('status', 'voice-transcribed');
    }

    /** After checking the transcript: make the draft (1 credit). */
    public function draftFromTranscript(Request $request, int $intake, Extractor $extractor): RedirectResponse
    {
        $user = $this->user($request);
        $m = DB::table('intake_messages')->where(['id' => $intake, 'user_id' => $user->id, 'channel' => 'voice', 'status' => 'received'])->first();
        abort_if($m === null, 404);
        $text = (string) $request->validate(['text' => ['required', 'string', 'min:5', 'max:8000']])['text'];
        DB::table('intake_messages')->where('id', $m->id)->update(['body' => $text, 'updated_at' => now()]);
        $started = hrtime(true);
        $ulid = $extractor->draft($user, (int) $m->id, $text, 1);
        DB::table('intake_messages')->where('id', $m->id)->update(['status' => 'drafted', 'processing_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);

        return redirect('/app/send?draft='.$ulid);
    }

    public function photo(Request $request, AiGateway $gateway, Extractor $extractor, VirusScanner $scanner): RedirectResponse
    {
        $user = $this->user($request);
        $request->validate(['photo' => ['required', 'file', 'max:8192']]);
        $file = $request->file('photo');
        abort_if(! $file instanceof \Illuminate\Http\UploadedFile, 422);
        $bytes = (string) $file->get();
        $mime = ImageMetadata::type($bytes); // the file's own bytes, not its name
        if ($mime === null) {
            throw ValidationException::withMessages(['photo' => 'Use a JPEG, PNG or WebP photo.']);
        }
        $team = $this->creditsFor($user, 2);
        $started = hrtime(true);
        $path = 'intake/'.bin2hex(random_bytes(16));
        Storage::disk('local')->put($path, $bytes);
        try {
            $verdict = $scanner->scan(Storage::disk('local')->path($path));
            if ($verdict === 'infected' || ($verdict !== 'clean' && (bool) config('attachments.require_scan'))) {
                throw ValidationException::withMessages(['photo' => $verdict === 'infected' ? 'This file was flagged by the virus scan.' : 'Photos can’t be checked right now. Please try again shortly.']);
            }
            $clean = ImageMetadata::strip($bytes);
        } finally {
            Storage::disk('local')->delete($path); // not kept
        }
        $id = DB::table('intake_messages')->insertGetId(['user_id' => $user->id, 'team_id' => $team->id, 'channel' => 'photo', 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
        $read = $gateway->run($team, $user, 'intake_photo_read', 'intake_photo_read', [], 1, 600, ['data' => base64_encode($clean), 'mime' => $mime]);
        if ($read === null || trim($read['text']) === '') {
            throw ValidationException::withMessages(['photo' => 'The photo couldn’t be read right now. You can type the details instead.']);
        }
        DB::table('intake_messages')->where('id', $id)->update(['body' => $read['text']]);
        $ulid = $extractor->draft($user, $id, $read['text'], 1);
        DB::table('intake_messages')->where('id', $id)->update(['status' => 'drafted', 'processing_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'updated_at' => now()]);

        return redirect('/app/send?draft='.$ulid);
    }

    /** Both steps cost a credit each: check there are enough before reading anything. */
    private function creditsFor(User $user, int $needed): Team
    {
        $team = Team::of($user) ?? Team::forLeader($user);
        AiCredits::grantMonthly($team);
        if (AiCredits::balance($team) < $needed || AiCredits::usedToday($team) + $needed > AiCredits::setting('daily_cap')) {
            throw ValidationException::withMessages(['credits' => 'Not enough AI credits for this (it uses '.$needed.'). You can buy more on the Billing page.']);
        }

        return $team;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
