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

    /** Free month when no referral reached a seat. Off for Tier C: fees there must not depend on referrals. */
    public function allowsValueGuarantee(): bool
    {
        return $this !== self::Directory;
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
            'valueGuarantee' => $this->allowsValueGuarantee(),
            'platformFee' => $this->allowsPlatformFee(),
            'disclosure' => $this->requiresDisclosure(),
        ];
    }
}
