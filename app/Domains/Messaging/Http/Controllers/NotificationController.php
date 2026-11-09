<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\NotificationCatalogue;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationController
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $query = $user->notifications()->latest();
        if ($request->query('filter') === 'unread') {
            $query->whereNull('read_at');
        }
        $page = $query->paginate(30)->withQueryString();

        return Inertia::render('app/Notifications', [
            'items' => collect($page->items())->map(fn (DatabaseNotification $n) => self::present($n)),
            'filter' => $request->query('filter') === 'unread' ? 'unread' : 'all',
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage()],
            'seo' => SeoData::private('Notifications')->toArray(),
        ]);
    }

    public function read(Request $request, string $id): RedirectResponse
    {
        $n = $this->user($request)->notifications()->findOrFail($id);
        $n->markAsRead();
        $url = data_get($n->data, 'url');

        return is_string($url) && str_starts_with($url, url('/')) ? redirect()->to($url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    /** @return array{id: string, type: string, group: string, title: string, body: string, url: ?string, read: bool, ago: string} */
    public static function present(DatabaseNotification $n): array
    {
        /** @var array{title?: string, body?: string, url?: ?string} $d */
        $d = $n->data;

        return [
            'id' => (string) $n->id,
            'type' => (string) $n->type,
            'group' => NotificationCatalogue::TYPES[$n->type]['group'] ?? 'Other',
            'title' => (string) ($d['title'] ?? ''),
            'body' => (string) ($d['body'] ?? ''),
            'url' => $d['url'] ?? null,
            'read' => $n->read_at !== null,
            'ago' => $n->created_at?->diffForHumans() ?? '',
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
