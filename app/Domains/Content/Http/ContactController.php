<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Seo\SeoData;
use App\Support\FormGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The Contact form (guarded like the profile lead form) and its admin inbox. */
final class ContactController
{
    public const TOPICS = ['general' => 'General question', 'joining' => 'Joining a network', 'billing' => 'Billing', 'press' => 'Press', 'partnership' => 'Partnerships'];

    public function send(Request $request, Notifier $notifier): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190'], 'topic' => ['required', 'in:'.implode(',', array_keys(self::TOPICS))],
            'message' => ['required', 'string', 'min:20', 'max:4000'], 'started' => ['required', 'string']]);
        FormGuard::check($request, 'message');
        DB::table('contact_messages')->insert(['name' => $d['name'], 'email' => strtolower((string) $d['email']), 'topic' => $d['topic'], 'message' => $d['message'],
            'ip_hash' => FormGuard::ipHash($request), 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::permission(['support.manage', 'content.manage'])->get()->merge(User::role(Permissions::SUPER_ADMIN)->get())->unique('id');
        foreach ($staff as $u) {
            $notifier->send($u, 'admin.contact', ['headline' => 'New message: '.self::TOPICS[$d['topic']], 'body' => $d['name'].' wrote through the Contact page.'], url('/admin/content/messages'));
        }

        return back()->with('status', 'contact-sent');
    }

    public function inbox(): Response
    {
        return Inertia::render('admin/content/Messages', [
            'messages' => DB::table('contact_messages')->orderByRaw("CASE WHEN status = 'new' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(200)
                ->get(['id', 'name', 'email', 'topic', 'message', 'status', 'created_at'])->map(fn ($m) => [...(array) $m, 'topic' => self::TOPICS[$m->topic] ?? $m->topic])->values(),
            'status' => session('status'), 'seo' => SeoData::private('Messages')->toArray(),
        ]);
    }

    public function handled(Request $request, int $message): RedirectResponse
    {
        DB::table('contact_messages')->where('id', $message)->update(['status' => 'handled', 'handled_by' => $request->user()?->id, 'updated_at' => now()]);

        return back()->with('status', 'message-handled');
    }
}
