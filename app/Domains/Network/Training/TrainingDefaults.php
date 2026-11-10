<?php

declare(strict_types=1);

namespace App\Domains\Network\Training;

use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;

/** Starting training in each industry's own words. Admins edit it per industry; this is what every industry begins with. */
final class TrainingDefaults
{
    /** @return array{lessons: list<array{title: string, minutes: int, body: string, video_url: ?string, transcript: ?string}>, questions: list<array{q: string, options: list<string>, answer: int, why: string}>} */
    public static function for(Industry $industry): array
    {
        $t = $industry->terms();
        $ref = $t['referral'];
        $fees = $industry->tier === Tier::Open;
        $l = fn (string $title, int $min, string $body) => ['title' => $title, 'minutes' => $min, 'body' => $body, 'video_url' => null, 'transcript' => null];

        $lessons = [
            $l('How the network works', 3, "Each {$t['pro']} holds one territory. When your {$t['client']} needs help somewhere else, you send a {$ref} to the verified {$t['pro']} there, and every step is tracked."),
            $l('Client consent comes first', 2, "Only send a {$ref} when your {$t['client']} has agreed to be contacted. Their details are shared with the receiving {$t['pro']} only, never shown publicly."),
            $l('Deadlines that keep clients happy', 3, "Accept or decline a {$ref} within the group’s deadline (usually 24 hours), make first contact within the deadline, and post an update at least every 7 days. Missed deadlines lower your trust score and can pass the {$ref} to your backup."),
            $l('Agreements and money', 3, $fees
                ? "Before work starts, both sides sign the network’s approved agreement. Any {$t['fee']} is paid business to business, as the agreement says; Team to Team never holds it."
                : "In this industry no fee, commission or other payment may pass between members for an {$ref}. Never offer or accept one. Reciprocity credits on the platform have no cash value."),
            $l('Keeping your seat', 2, 'Seats are earned: keep your license current, respond on time and close the loop. Holders with a low trust score for 60 days can be challenged by the top waitlisted team.'),
        ];
        $questions = [
            ['q' => "When may you send a {$ref}?", 'options' => ["As soon as you have the {$t['client']}’s phone number", "Only after the {$t['client']} agrees to be contacted", 'After the receiving pro agrees to pay you'], 'answer' => 1, 'why' => "Consent comes first: the {$t['client']} must agree before their details are shared."],
            ['q' => "A {$ref} arrives for your territory. What’s the usual deadline to accept or decline?", 'options' => ['24 hours', '7 days', 'There is no deadline'], 'answer' => 0, 'why' => 'Most groups ask for a decision within 24 hours, so the client isn’t left waiting.'],
            ['q' => 'How often should you post an update on an active referral?', 'options' => ['Only when it closes', 'At least every 7 days', 'Once a month'], 'answer' => 1, 'why' => 'Regular updates keep the sender informed and protect your trust score.'],
            $fees
                ? ['q' => "Who holds the {$t['fee']}?", 'options' => ['Team to Team, until the deal closes', 'Nobody: it’s paid business to business under the agreement', 'The client'], 'answer' => 1, 'why' => 'Fees are paid directly between the parties’ businesses, as the signed agreement says.']
                : ['q' => "May a member pay you for an {$ref} in this industry?", 'options' => ['Yes, if it’s small', 'Yes, as a gift card', 'No, never'], 'answer' => 2, 'why' => 'Payments for introductions are not allowed in this industry, in any form.'],
            ['q' => 'What happens if your trust score stays low for 60 days?', 'options' => ['Nothing', 'Your seat can be challenged by the top waitlisted team', 'Your account is deleted'], 'answer' => 1, 'why' => 'Seats are earned: a long-term low score lets the next team in line challenge for the seat.'],
        ];

        return ['lessons' => $lessons, 'questions' => $questions];
    }
}
