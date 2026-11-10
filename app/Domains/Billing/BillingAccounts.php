<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Companies\Company;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Payments\Stripe;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Billing accounts: the payer of each seat (the holding team, or its company when the company pays centrally),
 * its Stripe customer, and the saved card. Card details are entered in Stripe's own form and never reach us.
 */
final class BillingAccounts
{
    public function __construct(private readonly Stripe $stripe, private readonly Notifier $notifier) {}

    /** @return array{type: string, id: int}|null */
    public static function payerFor(Seat $seat): ?array
    {
        $holder = $seat->primary;
        if ($holder === null) {
            return null;
        }

        return $seat->payer === 'company' && $holder->company_id !== null ? ['type' => 'company', 'id' => $holder->company_id] : ['type' => 'team', 'id' => $holder->id];
    }

    public static function account(string $type, int $id): stdClass
    {
        DB::table('billing_customers')->insertOrIgnore(['owner_type' => $type, 'owner_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        /** @var stdClass $row */
        $row = DB::table('billing_customers')->where(['owner_type' => $type, 'owner_id' => $id])->first();

        return $row;
    }

    /** Who may manage this payer's billing: team leader or assistant; for a company, its admin. */
    public static function canManage(User $user, string $type, int $id): bool
    {
        if ($type === 'company') {
            return Company::query()->whereKey($id)->value('admin_id') === $user->id;
        }
        $team = Team::query()->find($id);

        return $team !== null && in_array($team->roleOf($user), ['leader', 'assistant'], true);
    }

    /** @return list<User> who hears about this payer's billing */
    public static function contacts(string $type, int $id): array
    {
        $u = $type === 'company' ? Company::query()->find($id)?->admin : Team::query()->find($id)?->leader;

        return $u !== null ? [$u] : [];
    }

    public function setupIntent(string $type, int $id): string
    {
        $account = $this->customer($type, $id);

        return (string) $this->stripe->api('POST', 'setup_intents', ['customer' => $account->stripe_customer_id, 'usage' => 'off_session', 'automatic_payment_methods[enabled]' => 'true'])['client_secret'];
    }

    /** Save the card Stripe confirmed, after checking it belongs to this payer's Stripe customer. */
    public function saveCard(string $type, int $id, string $paymentMethodId): void
    {
        $account = $this->customer($type, $id);
        $pm = $this->stripe->api('GET', 'payment_methods/'.$paymentMethodId);
        if (($pm['customer'] ?? null) !== $account->stripe_customer_id) {
            throw ValidationException::withMessages(['card' => 'That card isn’t linked to this account. Please add it again.']);
        }
        $this->stripe->api('POST', 'customers/'.$account->stripe_customer_id, ['invoice_settings[default_payment_method]' => $paymentMethodId]);
        DB::table('billing_customers')->where('id', $account->id)->update([
            'payment_method_id' => $paymentMethodId, 'card_brand' => data_get($pm, 'card.brand'), 'card_last4' => data_get($pm, 'card.last4'),
            'card_exp_month' => data_get($pm, 'card.exp_month'), 'card_exp_year' => data_get($pm, 'card.exp_year'), 'needs_new_card' => false, 'updated_at' => now(),
        ]);
    }

    /** Daily: tell payers 14, 7 and 1 days before a seat's free trial ends (and whether a card is on file). */
    public function trialNotices(): void
    {
        $seats = Seat::query()->with(['group', 'primary'])->whereNotNull('primary_team_id')->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(15)])->get();
        foreach ($seats as $seat) {
            $days = (int) ceil(now()->diffInHours($seat->trial_ends_at) / 24);
            $kind = match (true) {
                $days <= 1 => 'trial_1',
                $days <= 7 => 'trial_7',
                $days <= 14 => 'trial_14',
                default => null,
            };
            $payer = self::payerFor($seat);
            if ($kind === null || $payer === null || DB::table('billing_notices')->insertOrIgnore(['seat_id' => $seat->id, 'kind' => $kind, 'sent_at' => now()]) !== 1) {
                continue;
            }
            $hasCard = DB::table('billing_customers')->where(['owner_type' => $payer['type'], 'owner_id' => $payer['id']])->whereNotNull('payment_method_id')->exists();
            $when = $seat->trial_ends_at?->toFormattedDateString();
            $price = '$'.number_format((float) data_get($seat->group->pricing, 'seat_price', 10), 2);
            $body = $hasCard ? "Your card on file will be charged {$price} a month from then. Any month no referral is offered to your seat is free."
                : "Add a card before then to keep receiving referrals. It’s {$price} a month, and any month no referral is offered to your seat is free.";
            $recipients = self::contacts($payer['type'], $payer['id']);
            if ($payer['type'] === 'company' && $seat->primary !== null) {
                $recipients[] = $seat->primary->leader; // the city leader hears too
            }
            foreach (collect($recipients)->unique('id') as $u) {
                $this->notifier->send($u, 'seat.payment', ['group' => $seat->group->name, 'headline' => "Free trial for {$seat->territory_name} ends {$when}", 'body' => $body], url('/app/billing'), $seat->group->industry, $seat->group_id);
            }
        }
    }

    private function customer(string $type, int $id): stdClass
    {
        $account = self::account($type, $id);
        if ($account->stripe_customer_id === null) {
            $owner = $type === 'company' ? Company::query()->findOrFail($id) : Team::query()->findOrFail($id);
            $contact = self::contacts($type, $id)[0] ?? null;
            $c = $this->stripe->api('POST', 'customers', ['name' => $owner->name, 'email' => $contact?->email, 'metadata[owner_type]' => $type, 'metadata[owner_id]' => $id], "customer-{$type}-{$id}");
            DB::table('billing_customers')->where('id', $account->id)->update(['stripe_customer_id' => $c['id'], 'updated_at' => now()]);
            $account->stripe_customer_id = (string) $c['id'];
        }

        return $account;
    }
}
