<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Platform\Settings\Settings;
use App\Support\ImageMetadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Support tickets (Sprint 48a). Anyone can open one: members from their account, partners and visitors with their
 * email (they get a private link). Staff work them in Admin → Support: assignment, priority, internal notes, saved
 * replies and response-time targets. Everyone can reply on the web or by replying to the email.
 *
 * Status: open (waiting on us) → pending (waiting on the customer) → solved → closed. A customer reply reopens a
 * solved ticket; pending tickets with no answer for 5 days are solved, and solved tickets close after 7 days.
 */
final class Tickets
{
    /** Hours to first response and to resolution, by priority (Admin → Support → Settings). */
    public const SLA = ['urgent' => [1, 8], 'high' => [4, 24], 'normal' => [8, 72], 'low' => [24, 120]];

    public const PENDING_DAYS = 5;

    public const CLOSE_DAYS = 7;

    public const MAX_FILES = 3;

    private const FILE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    /**
     * Open a ticket. Returns the ticket and, for people without an account, the token in their private link.
     *
     * @param  array{name: string, email: string, subject: string, body: string, category?: ?string}  $d
     * @param  list<UploadedFile>  $files
     * @return array{0: Ticket, 1: ?string}
     */
    public static function open(array $d, ?User $user, ?int $partnerId = null, string $source = 'web', ?string $ipHash = null, array $files = [], ?string $emailId = null): array
    {
        $category = isset(Ticket::CATEGORIES[$d['category'] ?? '']) ? (string) $d['category'] : ($partnerId !== null ? 'partners' : 'other');
        $attachments = self::store($files);
        $token = $user === null ? Str::random(40) : null;
        $t = DB::transaction(function () use ($d, $user, $partnerId, $source, $ipHash, $category, $attachments, $token, $emailId): Ticket {
            $t = new Ticket(['user_id' => $user?->id, 'partner_id' => $partnerId, 'name' => trim($d['name']), 'email' => strtolower(trim($d['email'])), 'subject' => mb_substr(trim($d['subject']), 0, 160),
                'category' => $category, 'source' => $source, 'ip_hash' => $ipHash]);
            $t->number = 'T-'.Str::upper(Str::random(8));
            $t->access_hash = $token !== null ? hash('sha256', $token) : null;
            $t->last_customer_at = now();
            self::targets($t);
            $t->save();
            $t->forceFill(['number' => 'T-'.(10000 + $t->id)])->save();
            TicketMessage::create(['ticket_id' => $t->id, 'author' => 'customer', 'user_id' => $user?->id, 'body' => trim($d['body']), 'via' => $source === 'email' ? 'email' : 'web',
                'attachments' => $attachments ?: null, 'email_id' => $emailId]);

            return $t;
        });
        self::mail($t, "We got your message [{$t->number}]", ["Hi {$t->firstName()},", 'Thanks for getting in touch. We usually answer within '.self::targetWords($t).'.',
            '“'.Str::limit(trim($d['body']), 300).'”', 'Reply to this email or use the link below to add anything else.'], self::link($t, $token));
        foreach (self::staff() as $u) {
            app(Notifier::class)->send($u, 'admin.support', ['headline' => "New ticket {$t->number}: {$t->subject}", 'body' => $t->name.' · '.Ticket::CATEGORIES[$t->category]], url('/admin/support/'.$t->ulid));
        }

        return [$t, $token];
    }

    /**
     * The customer replies (web or email). A solved ticket reopens; a closed one starts a new ticket that refers back.
     *
     * @param  list<UploadedFile>  $files
     */
    public static function customerReply(Ticket $t, string $body, string $via = 'web', array $files = [], ?string $emailId = null): Ticket
    {
        if ($emailId !== null && TicketMessage::query()->where('email_id', $emailId)->exists()) {
            return $t; // the same email delivered twice
        }
        if ($t->status === 'closed') {
            [$new] = self::open(['name' => $t->name, 'email' => $t->email, 'subject' => 'Re: '.$t->subject, 'body' => $body."\n\n(Follows on from {$t->number}.)", 'category' => $t->category],
                $t->user, $t->partner_id, $via === 'email' ? 'email' : 'web', null, $files, $emailId);

            return $new;
        }
        TicketMessage::create(['ticket_id' => $t->id, 'author' => 'customer', 'user_id' => $t->user_id, 'body' => trim($body), 'via' => $via, 'attachments' => self::store($files) ?: null, 'email_id' => $emailId]);
        $t->forceFill(['status' => 'open', 'solved_at' => null, 'last_customer_at' => now()])->save();
        $to = $t->assignee !== null ? collect([$t->assignee]) : self::staff();
        foreach ($to as $u) {
            app(Notifier::class)->send($u, 'admin.support', ['headline' => "{$t->name} replied on {$t->number}", 'body' => Str::limit(trim($body), 140)], url('/admin/support/'.$t->ulid));
        }

        return $t;
    }

    /**
     * Staff reply to the customer (emailed), or add a note only staff see. A reply sets the ticket to "waiting on
     * you" unless another status is chosen.
     *
     * @param  list<UploadedFile>  $files
     */
    public static function staffReply(Ticket $t, User $by, string $body, bool $internal = false, ?string $status = null, array $files = []): void
    {
        $body = strtr(trim($body), ['{first_name}' => $t->firstName(), '{ticket}' => $t->number]);
        $m = TicketMessage::create(['ticket_id' => $t->id, 'author' => 'staff', 'user_id' => $by->id, 'body' => $body, 'internal' => $internal, 'attachments' => self::store($files) ?: null]);
        if ($internal) {
            return;
        }
        $t->first_responded_at ??= now();
        $t->assignee_id ??= $by->id;
        $t->last_staff_at = now();
        $t->status = $status !== null && isset(Ticket::STATUSES[$status]) ? $status : 'pending';
        $t->solved_at = $t->status === 'solved' ? now() : null;
        $t->save();
        $lines = [$body];
        if ($m->attachments) {
            $lines[] = count($m->attachments).' '.Str::plural('file', count($m->attachments)).' attached: open the ticket to download.';
        }
        $lines[] = $t->status === 'solved' ? 'We’ve marked this as solved. Just reply if you need anything else and it reopens.' : 'Reply to this email to answer.';
        self::mail($t, "Re: {$t->subject} [{$t->number}]", $lines, self::link($t, null));
    }

    /**
     * Staff change status, priority, category or who it's assigned to. Each change is noted on the ticket.
     *
     * @param  array{status?: string, priority?: string, category?: string, assignee_id?: ?int}  $d
     */
    public static function update(Ticket $t, User $by, array $d): void
    {
        $notes = [];
        if (isset($d['priority']) && $d['priority'] !== $t->priority) {
            $notes[] = 'Priority: '.Ticket::PRIORITIES[$t->priority].' → '.Ticket::PRIORITIES[$d['priority']];
            $t->priority = $d['priority'];
            self::targets($t);
        }
        if (isset($d['category']) && $d['category'] !== $t->category) {
            $notes[] = 'Topic: '.Ticket::CATEGORIES[$t->category].' → '.Ticket::CATEGORIES[$d['category']];
            $t->category = $d['category'];
        }
        if (array_key_exists('assignee_id', $d) && $d['assignee_id'] !== $t->assignee_id) {
            $to = $d['assignee_id'] !== null ? User::query()->find($d['assignee_id']) : null;
            $notes[] = 'Assigned to '.($to->name ?? 'nobody');
            $t->assignee_id = $to?->id;
            if ($to !== null && $to->id !== $by->id) {
                app(Notifier::class)->send($to, 'admin.support', ['headline' => "{$t->number} is yours", 'body' => $by->name.' assigned you: '.$t->subject], url('/admin/support/'.$t->ulid));
            }
        }
        if (isset($d['status']) && $d['status'] !== $t->status) {
            $notes[] = 'Status: '.Ticket::STATUSES[$t->status].' → '.Ticket::STATUSES[$d['status']];
            $t->status = $d['status'];
            $t->solved_at = in_array($t->status, ['solved', 'closed'], true) ? ($t->solved_at ?? now()) : null;
        }
        if ($notes !== []) {
            $t->save();
            TicketMessage::create(['ticket_id' => $t->id, 'author' => 'system', 'user_id' => $by->id, 'body' => implode('. ', $notes).'.', 'internal' => true]);
        }
    }

    public static function solveByCustomer(Ticket $t): void
    {
        if (in_array($t->status, ['open', 'pending'], true)) {
            $t->forceFill(['status' => 'solved', 'solved_at' => now()])->save();
            TicketMessage::create(['ticket_id' => $t->id, 'author' => 'system', 'body' => $t->name.' marked this as solved.', 'internal' => true]);
        }
    }

    /** "How did we do?" on a solved ticket. */
    public static function rate(Ticket $t, string $rating, ?string $comment): void
    {
        abort_unless(in_array($t->status, ['solved', 'closed'], true), 422, 'You can rate a ticket once it’s solved.');
        $t->forceFill(['rating' => $rating, 'rating_comment' => $comment !== null ? mb_substr(trim($comment), 0, 1000) : null])->save();
    }

    /** Hourly: response-time alerts, waiting tickets solved after 5 days, solved tickets closed after 7. */
    public static function run(): void
    {
        foreach (Ticket::query()->with('assignee')->where('status', 'open')->whereNull('first_responded_at')->whereNull('breach_alerted_at')->where('first_response_due_at', '<', now())->get() as $t) {
            $t->forceFill(['breach_alerted_at' => now()])->save();
            foreach ($t->assignee !== null ? [$t->assignee] : self::staff() as $u) {
                app(Notifier::class)->send($u, 'admin.support', ['headline' => "{$t->number} is past its response time", 'body' => Ticket::PRIORITIES[$t->priority].' priority: '.$t->subject], url('/admin/support/'.$t->ulid));
            }
        }
        foreach (Ticket::query()->where('status', 'pending')->where('last_staff_at', '<', now()->subDays(self::PENDING_DAYS))->get() as $t) {
            $t->forceFill(['status' => 'solved', 'solved_at' => now()])->save();
            TicketMessage::create(['ticket_id' => $t->id, 'author' => 'system', 'body' => 'Solved automatically: no reply for '.self::PENDING_DAYS.' days.', 'internal' => true]);
            self::mail($t, "Re: {$t->subject} [{$t->number}]", ["Hi {$t->firstName()},", 'We haven’t heard back, so we’ve marked this as solved. Just reply if you still need help and it reopens.'], self::link($t, null));
        }
        Ticket::query()->where('status', 'solved')->where('solved_at', '<', now()->subDays(self::CLOSE_DAYS))->update(['status' => 'closed', 'updated_at' => now()]);
    }

    /**
     * An email arrived for support: a reply to a ticket (reply+t…), or a new message to support@ (a new ticket).
     * A reply is accepted only from the ticket's own address.
     */
    public static function inbound(string $to, string $from, string $subject, string $text, string $emailId): string
    {
        $from = strtolower(trim((string) (preg_match('/<([^>]+)>/', $from, $m) === 1 ? $m[1] : $from)));
        if (preg_match('/reply\+t([0-9a-z]{26})\.([0-9a-f]{16})@/i', $to, $m) === 1) {
            $t = Ticket::query()->where('ulid', strtolower($m[1]))->first();
            if ($t === null || ! hash_equals(self::sign($t->ulid), strtolower($m[2]))) {
                return 'unknown-ticket';
            }
            if ($from !== $t->email && $from !== strtolower((string) $t->user?->email)) {
                return 'wrong-sender';
            }
            self::customerReply($t, $text !== '' ? $text : '(empty reply)', 'email', [], $emailId);

            return 'replied';
        }
        if (self::isSupportAddress($to) && filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
            if (TicketMessage::query()->where('email_id', $emailId)->exists()) {
                return 'duplicate';
            }
            $user = User::query()->where('email', $from)->first();
            self::open(['name' => $user->name ?? Str::before($from, '@'), 'email' => $from, 'subject' => $subject !== '' ? $subject : 'Email to support', 'body' => $text !== '' ? $text : '(no text)'],
                $user, null, 'email', null, [], $emailId);

            return 'opened';
        }

        return 'ignored';
    }

    public static function isSupportAddress(string $to): bool
    {
        $domain = (string) config('mail.inbound.domain');

        return $domain !== '' && str_contains(strtolower($to), 'support@'.strtolower($domain));
    }

    /** reply+t<ticket>.<signature>@<inbound domain>, when reply-by-email is set up. */
    public static function replyAddress(Ticket $t): ?string
    {
        $domain = (string) config('mail.inbound.domain');

        return $domain !== '' ? 'reply+t'.$t->ulid.'.'.self::sign($t->ulid).'@'.$domain : null;
    }

    /** Can this person see the ticket: its member, or someone holding its private link. Staff use the admin. */
    public static function canView(Ticket $t, ?User $user, ?string $token): bool
    {
        if ($user !== null && $t->user_id === $user->id) {
            return true;
        }

        return $token !== null && $t->access_hash !== null && hash_equals($t->access_hash, hash('sha256', $token));
    }

    /** Where the customer reads the ticket: their account, or their private link (a new one is made if needed). */
    public static function link(Ticket $t, ?string $token): string
    {
        if ($t->user_id !== null) {
            return url('/app/support/'.$t->ulid);
        }
        if ($token === null) {
            $token = Str::random(40);
            $t->forceFill(['access_hash' => hash('sha256', $token)])->save();
        }

        return url('/support/t/'.$t->ulid.'?k='.$token);
    }

    /** @return array{0: int, 1: int} hours to first response and to resolution */
    public static function sla(string $priority): array
    {
        $s = app(Settings::class);
        $d = self::SLA[$priority] ?? self::SLA['normal'];

        return [(int) $s->get("support.sla.{$priority}.first", (string) $d[0]), (int) $s->get("support.sla.{$priority}.resolve", (string) $d[1])];
    }

    /** @return Collection<int, User> */
    public static function staff(): Collection
    {
        return User::query()->where(fn ($q) => $q->whereHas('permissions', fn ($p) => $p->where('name', 'support.manage'))
            ->orWhereHas('roles', fn ($r) => $r->where('name', Permissions::SUPER_ADMIN)->orWhereHas('permissions', fn ($p) => $p->where('name', 'support.manage'))))->get();
    }

    /**
     * Files are checked by their bytes (images and PDFs only), images are stripped of hidden data, and everything is
     * kept on private storage, downloadable only by the ticket's customer and staff.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{path: string, name: string, size: int, type: string}>
     */
    public static function store(array $files): array
    {
        $out = [];
        foreach (array_slice($files, 0, self::MAX_FILES) as $f) {
            $bytes = (string) file_get_contents((string) $f->getRealPath());
            $type = ImageMetadata::type($bytes) ?? (str_starts_with($bytes, '%PDF-') ? 'application/pdf' : null);
            if ($type === null) {
                throw ValidationException::withMessages(['files' => 'Attach images (JPEG, PNG, WebP) or PDFs only.']);
            }
            $path = 'support/'.Str::lower((string) Str::ulid()).'.'.self::FILE_TYPES[$type];
            Storage::disk('private')->put($path, $type === 'application/pdf' ? $bytes : ImageMetadata::strip($bytes));
            $out[] = ['path' => $path, 'name' => mb_substr(preg_replace('/[^\w.\- ]+/u', '_', $f->getClientOriginalName()) ?? 'file', 0, 120), 'size' => strlen($bytes), 'type' => $type];
        }

        return $out;
    }

    private static function targets(Ticket $t): void
    {
        [$first, $resolve] = self::sla($t->priority);
        $from = $t->created_at ?? now();
        $t->first_response_due_at = $from->copy()->addHours($first);
        $t->resolution_due_at = $from->copy()->addHours($resolve);
    }

    private static function targetWords(Ticket $t): string
    {
        $h = self::sla($t->priority)[0];

        return $h < 24 ? $h.' '.Str::plural('hour', $h) : intdiv($h, 24).' '.Str::plural('day', intdiv($h, 24));
    }

    /** @param list<string> $lines */
    private static function mail(Ticket $t, string $subject, array $lines, string $link): void
    {
        Notification::route('mail', $t->email)->notify(new TicketMail($subject, $lines, $link, self::replyAddress($t)));
    }

    private static function sign(string $ulid): string
    {
        return substr(hash_hmac('sha256', 'ticket|'.$ulid, (string) config('app.key')), 0, 16);
    }
}
