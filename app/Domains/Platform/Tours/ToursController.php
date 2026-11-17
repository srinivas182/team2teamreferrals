<?php

declare(strict_types=1);

namespace App\Domains\Platform\Tours;

use App\Domains\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * First-run guided tours (Sprint 52a): finished or skipped tours aren't shown again; a person can replay them, or
 * turn tips off altogether.
 */
final class ToursController
{
    public const TOURS = ['member-home', 'admin-home'];

    /** @return array{done: list<string>, off: bool} */
    public static function state(User $user): array
    {
        // Read from the signed-in user already loaded (no extra query on every page).
        $attrs = $user->getAttributes();
        $done = $attrs['tours_done'] ?? null;

        return ['done' => array_values(array_intersect((array) (is_string($done) ? json_decode($done, true) : $done), self::TOURS)), 'off' => (bool) ($attrs['tips_off'] ?? false)];
    }

    public function done(Request $request, string $tour): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(in_array($tour, self::TOURS, true), 404);
        $done = self::state($user)['done'];
        $user->forceFill(['tours_done' => json_encode(array_values(array_unique([...$done, $tour])))])->save();

        return response()->json(['ok' => true]);
    }

    /** Show the tours again from the start (and turn tips back on). */
    public function replay(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $user->forceFill(['tours_done' => null, 'tips_off' => false])->save();

        return redirect($user->isStaff() && $request->input('where') === 'admin' ? '/admin' : '/app')->with('status', 'tour-replay');
    }

    public function tips(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $user->forceFill(['tips_off' => ! $request->boolean('on')])->save();

        return back()->with('status', $request->boolean('on') ? 'tips-on' : 'tips-off');
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
