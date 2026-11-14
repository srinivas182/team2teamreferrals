<?php

declare(strict_types=1);

namespace App\Domains\Content\Industry;

/**
 * Drafted hub content for the launch industries (Sprint 39a: six written in full as samples; 39b and 39c add the rest).
 * Every outside fact is paraphrased from a listed source, checked on the date given. Where no reliable public figure
 * exists, the cost line is left empty rather than estimated. All of it installs as a draft with "Review pending":
 * nothing is public until staff name the reviewer who checked it.
 */
final class IndustryPack
{
    public const CHECKED = '2026-11-14';

    /** @return array<string, array<string, mixed>> industry slug => content (see IndustryContent::PARTS) */
    public static function all(): array
    {
        return [...self::sample(), ...IndustryPackB::all(), ...IndustryPackC::all()];
    }

    /** @return array<string, array<string, mixed>> the six samples written in Sprint 39a */
    private static function sample(): array
    {
        return [
            'real-estate' => [
                'summary' => 'Real estate referrals connect a buyer or seller who is moving to a licensed agent in their new city. On {site_name}, agents send clients with their consent, the receiving agent accepts within the deadline, and any referral fee is agreed up front as a share of the receiving agent’s commission.',
                'intro' => 'When a client relocates, the agent they trust usually can’t help them buy or sell in the new city: real estate licenses are issued state by state. A good referral keeps the client in safe hands and keeps the referring agent involved. The {industry} network gives one verified agent per city a seat, so every introduction goes to someone with a track record of answering quickly, keeping you informed and closing.',
                'how' => 'Send a buyer or seller to the agent who holds the seat in their new city. The receiving agent sees what the client needs (but not their contact details) and accepts or declines within the network’s deadline. Once accepted, they contact the client, post updates on a shared timeline, and mark the referral won or lost when it ends. Where you agree a referral fee, it is written into the referral agreement before you send the client.',
                'request' => 'Moving to a new city? Tell us where and what you need, and we’ll introduce you to the verified agent who serves it.',
                'state_summary' => 'Find a verified real estate agent anywhere in {state}. Agents on {site_name} hold an active {state} license, and each city’s seat goes to the agent with the best record of serving referred buyers and sellers.',
                'city_summary' => 'Buying or selling in {city}, {state}? {site_name} introduces you to the verified local agent who holds the {city} seat, chosen for how quickly they respond and how well they serve referred clients.',
                'facts' => [
                    'covers' => 'Residential real estate agents and brokers who help clients buy, sell or rent homes, condos and townhomes.',
                    'cost' => 'There is no standard commission: since August 17, 2024, an agent working with a buyer through an MLS must have a written agreement stating their compensation, and it must say that commissions are not set by law and are fully negotiable.',
                    'licensing' => 'Agents and brokers are licensed by each state’s real estate commission; an agent can only represent clients in states where they hold a license.',
                    'fees' => 'Agent-to-agent referral fees are allowed under federal rules (RESPA’s exception for cooperative brokerage and referral arrangements) and are usually a negotiated share of the receiving agent’s commission, paid broker to broker.',
                ],
                'cost_note' => 'Commissions are negotiated case by case, so we don’t publish a typical rate.',
                'lookups' => [['name' => 'ARELLO license verification (most states)', 'url' => 'https://www.arello.com/']],
                'hire_questions' => [
                    'Are you licensed in this state, and with which brokerage?',
                    'How many buyers or sellers have you helped in this area in the last year?',
                    'What will you charge, and what exactly does it cover?',
                    'How and how often will you update me?',
                    'Who else on your team will I work with?',
                    'How long does your buyer or listing agreement last, and how can I end it?',
                ],
                'faqs' => [
                    ['q' => 'What is a real estate referral fee?', 'a' => 'A payment from the receiving agent’s brokerage to the referring agent’s brokerage when the referred client closes. It is a share of the receiving agent’s commission, agreed before the client is sent; the client does not pay it.'],
                    ['q' => 'How much is a typical real estate referral fee?', 'a' => 'It is negotiable. A share of about a quarter of the receiving agent’s commission is commonly reported, with higher and lower figures agreed case by case.'],
                    ['q' => 'Is it legal to pay a real estate referral fee?', 'a' => 'Between licensed agents and brokers, yes: federal rules under RESPA allow cooperative brokerage and referral arrangements between real estate agents and brokers. State license law usually requires payment to go to a licensed broker.'],
                    ['q' => 'Do I need a license to receive a referral fee?', 'a' => 'In most states, yes. Fees are generally paid broker to broker, to licensed agents. Check your state’s rules and your broker’s policy.'],
                    ['q' => 'Does a referral cost the client anything?', 'a' => 'No. The fee comes out of the receiving agent’s commission. The client agrees the agent’s compensation directly with the agent they work with.'],
                    ['q' => 'What changed for buyers in August 2024?', 'a' => 'Agents working with buyers through an MLS must now have a written agreement before touring a home, stating what they will be paid and that commissions are not set by law and are fully negotiable.'],
                    ['q' => 'How quickly will the receiving agent respond?', 'a' => 'Each network sets a deadline to accept or decline, and another for first contact with the client. Reminders arrive before deadlines, and response speed counts toward the agent’s trust score.'],
                    ['q' => 'Can I see how my referral is going?', 'a' => 'Yes. Every referral has a shared timeline with the receiving agent’s updates, so you can follow it from first contact to closing without chasing.'],
                ],
                'glossary' => [
                    ['term' => 'Referral fee', 'definition' => 'A share of the receiving agent’s commission paid to the referring agent’s broker when the referred client closes.'],
                    ['term' => 'Written buyer agreement', 'definition' => 'A signed agreement between a buyer and their agent setting out the agent’s compensation, required before touring homes through an MLS since August 2024.'],
                    ['term' => 'Broker', 'definition' => 'A licensed real estate professional who can run a brokerage and supervise agents; referral fees are usually paid between brokers.'],
                    ['term' => 'MLS', 'definition' => 'Multiple Listing Service: the shared database of homes for sale that local agents use.'],
                    ['term' => 'RESPA', 'definition' => 'The federal Real Estate Settlement Procedures Act, which limits payments for referrals in home purchases but allows agent-to-agent referral arrangements.'],
                ],
                'related' => ['mortgage', 'title-escrow', 'home-inspection', 'moving', 'insurance'],
                'sources' => [
                    ['title' => 'Written buyer agreements 101 (National Association of REALTORS)', 'url' => 'https://www.nar.realtor/the-facts/written-buyer-agreements-101'],
                    ['title' => 'Regulation X §1024.14 (Consumer Financial Protection Bureau)', 'url' => 'https://www.consumerfinance.gov/rules-policy/regulations/1024/14/'],
                    ['title' => 'Real Estate Referral Fees report, September 2020 (Consumer Federation of America)', 'url' => 'https://consumerfed.org/wp-content/uploads/2020/09/Real-Estate-Referral-Fees-Report-9-21-20.pdf'],
                ],
                'checked' => self::CHECKED,
            ],

            'mortgage' => [
                'summary' => 'Mortgage introductions connect a borrower to a loan officer licensed in the state where they are buying or refinancing. On {site_name}, loan officers introduce borrowers with their consent and track each introduction, and no referral fees or anything else of value are exchanged, as federal rules require.',
                'intro' => 'A borrower buying in another state needs a loan officer licensed there. Rather than leaving them to search online, a loan officer they already trust can introduce them to a verified colleague in that state. The {industry} network makes those introductions reliable: one licensed loan officer per city, held to clear deadlines and updates, with no payments for referrals of any kind.',
                'how' => 'Introduce a borrower to the loan officer who holds the seat where they are buying. The receiving loan officer accepts or declines within the deadline, contacts the borrower, and keeps you updated on the timeline. Introductions are exchanged without payment: members earn credits and recognition, which have no cash value.',
                'request' => 'Buying or refinancing in another state? Tell us where, and we’ll introduce you to a verified loan officer licensed there.',
                'state_summary' => 'Find a loan officer licensed in {state}. Every loan officer on {site_name} is listed in NMLS Consumer Access, and introductions are made without any referral payment.',
                'city_summary' => 'Need a mortgage in {city}, {state}? {site_name} introduces you to the verified loan officer who holds the {city} seat, licensed in {state} and chosen for how well they serve introduced borrowers.',
                'facts' => [
                    'covers' => 'Mortgage loan officers and lenders helping borrowers buy, refinance or borrow against a home.',
                    'cost' => 'Borrowers paid median closing costs of nearly $6,000 on home purchase loans in 2022, according to the CFPB.',
                    'licensing' => 'Mortgage loan originators must be registered or licensed through the NMLS and licensed in each state where they originate loans; their record is public in NMLS Consumer Access.',
                    'fees' => 'Under RESPA Section 8 and Regulation X, no one may give or accept a fee or anything of value for referring settlement-service business on a federally related mortgage loan.',
                ],
                'cost_note' => 'Closing costs vary widely by loan, lender and state; the CFPB figure is a national median for 2022 home purchase loans.',
                'lookups' => [['name' => 'NMLS Consumer Access', 'url' => 'https://www.nmlsconsumeraccess.org/']],
                'hire_questions' => [
                    'What is your NMLS ID, and are you licensed in my state?',
                    'Which loan types do you offer for my situation?',
                    'Can I have a Loan Estimate so I can compare offers?',
                    'What are your lender fees and points?',
                    'How long do you expect closing to take?',
                    'How will you keep me updated?',
                ],
                'faqs' => [
                    ['q' => 'Can a loan officer pay a referral fee?', 'a' => 'No. RESPA Section 8 and Regulation X prohibit giving or accepting any fee or thing of value for referring settlement-service business on a federally related mortgage loan.'],
                    ['q' => 'What counts as a “thing of value”?', 'a' => 'Almost anything: money, discounts, gifts, trips, paying someone’s expenses or special prices. There is no exception for small amounts when it is given for referrals.'],
                    ['q' => 'Why introduce borrowers if no fee is allowed?', 'a' => 'Because the borrower gets a licensed professional you trust. On {site_name}, members earn credits and recognition that have no cash value.'],
                    ['q' => 'How do I check a loan officer’s license?', 'a' => 'Search their name or NMLS ID in NMLS Consumer Access, which shows where they are licensed and their employer.'],
                    ['q' => 'What are typical mortgage closing costs?', 'a' => 'They vary by loan and state. The CFPB reported median closing costs of nearly $6,000 on 2022 home purchase loans.'],
                    ['q' => 'Is the borrower’s information kept private?', 'a' => 'Yes. The receiving loan officer sees what the borrower needs but not their contact details until they accept, and only borrowers who agreed are introduced.'],
                    ['q' => 'How quickly will the receiving loan officer respond?', 'a' => 'The network sets deadlines to accept and to make first contact, with reminders before they pass.'],
                    ['q' => 'Can I follow the introduction after I send it?', 'a' => 'Yes. The receiving loan officer posts updates on a shared timeline until the loan closes or the borrower decides not to proceed.'],
                ],
                'glossary' => [
                    ['term' => 'NMLS ID', 'definition' => 'The unique number every mortgage loan originator receives from the Nationwide Multistate Licensing System.'],
                    ['term' => 'Loan Estimate', 'definition' => 'A standard three-page form lenders give after an application, showing the loan’s rate, payments and closing costs.'],
                    ['term' => 'Closing costs', 'definition' => 'Fees paid to complete a mortgage, such as lender charges, appraisal, title and recording fees.'],
                    ['term' => 'RESPA Section 8', 'definition' => 'The federal rule that prohibits payments for referrals of settlement-service business on federally related mortgages.'],
                    ['term' => 'Discount points', 'definition' => 'An upfront payment to the lender in exchange for a lower interest rate.'],
                ],
                'related' => ['real-estate', 'title-escrow', 'insurance', 'home-inspection'],
                'sources' => [
                    ['title' => 'Regulation X §1024.14 (Consumer Financial Protection Bureau)', 'url' => 'https://www.consumerfinance.gov/rules-policy/regulations/1024/14/'],
                    ['title' => 'Junk fees are driving up housing costs, March 2024 (Consumer Financial Protection Bureau)', 'url' => 'https://www.consumerfinance.gov/about-us/blog/junk-fees-are-driving-up-housing-costs-the-cfpb-wants-to-hear-from-you'],
                    ['title' => 'NMLS Consumer Access', 'url' => 'https://www.nmlsconsumeraccess.org/'],
                ],
                'checked' => self::CHECKED,
            ],

            'insurance' => [
                'summary' => 'Insurance introductions connect a client who is moving, or needs cover in another state, to an agent licensed there. On {site_name}, agents introduce clients with their consent and track each introduction, without referral fees, since insurance commissions may only be paid to licensed agents.',
                'intro' => 'Insurance agents are licensed by each state, so a client who moves often needs a new agent for home, auto, life or health cover. The {industry} network gives one verified, licensed agent per city a seat, so the agent your client already trusts can introduce them to someone reliable, and both can see the introduction through.',
                'how' => 'Introduce a client to the agent who holds the seat where they now live. The receiving agent accepts within the deadline, contacts the client and keeps you updated. Introductions are exchanged without payment; members earn credits and recognition with no cash value.',
                'request' => 'Moving, or need cover in another state? Tell us where, and we’ll introduce you to a verified agent licensed there.',
                'state_summary' => 'Find a licensed insurance agent in {state} for home, auto, life or health cover. Agents on {site_name} hold an active {state} license, and introductions are made without referral payments.',
                'city_summary' => 'Need insurance in {city}, {state}? {site_name} introduces you to the verified local agent who holds the {city} seat, licensed in {state}.',
                'facts' => [
                    'covers' => 'Licensed agents and brokers selling home, auto, life and health insurance to individuals and families.',
                    'cost' => '',
                    'licensing' => 'Insurance agents (producers) are licensed by each state’s department of insurance for each line they sell; the NAIC lists every state department.',
                    'fees' => 'States generally allow insurance commissions to be paid only to licensed agents, and rules on paying for referrals vary by state, so this network allows no referral fees.',
                ],
                'cost_note' => 'Premiums depend on the cover, the property or driver, and the state, so we don’t publish a typical figure.',
                'lookups' => [
                    ['name' => 'State insurance departments (NAIC directory)', 'url' => 'https://content.naic.org/state-insurance-departments'],
                    ['name' => 'NIPR producer lookup', 'url' => 'https://nipr.com/producer-lookup'],
                ],
                'hire_questions' => [
                    'Are you licensed in my state for the cover I need?',
                    'Which insurers do you represent?',
                    'Can you compare quotes from more than one company?',
                    'What do the deductibles and limits mean for me?',
                    'How do I reach you if I need to make a claim?',
                    'How will my premium change at renewal?',
                ],
                'faqs' => [
                    ['q' => 'Can insurance agents pay referral fees?', 'a' => 'Commissions may generally be paid only to licensed agents, and state rules on payments for referrals vary. To keep it simple and safe, this network exchanges introductions without any payment.'],
                    ['q' => 'Do I need a new agent when I move states?', 'a' => 'Often yes. Agents are licensed state by state, and many policies need to be rewritten for the new state.'],
                    ['q' => 'How do I check an agent’s license?', 'a' => 'Use your state department of insurance’s lookup (the NAIC lists them all) or the NIPR producer lookup.'],
                    ['q' => 'What kinds of insurance does the network cover?', 'a' => 'Home, auto, life and health insurance for individuals and families. Medicare has its own network.'],
                    ['q' => 'Is my information shared before I agree?', 'a' => 'No. You’re introduced only after you agree, and the receiving agent sees your contact details only after accepting.'],
                    ['q' => 'What do introducing agents get?', 'a' => 'Credits and recognition within the network, which have no cash value.'],
                    ['q' => 'How quickly will the new agent contact me?', 'a' => 'Each network sets deadlines for accepting and first contact, with reminders before they pass.'],
                    ['q' => 'Can the introducing agent follow what happens?', 'a' => 'Yes. The receiving agent posts updates on a shared timeline until the client is covered or decides not to proceed.'],
                ],
                'glossary' => [
                    ['term' => 'Producer', 'definition' => 'The legal term for a licensed insurance agent or broker.'],
                    ['term' => 'Line of authority', 'definition' => 'The kind of insurance a producer’s license allows them to sell, such as property, casualty, life or health.'],
                    ['term' => 'Premium', 'definition' => 'What the policyholder pays for cover, usually monthly or yearly.'],
                    ['term' => 'Deductible', 'definition' => 'The amount the policyholder pays toward a claim before the insurer pays.'],
                    ['term' => 'Appointment', 'definition' => 'An insurer’s authorization for a licensed agent to sell its policies.'],
                ],
                'related' => ['real-estate', 'mortgage', 'medicare', 'auto-sales'],
                'sources' => [
                    ['title' => 'State insurance departments (NAIC)', 'url' => 'https://content.naic.org/state-insurance-departments'],
                    ['title' => 'Producer lookup (NIPR)', 'url' => 'https://nipr.com/producer-lookup'],
                ],
                'checked' => self::CHECKED,
            ],

            'solar' => [
                'summary' => 'Solar referrals connect a homeowner to a licensed solar installer in their area. On {site_name}, installers pass homeowners they can’t serve to the verified installer who holds that city’s seat, track every referral, and agree any referral fee up front as a share of the receiving installer’s earnings.',
                'intro' => 'Solar installers often hear from homeowners outside their service area, or in states where they aren’t licensed. Instead of turning them away, the {industry} network lets you pass them to a verified local installer who answers quickly and keeps you informed, so the homeowner gets a good install and you keep the relationship.',
                'how' => 'Send a homeowner to the installer who holds the seat for their city, with their monthly bill and roof type if you have them. The receiving installer accepts within the deadline, books a site survey and updates you through proposal and install. Any referral fee is agreed in the referral agreement before you send the homeowner.',
                'request' => 'Thinking about solar? Tell us your city, and we’ll introduce you to the verified installer who serves it.',
                'state_summary' => 'Find a verified solar installer in {state}. Installers on {site_name} hold the contractor or electrical license {state} requires, and each city’s seat goes to the installer with the best record of serving referred homeowners.',
                'city_summary' => 'Going solar in {city}, {state}? {site_name} introduces you to the verified installer who holds the {city} seat, chosen for fast responses and good installs.',
                'facts' => [
                    'covers' => 'Companies that design and install rooftop and ground-mounted solar panels and home batteries.',
                    'cost' => 'In the first half of 2025, the median quoted price for home solar was about $2.48 per watt, and the average 11.7 kW system cost about $29,000 before incentives, according to EnergySage marketplace data.',
                    'licensing' => 'States license solar installers as electrical or general contractors, and requirements differ by state; check the state contractor licensing board.',
                    'fees' => 'Installers may agree a referral fee with each other where state law allows, as a share of what the receiving installer earns from the job.',
                ],
                'cost_note' => 'EnergySage figures come from quotes on its marketplace; prices vary by state, system size, equipment and roof. Incentives change, so check current federal and state programs.',
                'lookups' => [['name' => 'State contractor licensing agencies (NASCLA directory)', 'url' => 'https://www.nascla.org/state-licensing-information']],
                'hire_questions' => [
                    'Are you licensed as a contractor or electrician in this state?',
                    'Who will do the installation: your own crew or subcontractors?',
                    'What is the price per watt, and what does it include?',
                    'Which panels, inverters and batteries do you recommend, and why?',
                    'What warranties cover the equipment and the workmanship?',
                    'Who handles permits and the utility connection?',
                ],
                'faqs' => [
                    ['q' => 'How much does home solar cost?', 'a' => 'EnergySage reported a median of about $2.48 per watt in the first half of 2025, with the average 11.7 kW system around $29,000 before incentives. Your price depends on your state, system size and roof.'],
                    ['q' => 'Can solar installers pay referral fees?', 'a' => 'Usually yes, where state law allows. On {site_name}, a referral fee is a share of what the receiving installer earns from the job, agreed before the homeowner is sent.'],
                    ['q' => 'Does a referral cost the homeowner anything?', 'a' => 'No. Any referral fee is paid by the receiving installer out of its earnings.'],
                    ['q' => 'What license does a solar installer need?', 'a' => 'It depends on the state: usually an electrical or general contractor license. Check the state’s contractor licensing board.'],
                    ['q' => 'What details help the installer most?', 'a' => 'The homeowner’s city, average monthly power bill and roof type, plus any timing they mentioned.'],
                    ['q' => 'How quickly will the installer respond?', 'a' => 'Each network sets deadlines to accept and to make first contact, with reminders before they pass.'],
                    ['q' => 'Can I follow the referral?', 'a' => 'Yes. The installer posts updates on a shared timeline: site survey, proposal, install and closing.'],
                    ['q' => 'What if the installer can’t take the job?', 'a' => 'They decline with a reason, and you can send the homeowner to someone else straight away.'],
                ],
                'glossary' => [
                    ['term' => 'Price per watt', 'definition' => 'The system price divided by its capacity in watts; the usual way to compare solar quotes.'],
                    ['term' => 'kW (kilowatt)', 'definition' => 'A thousand watts; home systems are usually described by their capacity in kW.'],
                    ['term' => 'Inverter', 'definition' => 'The device that turns the panels’ direct current into the alternating current a home uses.'],
                    ['term' => 'Net metering', 'definition' => 'A utility arrangement that credits a home for solar power it sends to the grid; rules differ by state and utility.'],
                    ['term' => 'Interconnection', 'definition' => 'The utility’s approval to connect a solar system to the grid.'],
                ],
                'related' => ['roofing', 'real-estate', 'windows-siding', 'hvac'],
                'sources' => [
                    ['title' => 'US residential PV prices remain stable, September 2025 (PV Tech, on EnergySage data)', 'url' => 'https://www.pv-tech.org/us-residential-pv-prices-remain-stable-but-industry-confidence-falters-energysage/'],
                    ['title' => 'State licensing information (NASCLA)', 'url' => 'https://www.nascla.org/state-licensing-information'],
                ],
                'checked' => self::CHECKED,
            ],

            'moving' => [
                'summary' => 'Moving referrals connect a customer to a trusted mover at either end of their move. On {site_name}, movers pass customers to the verified mover who holds the seat for that city, track every referral, and agree any referral fee up front as a share of the receiving mover’s earnings.',
                'intro' => 'Movers regularly get calls for moves they can’t take: the wrong city, the wrong dates, or a long-distance move they don’t run. The {industry} network lets you pass that customer to a verified mover you can trust, instead of a lead site, and see the move through.',
                'how' => 'Send the customer to the mover who holds the seat for their city, with the move size and date. The receiving mover accepts within the deadline, gives an estimate and keeps you updated until moving day. Any referral fee is agreed in the referral agreement before you send the customer.',
                'request' => 'Planning a move? Tell us where from, where to and when, and we’ll introduce you to a verified mover.',
                'state_summary' => 'Find a verified mover in {state}. Interstate movers on {site_name} are registered with the FMCSA, and each city’s seat goes to the mover with the best record of serving referred customers.',
                'city_summary' => 'Moving to or from {city}, {state}? {site_name} introduces you to the verified mover who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Household moving companies for local and long-distance moves, including packing and storage.',
                    'cost' => '',
                    'licensing' => 'Interstate movers must be registered with the Federal Motor Carrier Safety Administration and have a USDOT number; moves within one state are regulated by that state.',
                    'fees' => 'Movers may agree a referral fee with each other, as a share of what the receiving mover earns from the move. A company that arranges moves for a fee without moving goods itself is a broker and must register with the FMCSA as one.',
                ],
                'cost_note' => 'Prices depend on distance, volume, date and services, so we don’t publish a typical figure.',
                'lookups' => [['name' => 'FMCSA Protect Your Move: search a mover or broker', 'url' => 'https://ai.fmcsa.dot.gov/hhg/Search.asp']],
                'hire_questions' => [
                    'What is your USDOT number, and are you registered with the FMCSA?',
                    'Are you the mover, or a broker who will hire one?',
                    'Is your estimate binding or non-binding, and what could change it?',
                    'What valuation coverage do you offer if something is damaged?',
                    'Do you charge extra for stairs, long carries or storage?',
                    'How do I reach you on moving day?',
                ],
                'faqs' => [
                    ['q' => 'How do I check a mover?', 'a' => 'Search the mover’s name, USDOT number or MC number in the FMCSA’s Protect Your Move search, which shows registration and complaint history.'],
                    ['q' => 'What is the difference between a mover and a broker?', 'a' => 'A mover owns trucks and moves your goods. A broker arranges the move with a mover and doesn’t move anything itself. Both must register with the FMCSA for interstate moves.'],
                    ['q' => 'Can movers pay referral fees?', 'a' => 'Yes, between moving companies. On {site_name}, a referral fee is a share of what the receiving mover earns from the move, agreed before the customer is sent.'],
                    ['q' => 'Can a mover charge more than the estimate?', 'a' => 'For interstate moves, federal rules limit what you must pay at delivery to 100% of a binding estimate or 110% of a non-binding estimate; any balance is billed later.'],
                    ['q' => 'Does a referral cost the customer anything?', 'a' => 'No. Any referral fee is paid by the receiving mover out of its earnings.'],
                    ['q' => 'What details help the receiving mover?', 'a' => 'Where from, where to, the move size and the date, plus anything unusual like a piano or storage.'],
                    ['q' => 'How quickly will the mover respond?', 'a' => 'Each network sets deadlines to accept and to make first contact, with reminders before they pass.'],
                    ['q' => 'Can I follow the referral?', 'a' => 'Yes. The receiving mover posts updates on a shared timeline: estimate, booking and moving day.'],
                ],
                'glossary' => [
                    ['term' => 'USDOT number', 'definition' => 'The identification number the US Department of Transportation gives registered interstate carriers.'],
                    ['term' => 'Binding estimate', 'definition' => 'A written estimate that fixes the price for the services listed.'],
                    ['term' => 'Non-binding estimate', 'definition' => 'An estimate of the likely price; the final charge depends on the actual weight and services.'],
                    ['term' => 'Broker', 'definition' => 'A company that arranges a move with a mover but doesn’t transport goods itself.'],
                    ['term' => 'Valuation', 'definition' => 'The mover’s liability for loss or damage, which sets how much you can recover.'],
                ],
                'related' => ['real-estate', 'property-management', 'commercial-cleaning'],
                'sources' => [
                    ['title' => 'Movers vs. brokers (FMCSA Protect Your Move)', 'url' => 'https://www.fmcsa.dot.gov/protect-your-move/movers-vs-brokers'],
                    ['title' => 'Moving FAQs (FMCSA Protect Your Move)', 'url' => 'https://www.fmcsa.dot.gov/protect-your-move/faq'],
                    ['title' => 'Search a mover or broker (FMCSA)', 'url' => 'https://ai.fmcsa.dot.gov/hhg/Search.asp'],
                ],
                'checked' => self::CHECKED,
            ],

            'personal-injury' => [
                'summary' => 'The {industry} directory lists verified personal injury attorneys by city so people can contact them directly. {site_name} does not route cases or take any share of legal fees; attorneys pay a flat listing fee, and each attorney’s state bar license is checked.',
                'intro' => 'After an accident, people need a qualified attorney licensed in the right state, quickly. The {industry} directory lists verified personal injury attorneys city by city so people can compare and contact them directly. Lawyers’ ethics rules strictly limit paying for recommendations, so this is a directory, not a referral network.',
                'how' => 'Attorneys appear in the directory for each city they serve, for a flat listing fee that never depends on cases or outcomes. People contact the attorney directly. {site_name} does not recommend attorneys, route cases or share in fees.',
                'request' => 'Hurt in an accident? Browse verified personal injury attorneys in your city and contact them directly.',
                'state_summary' => 'Browse verified personal injury attorneys licensed in {state}. Listings on {site_name} show each attorney’s bar license; contact attorneys directly.',
                'city_summary' => 'Looking for a personal injury attorney in {city}, {state}? Browse verified, licensed attorneys listed for {city} and contact them directly.',
                'facts' => [
                    'covers' => 'Attorneys who represent people injured in accidents, such as car crashes, falls and workplace injuries.',
                    'cost' => 'Personal injury attorneys usually work on a contingency fee, often one-third of the recovery and sometimes more if the case goes to trial; some states cap these fees.',
                    'licensing' => 'Attorneys are licensed by the highest court or bar of each state; the state disciplinary agency keeps records of licensing and discipline.',
                    'fees' => 'Under ABA Model Rule 7.2, which most states follow, a lawyer may not give anything of value for recommending their services, apart from narrow exceptions; fee sharing between lawyers is limited by Rule 1.5(e).',
                ],
                'cost_note' => 'Fee percentages vary by attorney, case and state, and some states cap contingency fees.',
                'lookups' => [['name' => 'Check a lawyer’s license and discipline (American Bar Association)', 'url' => 'https://www.americanbar.org/groups/professional_responsibility/resources/resources_for_the_public/consumer_faqs.html']],
                'hire_questions' => [
                    'Are you licensed in the state where the injury happened?',
                    'How many cases like mine have you handled?',
                    'What percentage is your contingency fee, and does it change if we go to trial?',
                    'Who pays case costs, and when?',
                    'Who will handle my case day to day?',
                    'How and how often will you update me?',
                ],
                'faqs' => [
                    ['q' => 'How do personal injury attorneys charge?', 'a' => 'Usually by contingency fee: a percentage of what you recover, often one-third, sometimes more if the case goes to trial. You pay no fee if there is no recovery, though case costs may be handled differently.'],
                    ['q' => 'Why is this a directory and not a referral network?', 'a' => 'Lawyers’ ethics rules strictly limit giving anything of value for recommending a lawyer, so {site_name} only lists attorneys for a flat fee and never routes cases.'],
                    ['q' => 'Does {site_name} recommend an attorney?', 'a' => 'No. The directory lists verified attorneys; you choose and contact them directly.'],
                    ['q' => 'How do I check an attorney’s license?', 'a' => 'Contact the state disciplinary agency or search the state bar’s lawyer directory; the American Bar Association explains how.'],
                    ['q' => 'Does using the directory create an attorney-client relationship?', 'a' => 'No. That only begins when you and an attorney agree to it.'],
                    ['q' => 'What does a listing cost attorneys?', 'a' => 'A flat listing fee that never depends on cases, referrals or outcomes.'],
                    ['q' => 'Is the information on this page legal advice?', 'a' => 'No. It is general information; speak to a licensed attorney about your situation.'],
                    ['q' => 'How quickly should I contact an attorney?', 'a' => 'As soon as you can: deadlines for filing claims (statutes of limitations) vary by state and type of case.'],
                ],
                'glossary' => [
                    ['term' => 'Contingency fee', 'definition' => 'A fee that is a percentage of what the client recovers, paid only if there is a recovery.'],
                    ['term' => 'Statute of limitations', 'definition' => 'The deadline for filing a claim, which varies by state and type of case.'],
                    ['term' => 'Settlement', 'definition' => 'An agreement that resolves a claim without a trial verdict.'],
                    ['term' => 'Case costs', 'definition' => 'Expenses of pursuing a case, such as filing fees and expert reports, separate from the attorney’s fee.'],
                    ['term' => 'Attorney advertising', 'definition' => 'Communications about a lawyer’s services, which state ethics rules regulate.'],
                ],
                'related' => ['estate-planning', 'insurance'],
                'sources' => [
                    ['title' => 'Contingency fees (FindLaw)', 'url' => 'https://www.findlaw.com/legal/practice/financing-a-law-firm/types-of-pricing-for-legal-services-contingency-fees.html'],
                    ['title' => 'Model Rule 7.2 (American Bar Association)', 'url' => 'https://www.americanbar.org/groups/professional_responsibility/publications/model_rules_of_professional_conduct/rule_7_2_advertising/'],
                    ['title' => 'Consumer FAQs: checking a lawyer (American Bar Association)', 'url' => 'https://www.americanbar.org/groups/professional_responsibility/resources/resources_for_the_public/consumer_faqs.html'],
                ],
                'checked' => self::CHECKED,
            ],
        ];
    }
}
