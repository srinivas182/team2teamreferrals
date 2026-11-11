<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Referrals\Intake\Extractor;
use App\Domains\Referrals\Intake\IntakeMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Voice notes and card photos into draft referrals (2 credits each: reading, then extracting). Neither the audio nor
 * the photo is kept: each is deleted as soon as it has been read. A voice transcript is shown for the member to edit
 * before the draft is made. Photos are identified by their bytes, virus-scanned and stripped of location data first.
 */
final class IntakeMediaController
{
    public function voice(Request $request, IntakeMedia $media): RedirectResponse
    {
        $user = $this->user($request);
        $request->validate(['audio' => ['required', 'file', 'max:5120', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/wav,audio/x-wav,video/webm']]);
        $file = $request->file('audio');
        abort_if(! $file instanceof \Illuminate\Http\UploadedFile, 422);
        try {
            $team = IntakeMedia::creditsFor($user, 2);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['credits' => $e->getMessage()]);
        }
        try {
            $text = $media->transcribe($team, $user, (string) $file->get(), 'voice-note.'.($file->guessExtension() ?: 'webm'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['audio' => $e->getMessage()]);
        }
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

    public function photo(Request $request, IntakeMedia $media, Extractor $extractor): RedirectResponse
    {
        $user = $this->user($request);
        $request->validate(['photo' => ['required', 'file', 'max:8192']]);
        $file = $request->file('photo');
        abort_if(! $file instanceof \Illuminate\Http\UploadedFile, 422);
        $bytes = (string) $file->get();
        if (\App\Support\ImageMetadata::type($bytes) === null) {
            throw ValidationException::withMessages(['photo' => 'Use a JPEG, PNG or WebP photo.']);
        }
        try {
            $team = IntakeMedia::creditsFor($user, 2);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['credits' => $e->getMessage()]);
        }
        $started = hrtime(true);
        try {
            $text = $media->readPhoto($team, $user, $bytes);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }
        $id = DB::table('intake_messages')->insertGetId(['user_id' => $user->id, 'team_id' => $team->id, 'channel' => 'photo', 'body' => $text, 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
        $ulid = $extractor->draft($user, $id, $text, 1);
        DB::table('intake_messages')->where('id', $id)->update(['status' => 'drafted', 'processing_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'updated_at' => now()]);

        return redirect('/app/send?draft='.$ulid);
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
