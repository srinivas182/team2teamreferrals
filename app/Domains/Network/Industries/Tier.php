<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries;

/**
 * Compliance tiers. Everything that differs by tier is decided here, in one place, so no screen
 * or job can accidentally let a restricted industry do something it must not.
 */
enum Tier: string
{
    case Open = 'A';
    case Restricted = 'B';
    case Directory = 'C';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'A · Open',
            self::Restricted => 'B · Restricted',
            self::Directory => 'C · Directory only',
        };
    }

    /** Members may agree referral fees with each other (paid business to business, off-platform). */
    public function allowsMemberReferralFees(): bool
    {
        return $this === self::Open;
    }

    /** Referrals are routed to members at all (Tier C is a listing with contact only). */
    public function allowsReferralRouting(): bool
    {
        return $this !== self::Directory;
    }

    /** "Request a pro" form for consumers on public pages. */
    public function allowsConsumerRequests(): bool
    {
        return $this !== self::Directory;
    }

    /**
     * The sharing reward (a free month for sending a good referral) is something of value given for a referral, so
     * only Tier A allows it: Tier B forbids anything of value for referrals, Tier C needs fees that don't depend on them.
     */
    public function allowsSharingReward(): bool
    {
        return $this === self::Open;
    }

    /** A per-referral platform fee can only ever exist for Tier A. */
    public function allowsPlatformFee(): bool
    {
        return $this === self::Open;
    }

    public function requiresDisclosure(): bool
    {
        return $this !== self::Open;
    }

    /** @return array<string, bool> for screens and the API */
    public function rules(): array
    {
        return [
            'memberReferralFees' => $this->allowsMemberReferralFees(),
            'referralRouting' => $this->allowsReferralRouting(),
            'consumerRequests' => $this->allowsConsumerRequests(),
            'sharingReward' => $this->allowsSharingReward(),
            'platformFee' => $this->allowsPlatformFee(),
            'disclosure' => $this->requiresDisclosure(),
        ];
    }
}
