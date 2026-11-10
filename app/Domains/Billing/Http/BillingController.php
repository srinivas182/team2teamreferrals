<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http;

use App\Domains\Billing\BillingAccounts;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Companies\Company;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Portal → Billing: each account this person pays for (their team, and their company if they run one). */
final class BillingController
{
    public function __construct(private readonly BillingAccounts $accounts) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $team = Team::of($user);
        $company = Company::administeredBy($user);
        $accounts = array_values(array_filter([
            $team !== null && BillingAccounts::canManage($user, 'team', $team->id) ? $this->describe('team', $team->id, $team->name) : null,
            $company !== null ? $this->describe('company', $company->id, $company->name) : null,
        ]));

        return Inertia::render('app/Billing', [
            'accounts' => $accounts,
            'stripeKey' => app(\App\Domains\Platform\Payments\Stripe::class)->connected() ? app(\App\Domains\Platform\Payments\Stripe::class)->publishableKey() : null,
            'status' => session('status'),
            'seo' => SeoData::private('Billing')->toArray(),
        ]);
    }

    public function setupIntent(Request $request, string $type): JsonResponse
    {
        $id = $this->payerId($request, $type);

        return response()->json(['clientSecret' => $this->accounts->setupIntent($type, $id)]);
    }

    public function saveCard(Request $request, string $type): RedirectResponse
    {
        $id = $this->payerId($request, $type);
        $this->accounts->saveCard($type, $id, (string) $request->validate(['payment_method' => ['required', 'string', 'starts_with:pm_', 'max:64']])['payment_method']);

        return back()->with('status', 'card-saved');
    }

    /** @return array<string, mixed> */
    private function describe(string $type, int $id, string $name): array
    {
        $acc = DB::table('billing_customers')->where(['owner_type' => $type, 'owner_id' => $id])->first();
        $seats = Seat::query()->with(['group', 'primary'])->whereNotNull('primary_team_id')->get()
            ->filter(fn (Seat $s) => BillingAccounts::payerFor($s) === ['type' => $type, 'id' => $id]);

        return [
            'type' => $type, 'name' => $name,
            'card' => $acc && $acc->payment_method_id ? ['brand' => $acc->card_brand, 'last4' => $acc->card_last4, 'exp' => sprintf('%02d/%d', $acc->card_exp_month, $acc->card_exp_year)] : null,
            'seats' => $seats->map(fn (Seat $s) => ['territory' => $s->territory_name, 'group' => $s->group->name, 'team' => (string) $s->primary?->name, 'status' => $s->status, 'founding' => $s->founding,
                'price' => (float) data_get($s->group->pricing, 'seat_price', 10), 'trialEnds' => $s->trial_ends_at?->toDateString()])->values(),
        ];
    }

    private function payerId(Request $request, string $type): int
    {
        $user = $this->user($request);
        $id = match ($type) {
            'team' => Team::of($user)?->id,
            'company' => Company::administeredBy($user)?->id,
            default => null,
        };
        abort_unless($id !== null && BillingAccounts::canManage($user, $type, $id), 403);
        abort_unless(app(\App\Domains\Platform\Payments\Stripe::class)->connected(), 503, 'Payments aren’t set up yet.');

        return $id;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
