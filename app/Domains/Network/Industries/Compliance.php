<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Models\PlatformFeeRule;
use App\Domains\Platform\Settings\Settings;
use DomainException;

/**
 * The compliance gate and the platform-fee lock. Every rule here is enforced in code, whatever a screen,
 * an import or an API call asks for.
 */
final class Compliance
{
    public function __construct(private readonly Settings $settings) {}

    public function approve(Industry $industry, User $by, string $note): void
    {
        $industry->forceFill(['compliance_approved_at' => now(), 'compliance_approved_by' => $by->id, 'compliance_note' => $note])->save();
        activity('admin')->causedBy($by)->performedOn($industry)->event('industry-approved')->withProperties(['tier' => $industry->tier->value, 'note' => $note])->log("Compliance-approved {$industry->name} (tier {$industry->tier->value})");
    }

    /** Revoking approval also takes the industry offline and switches off any platform fee. */
    public function revoke(Industry $industry, User $by, string $reason): void
    {
        $industry->forceFill(['compliance_approved_at' => null, 'compliance_approved_by' => null, 'status' => 'draft'])->save();
        $this->disableFees($industry);
        activity('admin')->causedBy($by)->performedOn($industry)->event('industry-approval-revoked')->withProperties(['reason' => $reason])->log("Revoked compliance approval for {$industry->name}");
    }

    /** A tier change invalidates the approval: counsel approved the industry under the old rules. */
    public function changeTier(Industry $industry, Tier $tier, User $by): void
    {
        if ($industry->tier === $tier) {
            return;
        }
        $old = $industry->tier;
        $industry->tier = $tier;
        $industry->save();
        if ($industry->isApproved()) {
            $this->revoke($industry, $by, "Tier changed from {$old->value} to {$tier->value}");
        }
        if (! $tier->allowsPlatformFee()) {
            $this->disableFees($industry);
        }
        activity('admin')->causedBy($by)->performedOn($industry)->event('industry-tier-changed')->withProperties(['old' => $old->value, 'new' => $tier->value])->log("Changed {$industry->name} to tier {$tier->value}");
    }

    public function goLive(Industry $industry, User $by): void
    {
        if (! $industry->isApproved()) {
            throw new DomainException('An industry can only go live after compliance approval.');
        }
        $industry->forceFill(['status' => 'live'])->save();
        activity('admin')->causedBy($by)->performedOn($industry)->event('industry-live')->log("{$industry->name} is live");
    }

    public function takeOffline(Industry $industry, User $by): void
    {
        $industry->forceFill(['status' => 'draft'])->save();
        activity('admin')->causedBy($by)->performedOn($industry)->event('industry-offline')->log("{$industry->name} taken offline");
    }

    public function feesUnlocked(): bool
    {
        return $this->settings->get('compliance.platform_fee_unlocked') === '1';
    }

    /** Super admin only: confirms licensing and fund routing are in place. Until then no fee can be switched on anywhere. */
    public function unlockFees(User $by, string $confirmation): void
    {
        $this->settings->set('compliance.platform_fee_unlocked', '1', userId: $by->id);
        activity('admin')->causedBy($by)->event('platform-fee-unlocked')->withProperties(['confirmation' => $confirmation])->log('Platform referral fees unlocked');
    }

    public function lockFees(User $by): void
    {
        $this->settings->set('compliance.platform_fee_unlocked', '0', userId: $by->id);
        PlatformFeeRule::query()->where('enabled', true)->update(['enabled' => false, 'enabled_at' => null]);
        activity('admin')->causedBy($by)->event('platform-fee-locked')->log('Platform referral fees locked; all fees switched off');
    }

    /** @param array{mode: string, percent?: ?float, flat_cents?: ?int, flat_by_temperature?: ?array<string, int>, charge_at?: string} $data */
    public function saveFeeRule(Industry $industry, ?int $groupId, array $data, bool $enable, User $by): PlatformFeeRule
    {
        if ($enable) {
            if (! $this->feesUnlocked()) {
                throw new DomainException('Platform fees are locked until licensing and fund routing are approved.');
            }
            if (! $industry->tier->allowsPlatformFee()) {
                throw new DomainException('Platform fees are never allowed for tier '.$industry->tier->value.' industries.');
            }
            if (! $industry->isApproved()) {
                throw new DomainException('Approve the industry for compliance before switching on a fee.');
            }
            if ($data['mode'] === 'none') {
                throw new DomainException('Choose a percentage or flat fee before switching it on.');
            }
        }
        $rule = PlatformFeeRule::query()->firstOrNew(['industry_id' => $industry->id, 'group_id' => $groupId]);
        $rule->fill($data);
        $rule->forceFill(['enabled' => $enable, 'enabled_at' => $enable ? ($rule->enabled_at ?? now()) : null, 'enabled_by' => $enable ? $by->id : null])->save();
        activity('admin')->causedBy($by)->performedOn($industry)->event('platform-fee-saved')->withProperties(['mode' => $rule->mode, 'enabled' => $enable, 'group' => $groupId])->log("Saved platform fee for {$industry->name}");

        return $rule;
    }

    private function disableFees(Industry $industry): void
    {
        PlatformFeeRule::query()->where('industry_id', $industry->id)->update(['enabled' => false, 'enabled_at' => null]);
    }
}
