<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

/**
 * One CRM. Every method takes the team's credentials and throws CrmException when the CRM says no.
 *
 * A contact is: first_name, last_name, email, phone, tags (list), source, message (the referral summary),
 * assign_user_id (optional, a CRM agent id).
 */
interface CrmProvider
{
    public function key(): string;

    public function label(): string;

    /** Where a member finds their key, in a sentence. */
    public function keyHelp(): string;

    /** Whether the platform side is set up (some CRMs need the platform registered with them). */
    public function ready(): bool;

    /**
     * Check the key works.
     *
     * @param  array<string, string>  $creds
     * @return array{account: string, users: list<array{id: string, name: string}>, stages: list<string>}
     */
    public function verify(array $creds): array;

    /**
     * Create the contact, or update the one with the same email or phone. Returns the CRM's id for it.
     *
     * @param  array<string, string>  $creds
     * @param  array<string, mixed>  $contact
     */
    public function pushContact(array $creds, array $contact, ?string $existingId): string;

    /** @param array<string, string> $creds */
    public function addNote(array $creds, string $remoteId, string $text): void;

    /**
     * The contact's current stage in the CRM.
     *
     * @param  array<string, string>  $creds
     */
    public function stageOf(array $creds, string $remoteId): ?string;
}
