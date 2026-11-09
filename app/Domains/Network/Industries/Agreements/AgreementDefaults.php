<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Agreements;

use App\Domains\Network\Industries\Tier;

/**
 * Starting wording per tier. Every default must still be reviewed and approved for each industry
 * before members can sign it. Tier C (directory only) has no agreement: just client consent.
 *
 * Clause: key, title, text, required (cannot be removed), optional (in the library, can be switched off), enabled, fee (contains fee terms).
 */
final class AgreementDefaults
{
    /** @return list<array{key: string, title: string, text: string, required: bool, optional: bool, enabled: bool, fee: bool}> */
    public static function for(Tier $tier): array
    {
        $c = fn (string $key, string $title, string $text, bool $required = true, bool $fee = false, bool $optional = false, bool $enabled = true) => compact('key', 'title', 'text', 'required', 'optional', 'enabled', 'fee');

        $parties = $c('parties', 'Parties', 'This agreement is between {sender_team} ({sender_name}, license {sender_license}, {sender_state}) as the referring party, and {receiver_team} ({receiver_name}, license {receiver_license}, {receiver_state}) as the receiving party, dated {date}.');
        $client = $c('client', 'Client', 'The referring party introduces {client} ({client_term}), who is looking for help in {city}. The client has agreed to be contacted by the receiving party.');
        $confidentiality = $c('confidentiality', 'Confidentiality', 'Both parties will keep the client’s details confidential and use them only to serve the client.', false, false, true);
        $mediation = $c('mediation', 'Disputes', 'The parties will first try to resolve any dispute through good-faith discussion, then mediation, before any other action.', false, false, true);
        $nonSolicit = $c('non_solicitation', 'Non-solicitation', 'The receiving party will not market other services to the client on behalf of competitors of the referring party.', false, false, true, false);
        $cancel = $c('cancellation', 'Cancellation', 'Either party may cancel this agreement in writing before the client signs with the receiving party.', false, false, true, false);
        $law = $c('governing_law', 'Governing law', 'This agreement is governed by the laws of {governing_state}.');
        $sign = $c('signatures', 'Signatures', 'Signed electronically by both parties. Electronic signatures have the same effect as handwritten ones.');

        return match ($tier) {
            Tier::Open => [
                $parties, $client,
                $c('fee', 'Referral fee', 'If the client completes a transaction with the receiving party within {protection_months} months of this agreement, the receiving party will pay the referring party {fee_terms}, within {fee_due_days} days of closing.', true, true),
                $c('protection', 'Protection period', 'This agreement covers the client for {protection_months} months from the date above, including transactions started during that period.', false, true, true),
                $c('payment_method', 'How the fee is paid', 'The fee is paid directly between the parties’ brokerages or businesses, as the law requires. Team to Team does not collect or hold it.', true, true),
                $confidentiality, $mediation, $nonSolicit, $cancel, $law, $sign,
            ],
            Tier::Restricted => [
                $parties, $client,
                $c('no_fee', 'No referral fee', 'No fee, commission or other payment is due from either party for this introduction. Each party may earn reciprocity credits on Team to Team, which carry no cash value.'),
                $c('consent', 'Client consent', 'The client agreed to be introduced and can withdraw consent at any time.'),
                $confidentiality, $mediation, $cancel, $law, $sign,
            ],
            Tier::Directory => [],
        };
    }

    /** Merge fields an agreement may use. Fee fields are rejected outside Tier A. */
    public const FIELDS = [
        'sender_team' => 'Referring team', 'sender_name' => 'Referring person', 'sender_license' => 'Referring license number', 'sender_state' => 'Referring state',
        'receiver_team' => 'Receiving team', 'receiver_name' => 'Receiving person', 'receiver_license' => 'Receiving license number', 'receiver_state' => 'Receiving state',
        'client' => 'Client name', 'client_term' => 'What the client is called', 'pro' => 'What a pro is called', 'city' => 'City', 'date' => 'Date',
        'governing_state' => 'Governing state', 'protection_months' => 'Protection period (months)',
        'fee_terms' => 'Fee (e.g. 25% of the commission)', 'fee_due_days' => 'Days to pay after closing',
    ];

    public const FEE_FIELDS = ['fee_terms', 'fee_due_days'];

    /**
     * Sample values for previews and the counsel pack.
     *
     * @return array<string, string>
     */
    public static function sample(): array
    {
        return [
            'sender_team' => 'Red Rock Realty Group', 'sender_name' => 'Jordan Hale', 'sender_license' => 'UT 5512345', 'sender_state' => 'Utah',
            'receiver_team' => 'Bayou City Homes', 'receiver_name' => 'Chris Delgado', 'receiver_license' => 'TX 0654321', 'receiver_state' => 'Texas',
            'client' => 'Tom Brennan', 'client_term' => 'buyer', 'pro' => 'agent', 'city' => 'Houston, TX', 'date' => 'November 10, 2026',
            'governing_state' => 'Texas', 'protection_months' => '12', 'fee_terms' => '25% of the commission received', 'fee_due_days' => '10',
        ];
    }
}
