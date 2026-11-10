<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\NotificationCatalogue;
use App\Domains\Messaging\Templates;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Group owners word referral messages for their network; the platform switches client texting on per industry. */
final class GroupWordingController
{
    /** The messages owners may reword: the referral journey. */
    private const TYPES = ['referral.waiting', 'referral.accepted', 'referral.declined', 'referral.message', 'referral.agreement'];

    public function show(Request $request, Group $group, Templates $templates): Response
    {
        $this->owner($request, $group);

        return Inertia::render('app/group/Messages', [
            'group' => ['slug' => $group->slug, 'name' => $group->name],
            'messages' => array_map(function (string $type) use ($group, $templates) {
                $mail = $templates->wording($type, 'mail', $group->industry_id, $group->id);
                $sms = $templates->wording($type, 'sms', $group->industry_id, $group->id);

                return ['type' => $type, 'label' => NotificationCatalogue::get($type)['label'], 'subject' => $mail['subject'], 'body' => $mail['body'], 'sms' => $sms['body'],
                    'custom' => $mail['source'] === 'group' || $sms['source'] === 'group', 'placeholders' => self::allowed($type, $group->industry)];
            }, self::TYPES),
            'status' => session('status'),
            'seo' => SeoData::private('Messages · '.$group->name)->toArray(),
        ]);
    }

    public function save(Request $request, Group $group): RedirectResponse
    {
        $user = $this->owner($request, $group);
        $d = $request->validate(['type' => ['required', 'in:'.implode(',', self::TYPES)], 'subject' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:2000'], 'sms' => ['nullable', 'string', 'max:300']]);
        $allowed = self::allowed((string) $d['type'], $group->industry);
        foreach (['subject', 'body', 'sms'] as $f) {
            preg_match_all('/\{([a-z_]+)\}/', (string) ($d[$f] ?? ''), $m);
            if ($bad = array_values(array_diff($m[1], $allowed))) {
                throw ValidationException::withMessages([$f => 'This message can’t fill {'.implode('}, {', $bad).'}. Use: {'.implode('}, {', $allowed).'}.']);
            }
        }
        foreach (['mail' => [$d['subject'], $d['body']], 'sms' => [null, (string) ($d['sms'] ?? '')]] as $channel => [$subject, $body]) {
            if ($body === '') {
                continue;
            }
            DB::table('group_message_wording')->updateOrInsert(['group_id' => $group->id, 'type' => $d['type'], 'channel' => $channel], ['subject' => $subject, 'body' => $body, 'updated_by' => $user->id, 'updated_at' => now(), 'created_at' => now()]);
        }
        activity('group')->causedBy($user)->performedOn($group)->event('wording-saved')->withProperties(['type' => $d['type']])->log("Reworded {$d['type']} in {$group->name}");

        return back()->with('status', 'wording-saved');
    }

    public function reset(Request $request, Group $group, string $type): RedirectResponse
    {
        $this->owner($request, $group);
        DB::table('group_message_wording')->where(['group_id' => $group->id, 'type' => $type])->delete();

        return back()->with('status', 'wording-reset');
    }

    /** Admin: allow texting clients (consent confirmation and status links) for an industry, with a note for the record. */
    public function texting(Request $request, Industry $industry): RedirectResponse
    {
        $d = $request->validate(['approved' => ['required', 'boolean'], 'note' => ['required_if:approved,true', 'nullable', 'string', 'max:500']], ['note.required_if' => 'Record who approved texting clients (e.g. counsel’s name), for compliance.']);
        $industry->forceFill(['client_texting_approved' => (bool) $d['approved']])->save();
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('client-texting')->withProperties(['approved' => (bool) $d['approved'], 'note' => $d['note'] ?? null])->log(($d['approved'] ? 'Approved' : 'Stopped').' texting clients in '.$industry->name);

        return back()->with('status', $d['approved'] ? 'texting-approved' : 'texting-stopped');
    }

    /** @return list<string> placeholders this message can fill */
    public static function allowed(string $type, Industry $industry): array
    {
        $def = NotificationCatalogue::get($type);
        preg_match_all('/\{([a-z_]+)\}/', $def['title'].' '.$def['body'].' '.$def['sms'], $m);

        return array_values(array_unique([...$m[1], ...array_keys($industry->mergeTerms()), 'first_name', 'url']));
    }

    private function owner(Request $request, Group $group): User
    {
        /** @var User $u */
        $u = $request->user();
        abort_unless($group->isOwnedBy($u) || $u->can('groups.manage'), 403);

        return $u;
    }
}
