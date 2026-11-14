<?php

declare(strict_types=1);

namespace App\Domains\Content\Industry;

use App\Domains\Content\Industry\IndustryPackB as B;

/**
 * Sprint 39c: drafted hub content for the last 20 launch industries (business, vehicles, life events and land).
 * Same rules as IndustryPack: outside facts paraphrased from listed sources checked on the given date, cost figures
 * only where a reliable public source exists, and everything installs as a draft with "Review pending".
 */
final class IndustryPackC
{
    public const CHECKED = '2026-11-14';

    private const ARELLO_SRC = ['title' => 'ARELLO license verification', 'url' => 'https://www.arello.com/'];

    private const NASCLA_SRC = ['title' => 'State licensing information (NASCLA)', 'url' => 'https://www.nascla.org/state-licensing-information'];

    /**
     * A Tier A hub for a business-to-business or local trade, built from its own words and facts, with the shared
     * questions (fees, response, following the referral) added.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function trade(array $d, string $pros, string $pro, string $client): array
    {
        $d['faqs'] = [...$d['faqs'], ...B::feesOpen($pros, $pro, $client), ...B::network($pro)];
        $d['checked'] = self::CHECKED;

        return $d;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'business-brokers' => self::trade([
                'summary' => 'Business broker referrals connect an owner selling a business, or a buyer looking for one, to a business broker in the right market. On {site_name}, brokers refer clients with consent, track each deal, and agree any fee up front as a share of the receiving broker’s commission.',
                'intro' => 'Owners sell businesses in markets their broker doesn’t cover, and buyers look in other cities. The {industry} network gives one verified business broker per metro a seat, so you can hand a client to a broker with local buyers and keep the relationship.',
                'how' => 'Refer the client to the broker who holds the seat for their market, with the business type and revenue range. The receiving broker accepts within the deadline, values and markets the business or searches for one, and updates you through closing. Any referral fee is agreed first.',
                'request' => 'Selling or buying a business? Tell us where, and we’ll introduce you to a verified business broker there.',
                'state_summary' => 'Find a verified business broker in {state}. Brokers on {site_name} are chosen for how well they serve referred buyers and sellers.',
                'city_summary' => 'Selling or buying a business in {city}, {state}? {site_name} introduces you to the verified broker who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Brokers who value, market and sell small and mid-sized businesses, and help buyers find them.',
                    'cost' => '',
                    'licensing' => 'Some states require a real estate license to broker the sale of a business, especially when real estate or a lease is part of the deal; others have no specific license.',
                    'fees' => 'Brokers may agree a referral fee with each other where state law allows, as a share of the receiving broker’s commission.',
                ],
                'cost_note' => 'Commissions depend on the size of the deal and the broker, so we don’t publish a typical figure.',
                'lookups' => [B::ARELLO],
                'hire_questions' => ['Are you licensed where my state requires it?', 'How many businesses like mine have you sold?', 'How do you value a business?', 'What is your commission, and is there an upfront fee?', 'How will you keep the sale confidential?', 'How do you screen buyers?'],
                'faqs' => [
                    ['q' => 'Do business brokers need a license?', 'a' => 'It depends on the state: some require a real estate license, especially when property or a lease is included.'],
                    ['q' => 'How are businesses valued?', 'a' => 'Usually from earnings, such as seller’s discretionary earnings or EBITDA, multiplied by a factor that depends on the industry and risk.'],
                    ['q' => 'How do brokers keep a sale confidential?', 'a' => 'Buyers sign a non-disclosure agreement before they learn the business’s name or see its numbers.'],
                    ['q' => 'What details help the receiving broker?', 'a' => 'The business type, location, annual revenue range and the owner’s timing.'],
                ],
                'glossary' => [
                    ['term' => 'SDE', 'definition' => 'Seller’s discretionary earnings: profit plus the owner’s pay and perks, used to value small businesses.'],
                    ['term' => 'EBITDA', 'definition' => 'Earnings before interest, taxes, depreciation and amortization.'],
                    ['term' => 'NDA', 'definition' => 'A non-disclosure agreement that keeps the sale and its details confidential.'],
                    ['term' => 'Asset sale', 'definition' => 'A sale of the business’s assets rather than the company itself.'],
                ],
                'related' => ['commercial-real-estate', 'franchise-sales', 'tax-accounting', 'financial-advisors'],
                'sources' => [self::ARELLO_SRC],
            ], 'business brokers', 'broker', 'client'),

            'auto-sales' => self::trade([
                'summary' => 'Auto sales referrals connect a car buyer to a trusted, licensed dealer where they live. On {site_name}, dealers pass buyers they can’t serve to the verified dealer who holds that metro’s seat, track each sale, and agree any referral fee up front as a share of the receiving dealer’s earnings.',
                'intro' => 'Buyers move, or want a model you don’t carry. The {industry} network lets you pass them to a verified dealer in their area instead of losing them to a random listing, and see the sale through.',
                'how' => 'Send the buyer to the dealer who holds the seat for their metro, with what they are looking for. The receiving dealer accepts within the deadline, arranges a test drive and updates you through delivery. Any referral fee is agreed first.',
                'request' => 'Shopping for a car? Tell us where you live and what you want, and we’ll introduce you to a verified local dealer.',
                'state_summary' => 'Find a licensed car dealer in {state}. Dealers on {site_name} hold a {state} dealer license and are chosen for how well they serve referred buyers.',
                'city_summary' => 'Buying a car in {city}, {state}? {site_name} introduces you to the verified dealer who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Licensed new and used vehicle dealers selling, leasing and taking trade-ins.',
                    'cost' => '',
                    'licensing' => 'Dealers are licensed by each state, usually through the motor vehicle department; under the FTC’s Used Car Rule, dealers must post a Buyers Guide on used vehicles showing whether they come with a warranty or “as is”.',
                    'fees' => 'Dealers may agree a referral fee with each other where state law allows, as a share of what the receiving dealer earns from the sale.',
                ],
                'cost_note' => 'Prices depend on the vehicle, market and incentives, so we don’t publish a typical figure.',
                'lookups' => [['name' => 'FTC Used Car Rule and the Buyers Guide', 'url' => 'https://www.ftc.gov/enforcement/rules/rulemaking-regulatory-reform-proceedings/used-car-rule']],
                'hire_questions' => ['Are you licensed as a dealer in this state?', 'What is the out-the-door price, with every fee?', 'Is this vehicle sold with a warranty or as is?', 'Can I see the vehicle history report?', 'Can I have it inspected by my own mechanic?', 'What will you give me for my trade-in, in writing?'],
                'faqs' => [
                    ['q' => 'What is the Buyers Guide on a used car?', 'a' => 'A window sticker the FTC requires dealers to post on used vehicles, showing whether there is a warranty or the car is sold as is.'],
                    ['q' => 'How do I check a dealer?', 'a' => 'Confirm their dealer license with the state motor vehicle department and read the Buyers Guide on any used vehicle.'],
                    ['q' => 'What details help the receiving dealer?', 'a' => 'Where the buyer lives, new or used, the model or type, budget and any trade-in.'],
                    ['q' => 'Can I refer a buyer for a lease?', 'a' => 'Yes. Say so in the referral, and the receiving dealer can quote lease terms.'],
                ],
                'glossary' => [
                    ['term' => 'Buyers Guide', 'definition' => 'The FTC-required window sticker on used vehicles describing warranty coverage.'],
                    ['term' => 'As is', 'definition' => 'Sold without a dealer warranty; the buyer pays for any repairs.'],
                    ['term' => 'Out-the-door price', 'definition' => 'The total price including taxes, title, registration and dealer fees.'],
                    ['term' => 'Trade-in', 'definition' => 'A vehicle the buyer sells to the dealer as part of the purchase.'],
                ],
                'related' => ['rv-boat', 'insurance', 'equipment', 'moving'],
                'sources' => [['title' => 'Used Car Rule (Federal Trade Commission)', 'url' => 'https://www.ftc.gov/enforcement/rules/rulemaking-regulatory-reform-proceedings/used-car-rule']],
            ], 'dealers', 'dealer', 'buyer'),

            'rv-boat' => self::trade([
                'summary' => 'RV and boat referrals connect a buyer to a licensed RV or boat dealer in their market. On {site_name}, dealers pass buyers they can’t serve to the verified dealer who holds that metro’s seat, track each sale, and agree any referral fee up front as a share of the receiving dealer’s earnings.',
                'intro' => 'RV and boat buyers travel, relocate and shop far from home. The {industry} network lets you pass them to a verified dealer near them who can sell, deliver and service the unit.',
                'how' => 'Send the buyer to the dealer who holds the seat for their metro, with the type of RV or boat they want. The receiving dealer accepts within the deadline, shows units and updates you through delivery. Any referral fee is agreed first.',
                'request' => 'Shopping for an RV or boat? Tell us where you live and what you want, and we’ll introduce you to a verified local dealer.',
                'state_summary' => 'Find a licensed RV or boat dealer in {state}. Dealers on {site_name} hold the dealer license {state} requires.',
                'city_summary' => 'Buying an RV or boat in {city}, {state}? {site_name} introduces you to the verified dealer who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Dealers selling new and used motorhomes, travel trailers, boats and personal watercraft, with service after the sale.',
                    'cost' => '',
                    'licensing' => 'RV and boat dealers are licensed by each state, often through the motor vehicle department or a separate vessel dealer license.',
                    'fees' => 'Dealers may agree a referral fee with each other where state law allows, as a share of what the receiving dealer earns from the sale.',
                ],
                'cost_note' => 'Prices depend on the unit, options and market, so we don’t publish a typical figure.',
                'lookups' => [['name' => 'Find your state’s licensing agencies (NASCLA directory)', 'url' => 'https://www.nascla.org/state-licensing-information']],
                'hire_questions' => ['Are you licensed as a dealer in this state?', 'What is the full price, with freight, prep and every fee?', 'What warranty comes with the unit, and who services it?', 'Can I have an independent inspection?', 'Do you offer delivery and a walk-through?', 'What will you give me for my trade-in?'],
                'faqs' => [
                    ['q' => 'Do RV and boat dealers need a license?', 'a' => 'Yes, in most states, through the motor vehicle department or a separate dealer license. Check with the state.'],
                    ['q' => 'Should I get an inspection?', 'a' => 'For used units especially, an independent RV inspection or marine survey can find problems before you buy.'],
                    ['q' => 'What details help the receiving dealer?', 'a' => 'Where the buyer lives, the type of RV or boat, new or used, budget and any trade-in.'],
                    ['q' => 'Who services the unit after the sale?', 'a' => 'Ask the dealer; buying near home makes warranty service easier.'],
                ],
                'glossary' => [
                    ['term' => 'Class A, B and C', 'definition' => 'Types of motorhome, from bus-style (A) to van (B) and cab-over (C).'],
                    ['term' => 'Travel trailer', 'definition' => 'An RV towed behind a vehicle.'],
                    ['term' => 'Marine survey', 'definition' => 'An independent inspection of a boat’s condition and value.'],
                    ['term' => 'HIN', 'definition' => 'Hull identification number: a boat’s unique identifier.'],
                ],
                'related' => ['auto-sales', 'insurance', 'travel', 'land'],
                'sources' => [self::NASCLA_SRC],
            ], 'dealers', 'dealer', 'buyer'),

            'staffing' => self::trade([
                'summary' => 'Staffing referrals connect an employer to a recruiter in the market where its roles are. On {site_name}, recruiters pass employers they can’t serve to the verified recruiter who holds that metro’s seat, track each search, and agree any fee up front as a share of the receiving recruiter’s earnings.',
                'intro' => 'Employers hire in cities where their usual recruiter has no network. The {industry} network lets you pass the search to a verified local recruiter who knows the market, and follow it through to hire.',
                'how' => 'Refer the employer to the recruiter who holds the seat for the market, with the roles and timing. The receiving recruiter accepts within the deadline, runs the search and updates you through to hire. Any referral fee is agreed first.',
                'request' => 'Hiring in another city? Tell us where and for what roles, and we’ll introduce you to a verified local recruiter.',
                'state_summary' => 'Find a verified staffing and recruiting firm in {state}. Recruiters on {site_name} are chosen for how well they fill referred searches.',
                'city_summary' => 'Hiring in {city}, {state}? {site_name} introduces you to the verified recruiter who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Staffing agencies and recruiters filling temporary, contract and permanent roles for employers.',
                    'cost' => '',
                    'licensing' => 'Most states don’t license recruiters, but some license or register employment agencies, especially those that charge job seekers.',
                    'fees' => 'Recruiters may agree a referral fee or fee split with each other, as a share of what the receiving recruiter earns from the placement.',
                ],
                'cost_note' => 'Placement fees and markups depend on the role and contract, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Do you specialize in roles like ours, in this market?', 'How do you charge: a placement fee, a markup or a retainer?', 'What guarantee do you give if a hire leaves early?', 'How do you screen candidates?', 'How quickly can you present candidates?', 'Who is our day-to-day contact?'],
                'faqs' => [
                    ['q' => 'Do recruiters need a license?', 'a' => 'Usually not, though some states license or register employment agencies. Check with the state labor department.'],
                    ['q' => 'What is the difference between staffing and recruiting?', 'a' => 'Staffing firms usually employ temporary workers and bill the employer; recruiters find permanent hires for a placement fee.'],
                    ['q' => 'What details help the receiving recruiter?', 'a' => 'The roles, how many, the location, pay range and timing.'],
                    ['q' => 'Can I refer a search with roles in several cities?', 'a' => 'Yes. Send a referral to the recruiter holding the seat in each market.'],
                ],
                'glossary' => [
                    ['term' => 'Placement fee', 'definition' => 'A fee an employer pays a recruiter for a permanent hire.'],
                    ['term' => 'Markup', 'definition' => 'The amount a staffing firm adds to a temporary worker’s pay when billing the employer.'],
                    ['term' => 'Retained search', 'definition' => 'A search paid partly up front, usually for senior roles.'],
                    ['term' => 'Contract-to-hire', 'definition' => 'A role that starts as temporary with the option to hire permanently.'],
                ],
                'related' => ['business-software', 'commercial-cleaning', 'franchise-sales'],
                'sources' => [],
            ], 'recruiters', 'recruiter', 'employer'),

            'travel' => self::trade([
                'summary' => 'Travel referrals connect a traveler to a specialist travel advisor for their destination or trip type. On {site_name}, advisors pass travelers they can’t serve to the verified advisor who holds that seat, track each booking, and agree any referral fee up front as a share of the receiving advisor’s commission.',
                'intro' => 'No advisor knows every destination. The {industry} network lets you pass a traveler to a verified specialist, for a destination wedding, an expedition cruise or a region you don’t sell, and keep the client relationship.',
                'how' => 'Send the traveler to the advisor who holds the seat, with the destination, dates and budget. The receiving advisor accepts within the deadline, plans and books the trip and updates you. Any referral fee is agreed first.',
                'request' => 'Planning a trip? Tell us where and when, and we’ll introduce you to a verified travel advisor who specializes in it.',
                'state_summary' => 'Find a verified travel advisor in {state}. Advisors on {site_name} hold any seller of travel registration {state} requires.',
                'city_summary' => 'Planning travel from {city}, {state}? {site_name} introduces you to a verified travel advisor who serves {state}.',
                'facts' => [
                    'covers' => 'Travel advisors and agencies planning and booking leisure trips, cruises, tours and group travel.',
                    'cost' => '',
                    'licensing' => 'There is no federal license; a few states, including California, Florida, Hawaii and Washington, require sellers of travel to register.',
                    'fees' => 'Advisors may agree a referral fee with each other, usually a share of the commission the receiving advisor earns on the booking.',
                ],
                'cost_note' => 'Trip prices and any planning fees vary, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Have you been to, or booked, this destination?', 'Do you charge a planning fee, or are you paid by commission?', 'Are you registered as a seller of travel where required?', 'Which travel insurance do you recommend?', 'Who helps me if something goes wrong while I’m away?', 'How will you send my documents?'],
                'faqs' => [
                    ['q' => 'Do travel agents need a license?', 'a' => 'There is no federal license, but a few states, including California, Florida, Hawaii and Washington, require sellers of travel to register.'],
                    ['q' => 'How are travel advisors paid?', 'a' => 'Mostly by commission from suppliers such as cruise lines and hotels; some also charge planning fees.'],
                    ['q' => 'What details help the receiving advisor?', 'a' => 'The destination, dates, number of travelers, budget and the kind of trip.'],
                    ['q' => 'Should I buy travel insurance?', 'a' => 'Ask your advisor; it can cover cancellation, medical care abroad and delays.'],
                ],
                'glossary' => [
                    ['term' => 'Seller of travel', 'definition' => 'A business that sells travel; some states require it to register.'],
                    ['term' => 'Host agency', 'definition' => 'An agency that independent advisors book through.'],
                    ['term' => 'Travel insurance', 'definition' => 'Cover for trip cancellation, medical care and other problems while traveling.'],
                    ['term' => 'DMC', 'definition' => 'A destination management company that arranges local services.'],
                ],
                'related' => ['weddings-events', 'timeshare', 'rv-boat'],
                'sources' => [['title' => 'Travel agent license and seller of travel registration (Host Agency Reviews)', 'url' => 'https://hostagencyreviews.com/blog/travel-agent-license-seller-of-travel-license']],
            ], 'travel advisors', 'travel advisor', 'traveler'),

            'weddings-events' => self::trade([
                'summary' => 'Wedding and event referrals connect a couple or host to trusted vendors where the event is. On {site_name}, vendors pass couples they can’t serve to the verified vendor who holds that city’s seat, track each booking, and agree any referral fee up front as a share of the receiving vendor’s earnings.',
                'intro' => 'Destination weddings and booked-up dates mean vendors turn couples away every week. The {industry} network lets you pass them to a verified vendor where the event is, so the couple is looked after and you keep their trust.',
                'how' => 'Send the couple to the vendor who holds the seat for the event city, with the date, guest count and service. The receiving vendor accepts within the deadline, checks availability and updates you through the event. Any referral fee is agreed first.',
                'request' => 'Planning a wedding or event? Tell us where and when, and we’ll introduce you to verified local vendors.',
                'state_summary' => 'Find verified wedding and event vendors in {state}. Vendors on {site_name} are chosen for how well they serve referred couples.',
                'city_summary' => 'Planning a wedding or event in {city}, {state}? {site_name} introduces you to the verified vendor who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Planners, venues, photographers, caterers, florists, musicians and other wedding and event vendors.',
                    'cost' => '',
                    'licensing' => 'Most event vendors need only a local business license; caterers and venues also need health and alcohol permits where they apply.',
                    'fees' => 'Vendors may agree a referral fee with each other, as a share of what the receiving vendor earns from the booking.',
                ],
                'cost_note' => 'Prices depend on the date, guest count, location and services, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Are you available on our date?', 'What exactly is included in your package?', 'What deposit do you need, and is it refundable?', 'What happens if you’re ill on the day?', 'Can we see full examples of past events?', 'Do you carry liability insurance?'],
                'faqs' => [
                    ['q' => 'When should couples book vendors?', 'a' => 'Popular dates book early; venues and photographers first, then the rest.'],
                    ['q' => 'What details help the receiving vendor?', 'a' => 'The event city, date, guest count and the service needed.'],
                    ['q' => 'Can I refer a couple to several vendors?', 'a' => 'Yes. Send a referral to each vendor they need.'],
                    ['q' => 'What should a vendor contract include?', 'a' => 'The date, services, price, deposit, cancellation terms and what happens if the vendor can’t attend.'],
                ],
                'glossary' => [
                    ['term' => 'Retainer', 'definition' => 'A deposit that reserves the vendor for the date.'],
                    ['term' => 'Day-of coordinator', 'definition' => 'A planner who runs the event on the day.'],
                    ['term' => 'Preferred vendor list', 'definition' => 'Vendors a venue recommends or requires.'],
                    ['term' => 'Load-in', 'definition' => 'The time vendors arrive to set up.'],
                ],
                'related' => ['travel', 'fitness', 'commercial-cleaning'],
                'sources' => [],
            ], 'event vendors', 'vendor', 'couple'),

            'elective-medical' => [
                'summary' => 'Cosmetic and elective care introductions connect a patient to a licensed practice near them, with their consent. On {site_name}, practices introduce patients and track each introduction, without referral fees, since many states bar fee-splitting and paying for patient referrals.',
                'intro' => 'Patients move, travel or ask for a procedure your practice doesn’t offer. The {industry} network lets you introduce them to a verified, licensed practice near them, with clear deadlines and no payment for the introduction.',
                'how' => 'Introduce the patient to the practice that holds the seat for their city, with what they are interested in. The receiving practice accepts within the deadline, offers a consultation and updates you with the patient’s permission. Introductions are exchanged without payment.',
                'request' => 'Looking for a cosmetic or elective care provider? Tell us your city, and we’ll introduce you to a verified, licensed practice.',
                'state_summary' => 'Find a verified cosmetic and elective care practice in {state}. Providers on {site_name} hold a {state} license, and introductions are made without referral payments.',
                'city_summary' => 'Looking for cosmetic or elective care in {city}, {state}? {site_name} introduces you to the verified practice that holds the {city} seat.',
                'facts' => [
                    'covers' => 'Licensed practices offering cosmetic surgery, aesthetic treatments, LASIK, dental cosmetics and other elective care.',
                    'cost' => '',
                    'licensing' => 'Physicians and other clinicians are licensed by state boards; DocInfo from the Federation of State Medical Boards shows a physician’s licenses and board actions.',
                    'fees' => 'Many states prohibit fee-splitting and paying for patient referrals, and federal law makes paying for referrals of Medicare or Medicaid business a crime; this network allows no referral fees.',
                ],
                'cost_note' => 'Prices depend on the procedure, provider and region, so we don’t publish a typical figure.',
                'lookups' => [['name' => 'DocInfo (Federation of State Medical Boards)', 'url' => 'https://www.docinfo.org/']],
                'hire_questions' => ['Who will perform the procedure, and are they board certified in it?', 'Where will it be done, and is the facility accredited?', 'What is the total price, including follow-up?', 'What are the risks and the recovery time?', 'Can I see before-and-after results from your own patients?', 'Who do I call if there’s a problem after hours?'],
                'faqs' => [
                    ['q' => 'Can practices pay for patient referrals?', 'a' => 'Many states prohibit fee-splitting and paying for referrals, and federal law forbids it for Medicare or Medicaid business. This network allows no referral fees.'],
                    ['q' => 'How do I check a provider?', 'a' => 'Search DocInfo from the Federation of State Medical Boards, or the state medical board, for licenses and any board actions.'],
                    ['q' => 'Is the patient’s information private?', 'a' => 'Patients are introduced only with their consent, and the receiving practice sees their details only after accepting.'],
                    ['q' => 'What details help the receiving practice?', 'a' => 'The patient’s city and the treatment they are interested in; no medical records are needed for the introduction.'],
                    ['q' => 'Why introduce patients if no fee is allowed?', 'a' => 'Because the patient gets a verified provider near them. Members earn credits and recognition with no cash value.'],
                    ['q' => 'Is this medical advice?', 'a' => 'No. It is general information; talk to a licensed provider about your situation.'],
                    ...B::network('practice', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'Board certified', 'definition' => 'Certified by a specialty board after training and exams in that specialty.'],
                    ['term' => 'Accredited facility', 'definition' => 'A surgical facility inspected and approved by an accrediting body.'],
                    ['term' => 'Fee-splitting', 'definition' => 'Sharing a professional fee with someone in return for a referral; banned in many states.'],
                    ['term' => 'Consultation', 'definition' => 'A first appointment to discuss options, risks and price.'],
                ],
                'related' => ['fitness', 'travel', 'insurance'],
                'sources' => [['title' => 'Information for consumers (Federation of State Medical Boards)', 'url' => 'https://www.fsmb.org/guide-to-medical-regulation-in-the-united-states/information-for-consumers/'], B::AKS],
                'checked' => self::CHECKED,
            ],

            'fitness' => self::trade([
                'summary' => 'Fitness referrals connect a member who is moving to a trusted gym or studio in their new city. On {site_name}, clubs pass members they can’t serve to the verified club that holds that city’s seat, track each referral, and agree any referral fee up front as a share of the receiving club’s earnings.',
                'intro' => 'Members move away and cancel, and you lose them for good. The {industry} network lets you pass them to a verified club in their new city, so they keep training and remember who helped.',
                'how' => 'Send the member to the club that holds the seat for their new city, with what they like to do. The receiving club accepts within the deadline, offers a visit and updates you. Any referral fee is agreed first.',
                'request' => 'Moving and need a new gym or studio? Tell us where, and we’ll introduce you to a verified club there.',
                'state_summary' => 'Find a verified gym or studio in {state}. Clubs on {site_name} are chosen for how well they welcome referred members.',
                'city_summary' => 'Looking for a gym or studio in {city}, {state}? {site_name} introduces you to the verified club that holds the {city} seat.',
                'facts' => [
                    'covers' => 'Gyms, fitness studios and franchise clubs offering memberships, classes and personal training.',
                    'cost' => '',
                    'licensing' => 'Clubs need a local business license; many states have health club laws covering contracts, cancellations and prepaid memberships.',
                    'fees' => 'Clubs may agree a referral fee with each other, as a share of what the receiving club earns from the membership.',
                ],
                'cost_note' => 'Membership prices vary widely by club and city, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Can I try the club before joining?', 'What is the monthly price, and are there joining or annual fees?', 'How long is the contract, and how do I cancel?', 'Can I freeze my membership?', 'Are trainers certified?', 'What hours and classes do you offer?'],
                'faqs' => [
                    ['q' => 'How do I cancel a gym membership?', 'a' => 'Read the contract’s cancellation terms before signing; many states have health club laws that set cancellation rights.'],
                    ['q' => 'What details help the receiving club?', 'a' => 'The member’s new city, what they like (classes, weights, swimming) and when they move.'],
                    ['q' => 'Can I refer a family?', 'a' => 'Yes. Say so in the referral, and the receiving club can quote family options.'],
                    ['q' => 'Is a referral the same as a transfer?', 'a' => 'No. A referral is an introduction; the member joins the new club under its own terms.'],
                ],
                'glossary' => [
                    ['term' => 'Joining fee', 'definition' => 'A one-time charge when a membership starts.'],
                    ['term' => 'Freeze', 'definition' => 'Pausing a membership for a time, sometimes for a fee.'],
                    ['term' => 'Annual fee', 'definition' => 'A yearly charge some clubs add to monthly dues.'],
                    ['term' => 'Personal training', 'definition' => 'One-on-one coaching with a trainer, usually extra.'],
                ],
                'related' => ['elective-medical', 'weddings-events', 'moving'],
                'sources' => [],
            ], 'clubs', 'club', 'member'),

            'franchise-sales' => self::trade([
                'summary' => 'Franchise referrals connect a would-be franchise owner to a franchise consultant who knows the brands available in their state. On {site_name}, consultants pass candidates they can’t serve to the verified consultant who holds that seat, track each candidate, and agree any fee up front.',
                'intro' => 'Candidates live in states where some brands aren’t sold or registered, or want industries you don’t cover. The {industry} network lets you pass them to a verified consultant who can help, and follow the candidate to signing.',
                'how' => 'Introduce the candidate to the consultant who holds the seat for their state, with their budget and interests. The receiving consultant accepts within the deadline, works with the candidate and updates you. Any referral fee is agreed first.',
                'request' => 'Thinking about buying a franchise? Tell us your state, and we’ll introduce you to a verified franchise consultant.',
                'state_summary' => 'Find a verified franchise consultant in {state}. Consultants on {site_name} are chosen for how well they guide referred candidates.',
                'city_summary' => 'Exploring franchises near {city}, {state}? {site_name} introduces you to a verified consultant who serves {state}.',
                'facts' => [
                    'covers' => 'Franchise consultants and brokers who help people choose and buy a franchise.',
                    'cost' => '',
                    'licensing' => 'Under the FTC Franchise Rule, franchisors must give buyers their Franchise Disclosure Document at least 14 calendar days before any agreement is signed or money is paid; some states also require franchise sellers or brokers to register.',
                    'fees' => 'Franchise consultants are usually paid by the franchisor when a candidate signs; consultants may share that fee with each other where state law allows.',
                ],
                'cost_note' => 'Franchise fees and startup costs are listed in each brand’s Franchise Disclosure Document, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['How are you paid, and by whom?', 'Which brands do you work with, and which don’t you?', 'Are you registered as a franchise seller where my state requires it?', 'Will you help me speak with existing franchisees?', 'What should I look for in the Franchise Disclosure Document?', 'Do you recommend I have a franchise attorney review it?'],
                'faqs' => [
                    ['q' => 'What is a Franchise Disclosure Document?', 'a' => 'A document franchisors must give buyers, with 23 sections covering fees, costs, litigation and the agreement.'],
                    ['q' => 'How long do I have to review it?', 'a' => 'Under the FTC Franchise Rule, at least 14 calendar days before signing or paying.'],
                    ['q' => 'Do franchise consultants charge candidates?', 'a' => 'Usually not; they are paid by the franchisor when a candidate signs. Ask each consultant.'],
                    ['q' => 'What details help the receiving consultant?', 'a' => 'The candidate’s state, budget, industries of interest and timing.'],
                ],
                'glossary' => [
                    ['term' => 'FDD', 'definition' => 'Franchise Disclosure Document: the disclosure franchisors must give buyers.'],
                    ['term' => 'Franchise fee', 'definition' => 'The upfront fee paid to join a franchise system.'],
                    ['term' => 'Royalty', 'definition' => 'An ongoing fee, often a percentage of sales, paid to the franchisor.'],
                    ['term' => 'Territory', 'definition' => 'The area a franchise owner may operate in.'],
                ],
                'related' => ['business-brokers', 'commercial-real-estate', 'fitness', 'tax-accounting'],
                'sources' => [['title' => 'FDD waiting period under the FTC Franchise Rule (Franchise Law Solutions)', 'url' => 'https://www.franchiselawsolutions.com/fdd/waiting-period/'], ['title' => '16 CFR Part 436, Franchise Rule (Cornell LII)', 'url' => 'https://www.law.cornell.edu/cfr/text/16/436.3']],
            ], 'franchise consultants', 'consultant', 'candidate'),

            'telecom' => self::trade([
                'summary' => 'Telecom referrals connect a business needing phone, internet or connectivity to a telecom partner serving its state. On {site_name}, partners pass customers they can’t serve to the verified partner who holds that state’s seat, track each sale, and agree any referral fee up front.',
                'intro' => 'Providers and coverage change at state lines. The {industry} network lets you pass a customer to a verified partner who sells the services available where they are, and follow the order to installation.',
                'how' => 'Refer the customer to the partner who holds the seat for their state, with locations and needs. The receiving partner accepts within the deadline, quotes and updates you through installation. Any referral fee is agreed first.',
                'request' => 'Need business internet or phone service? Tell us your state, and we’ll introduce you to a verified telecom partner.',
                'state_summary' => 'Find a verified telecom and internet partner in {state}. Partners on {site_name} are chosen for how well they serve referred businesses.',
                'city_summary' => 'Need business internet or phone in {city}, {state}? {site_name} introduces you to the verified partner who serves {state}.',
                'facts' => [
                    'covers' => 'Agents and resellers selling business internet, phone, mobile and cloud communications.',
                    'cost' => '',
                    'licensing' => 'Sales partners don’t need a license; since 2024 the FCC has required home internet providers to show a “Broadband Facts” label with prices, fees and speeds.',
                    'fees' => 'Partners may agree a referral fee or a share of ongoing commission with each other, as a share of what the receiving partner earns.',
                ],
                'cost_note' => 'Prices depend on the service, location and contract, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Which providers can you offer at our address?', 'What speeds are guaranteed, and what are typical?', 'How long is the contract, and what are early termination fees?', 'What does installation cost?', 'Who do we call for support?', 'Can you show the provider’s price label?'],
                'faqs' => [
                    ['q' => 'What is a broadband label?', 'a' => 'A “Broadband Facts” label the FCC requires home internet providers to show, with prices, fees and speeds, since 2024.'],
                    ['q' => 'What details help the receiving partner?', 'a' => 'The business’s locations, number of users, current provider and contract end date.'],
                    ['q' => 'Can I refer a business with offices in several states?', 'a' => 'Yes. Send a referral to the partner holding each state’s seat, or one that covers them all.'],
                    ['q' => 'How are telecom partners paid?', 'a' => 'Usually by the provider, often as a share of the monthly bill for the life of the contract.'],
                ],
                'glossary' => [
                    ['term' => 'Fiber', 'definition' => 'Internet carried over fiber-optic cable, usually the fastest option.'],
                    ['term' => 'VoIP', 'definition' => 'Phone calls carried over the internet.'],
                    ['term' => 'SLA', 'definition' => 'Service level agreement: the provider’s promise on uptime and repairs.'],
                    ['term' => 'Residual', 'definition' => 'Ongoing commission paid for as long as the customer stays.'],
                ],
                'related' => ['business-software', 'merchant-services', 'home-security'],
                'sources' => [['title' => 'FCC requires most broadband labels by April 2024 (Light Reading)', 'url' => 'https://www.lightreading.com/broadband/fcc-requires-most-broadband-labels-by-april-2024']],
            ], 'telecom partners', 'partner', 'customer'),

            'business-software' => self::trade([
                'summary' => 'Business software referrals connect a company to a software partner who sells, implements and supports the right product in its state. On {site_name}, partners pass companies they can’t serve to the verified partner who holds that state’s seat, track each deal, and agree any fee up front.',
                'intro' => 'Resellers and implementation partners are often limited by territory or product line. The {industry} network lets you pass a company to a verified partner who can deliver, and follow the deal from demo to go-live.',
                'how' => 'Refer the company to the partner who holds the seat for their state, with the software need and size. The receiving partner accepts within the deadline, runs discovery and demos, and updates you. Any referral fee is agreed first.',
                'request' => 'Choosing business software? Tell us your state and what you need, and we’ll introduce you to a verified software partner.',
                'state_summary' => 'Find a verified business software partner in {state}. Partners on {site_name} are chosen for how well they serve referred companies.',
                'city_summary' => 'Choosing business software in {city}, {state}? {site_name} introduces you to the verified partner who serves {state}.',
                'facts' => [
                    'covers' => 'Resellers and implementation partners selling accounting, ERP, CRM, payroll and other business software.',
                    'cost' => '',
                    'licensing' => 'Software partners don’t need a license; vendors certify their own partners.',
                    'fees' => 'Partners may agree a referral fee with each other, as a share of what the receiving partner earns from licenses or services.',
                ],
                'cost_note' => 'Prices depend on the product, users and services, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Are you a certified partner for this software?', 'How many companies like ours have you implemented?', 'What is the total first-year cost, with services?', 'How long will implementation take?', 'Who supports us after go-live?', 'Can we speak with a reference customer?'],
                'faqs' => [
                    ['q' => 'What details help the receiving partner?', 'a' => 'The company’s state, size, current systems and what it wants the software to do.'],
                    ['q' => 'How are software partners paid?', 'a' => 'Through license margins or commissions from the vendor, plus fees for implementation and support.'],
                    ['q' => 'Can I refer a company with offices in several states?', 'a' => 'Yes, to the partner that holds the seat for its main office, or one per state.'],
                    ['q' => 'What is implementation?', 'a' => 'Setting up and configuring the software, moving data and training staff.'],
                ],
                'glossary' => [
                    ['term' => 'ERP', 'definition' => 'Enterprise resource planning: software that runs finance, inventory and operations together.'],
                    ['term' => 'CRM', 'definition' => 'Customer relationship management software for sales and service.'],
                    ['term' => 'SaaS', 'definition' => 'Software sold as an online subscription.'],
                    ['term' => 'Implementation partner', 'definition' => 'A firm that sets up and configures software for customers.'],
                ],
                'related' => ['telecom', 'merchant-services', 'staffing', 'tax-accounting'],
                'sources' => [],
            ], 'software partners', 'partner', 'company'),

            'merchant-services' => self::trade([
                'summary' => 'Merchant services referrals connect a business that takes card payments to a payment processing partner in its state. On {site_name}, partners pass merchants they can’t serve to the verified partner who holds that state’s seat, track each account, and agree any fee up front.',
                'intro' => 'Every business that takes cards needs a processor, and merchants trust the partner who set them up. The {industry} network lets you pass a merchant to a verified partner who can serve them well, and follow the account to activation.',
                'how' => 'Refer the merchant to the partner who holds the seat for their state, with their business type and monthly card volume. The receiving partner accepts within the deadline, quotes and updates you through approval and setup. Any referral fee is agreed first.',
                'request' => 'Need card processing for your business? Tell us your state, and we’ll introduce you to a verified payments partner.',
                'state_summary' => 'Find a verified merchant services partner in {state}. Partners on {site_name} are chosen for how well they serve referred merchants.',
                'city_summary' => 'Taking card payments in {city}, {state}? {site_name} introduces you to the verified payments partner who serves {state}.',
                'facts' => [
                    'covers' => 'Independent sales organizations and agents setting up card processing, terminals and online payments for businesses.',
                    'cost' => '',
                    'licensing' => 'Processing partners don’t need a state license; they sell through banks and processors registered with the card networks.',
                    'fees' => 'Partners may agree a referral fee or a share of ongoing residuals with each other, as a share of what the receiving partner earns.',
                ],
                'cost_note' => 'Processing rates depend on the business, card types and pricing model, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Which processor and bank will my account be with?', 'What pricing model do you use, and what is my effective rate?', 'Is there a contract term or early termination fee?', 'Do I buy or lease the terminal?', 'How quickly are funds deposited?', 'Who do I call for support?'],
                'faqs' => [
                    ['q' => 'What details help the receiving partner?', 'a' => 'The business type, state, monthly card volume, average sale and how it takes payments.'],
                    ['q' => 'What is interchange-plus pricing?', 'a' => 'A pricing model that passes on the card networks’ interchange fees plus a set markup.'],
                    ['q' => 'Should I lease a card terminal?', 'a' => 'Leases can cost far more than buying; ask for both prices and read the lease term.'],
                    ['q' => 'How are payments partners paid?', 'a' => 'Usually a share of the processing revenue for as long as the merchant stays (residuals).'],
                ],
                'glossary' => [
                    ['term' => 'ISO', 'definition' => 'Independent sales organization: a company that sells card processing for a bank.'],
                    ['term' => 'Interchange', 'definition' => 'The fee card-issuing banks set for each card transaction.'],
                    ['term' => 'Effective rate', 'definition' => 'Total processing fees divided by total card sales.'],
                    ['term' => 'Residuals', 'definition' => 'Ongoing payments to the partner based on the merchant’s processing.'],
                ],
                'related' => ['business-software', 'telecom', 'franchise-sales'],
                'sources' => [],
            ], 'payments partners', 'partner', 'merchant'),

            'commercial-cleaning' => self::trade([
                'summary' => 'Commercial cleaning referrals connect a facility manager to a trusted cleaning company where the building is. On {site_name}, cleaning companies pass facilities they can’t serve to the verified company that holds that city’s seat, track each account, and agree any referral fee up front.',
                'intro' => 'Facility managers with buildings in several cities want one name they can trust in each. The {industry} network lets you pass a site you can’t cover to a verified local crew, and follow the account through to the first clean.',
                'how' => 'Refer the facility to the company that holds the seat for its city, with the building type, size and schedule. The receiving company accepts within the deadline, walks the site, quotes and updates you. Any referral fee is agreed first.',
                'request' => 'Need cleaning for an office or facility? Tell us your city, and we’ll introduce you to a verified local cleaning company.',
                'state_summary' => 'Find a verified commercial cleaning company in {state}. Companies on {site_name} are insured and chosen for how well they serve referred facilities.',
                'city_summary' => 'Need commercial cleaning in {city}, {state}? {site_name} introduces you to the verified company that holds the {city} seat.',
                'facts' => [
                    'covers' => 'Janitorial and commercial cleaning companies serving offices, medical buildings, schools, retail and industrial sites.',
                    'cost' => '',
                    'licensing' => 'Commercial cleaners usually need only a business license; ask for proof of insurance and bonding.',
                    'fees' => 'Cleaning companies may agree a referral fee with each other, as a share of what the receiving company earns from the contract.',
                ],
                'cost_note' => 'Prices depend on the building, frequency and services, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Are you insured and bonded?', 'Do you employ your cleaners or use subcontractors?', 'How do you check quality?', 'What is included, and what costs extra?', 'How much notice is needed to end the contract?', 'Do you have experience with buildings like ours?'],
                'faqs' => [
                    ['q' => 'What details help the receiving company?', 'a' => 'The facility’s city, type, square footage, how often it needs cleaning and any special needs.'],
                    ['q' => 'Do commercial cleaners need a license?', 'a' => 'Usually just a business license; ask for insurance and bonding certificates.'],
                    ['q' => 'Can I refer a facility manager with sites in several cities?', 'a' => 'Yes. Send a referral to the company holding each city’s seat.'],
                    ['q' => 'What is bonding?', 'a' => 'A surety bond that protects the client if a cleaner causes a loss, such as theft.'],
                ],
                'glossary' => [
                    ['term' => 'Janitorial', 'definition' => 'Routine cleaning of commercial buildings.'],
                    ['term' => 'Bonded', 'definition' => 'Covered by a surety bond that protects clients against losses.'],
                    ['term' => 'Scope of work', 'definition' => 'The list of tasks and frequencies a contract covers.'],
                    ['term' => 'Day porter', 'definition' => 'A cleaner who works in the building during business hours.'],
                ],
                'related' => ['property-management', 'pest-control', 'landscaping', 'staffing'],
                'sources' => [],
            ], 'cleaning companies', 'cleaning company', 'facility'),

            'custom-builders' => self::trade([
                'summary' => 'Custom home builder referrals connect a client building a home to a licensed builder in the county where they will build. On {site_name}, builders pass clients they can’t serve to the verified builder who holds that county’s seat, track each project, and agree any referral fee up front.',
                'intro' => 'Clients buy land far from where you build, or want a style you don’t offer. The {industry} network lets you pass them to a verified builder in that county and follow the project from design to keys.',
                'how' => 'Refer the client to the builder who holds the seat for the county, with the lot and budget if known. The receiving builder accepts within the deadline, holds a design consultation and updates you through the build contract. Any referral fee is agreed first.',
                'request' => 'Building a custom home? Tell us the county, and we’ll introduce you to a verified local builder.',
                'state_summary' => 'Find a verified custom home builder in {state}. Builders on {site_name} hold any license {state} requires.',
                'city_summary' => 'Building a home near {city}, {state}? {site_name} introduces you to the verified builder who serves the area.',
                'facts' => [
                    'covers' => 'Builders who design and build one-off homes on the client’s own land.',
                    'cost' => '',
                    'licensing' => 'Residential builders are licensed or registered by many states, and some counties or cities add their own requirements.',
                    'fees' => 'Builders may agree a referral fee with each other where state law allows, as a share of what the receiving builder earns from the project.',
                ],
                'cost_note' => 'Costs depend on size, finishes, site and region, so we don’t publish a typical figure.',
                'lookups' => [B::NASCLA],
                'hire_questions' => ['Are you licensed and insured in this state?', 'Is your contract fixed-price or cost-plus?', 'How do you handle changes and allowances?', 'What is your typical build time?', 'What warranty do you give?', 'Can we visit homes you’ve built?'],
                'faqs' => [
                    ['q' => 'What is the difference between fixed-price and cost-plus?', 'a' => 'Fixed-price sets the price up front; cost-plus charges actual costs plus the builder’s fee.'],
                    ['q' => 'Do custom builders need a license?', 'a' => 'Many states license or register residential builders. Check the state licensing board.'],
                    ['q' => 'What details help the receiving builder?', 'a' => 'The county, whether the client owns the lot, the size and style of home, and the budget.'],
                    ['q' => 'What are allowances?', 'a' => 'Budget amounts in the contract for items chosen later, such as flooring or fixtures.'],
                ],
                'glossary' => [
                    ['term' => 'Cost-plus contract', 'definition' => 'A contract where the client pays actual costs plus the builder’s fee.'],
                    ['term' => 'Allowance', 'definition' => 'A budget for finishes chosen later.'],
                    ['term' => 'Change order', 'definition' => 'A signed change to the plans, price or schedule.'],
                    ['term' => 'Construction loan', 'definition' => 'A short-term loan that funds the build in stages.'],
                ],
                'related' => ['land', 'mortgage', 'landscaping', 'pools'],
                'sources' => [self::NASCLA_SRC],
            ], 'builders', 'builder', 'client'),

            'land' => self::trade([
                'summary' => 'Land referrals connect a buyer or seller of land to a licensed land broker in the county where it is. On {site_name}, brokers refer clients with consent, track each deal, and agree any referral fee up front as a share of the receiving broker’s commission.',
                'intro' => 'Land deals turn on local knowledge: zoning, access, utilities and soil. The {industry} network gives one verified land broker per county a seat, so you can hand a client to someone who knows the ground.',
                'how' => 'Refer the client to the broker who holds the seat for the county, with the acreage and intended use. The receiving broker accepts within the deadline, lists or searches and updates you through closing. Any referral fee is agreed first.',
                'request' => 'Buying or selling land? Tell us the county, and we’ll introduce you to a verified local land broker.',
                'state_summary' => 'Find a verified land broker in {state}. Brokers on {site_name} hold an active {state} real estate license.',
                'city_summary' => 'Buying or selling land near {city}, {state}? {site_name} introduces you to the verified land broker who serves the county.',
                'facts' => [
                    'covers' => 'Licensed brokers selling vacant land, lots, acreage, recreational and development land.',
                    'cost' => '',
                    'licensing' => 'Land brokers hold a state real estate license and can only act where they are licensed.',
                    'fees' => 'Broker-to-broker referral fees are allowed between licensed brokers, usually paid broker to broker as a share of the commission.',
                ],
                'cost_note' => 'Commissions and land prices vary by county and use, so we don’t publish a typical figure.',
                'lookups' => [B::ARELLO],
                'hire_questions' => ['Are you licensed in this state, and how much land do you sell here?', 'What do you know about the zoning and access?', 'Are utilities available, and at what cost?', 'Has the land been surveyed or perc-tested?', 'What is your commission?', 'How will you market the land?'],
                'faqs' => [
                    ['q' => 'What is a perc test?', 'a' => 'A soil test that shows whether land can support a septic system.'],
                    ['q' => 'What should buyers check before buying land?', 'a' => 'Zoning, legal access, utilities, survey, flood zone and any restrictions or easements.'],
                    ['q' => 'What details help the receiving broker?', 'a' => 'The county, acreage, intended use and budget or asking price.'],
                    ['q' => 'Can I get a loan for land?', 'a' => 'Often, but land loans usually need larger down payments than home loans.'],
                ],
                'glossary' => [
                    ['term' => 'Zoning', 'definition' => 'Local rules on how land may be used.'],
                    ['term' => 'Easement', 'definition' => 'A right for someone else to use part of the land, such as for utilities.'],
                    ['term' => 'Perc test', 'definition' => 'A soil test for septic suitability.'],
                    ['term' => 'Survey', 'definition' => 'A map of the land’s boundaries by a licensed surveyor.'],
                ],
                'related' => ['ranch-farm', 'custom-builders', 'real-estate', 'title-escrow'],
                'sources' => [self::ARELLO_SRC],
            ], 'land brokers', 'land broker', 'client'),

            'ranch-farm' => self::trade([
                'summary' => 'Ranch and farm referrals connect a buyer or seller of agricultural property to a licensed ranch and farm broker in that county. On {site_name}, brokers refer clients with consent, track each deal, and agree any referral fee up front as a share of the receiving broker’s commission.',
                'intro' => 'Ranches and farms are valued on water, soil, grazing and improvements that only local specialists understand. The {industry} network gives one verified specialist per county a seat, so your client gets someone who knows agricultural land.',
                'how' => 'Refer the client to the broker who holds the seat for the county, with acreage and use. The receiving broker accepts within the deadline, lists or searches and updates you through closing. Any referral fee is agreed first.',
                'request' => 'Buying or selling a ranch or farm? Tell us the county, and we’ll introduce you to a verified specialist.',
                'state_summary' => 'Find a verified ranch and farm broker in {state}. Brokers on {site_name} hold an active {state} real estate license.',
                'city_summary' => 'Buying or selling a ranch or farm near {city}, {state}? {site_name} introduces you to the verified broker who serves the county.',
                'facts' => [
                    'covers' => 'Licensed brokers selling working ranches, farms, timberland and recreational acreage.',
                    'cost' => '',
                    'licensing' => 'Ranch and farm brokers hold a state real estate license and can only act where they are licensed.',
                    'fees' => 'Broker-to-broker referral fees are allowed between licensed brokers, usually paid broker to broker as a share of the commission.',
                ],
                'cost_note' => 'Prices depend on acreage, water, soil and improvements, so we don’t publish a typical figure.',
                'lookups' => [B::ARELLO],
                'hire_questions' => ['Are you licensed here, and how many ranches or farms have you sold?', 'What water rights come with the property?', 'What are the mineral rights?', 'Are there leases, easements or conservation agreements?', 'What is your commission?', 'Who do you work with for farm and ranch lending?'],
                'faqs' => [
                    ['q' => 'What are water rights?', 'a' => 'Legal rights to use water from a source; in many western states they can be sold separately from the land.'],
                    ['q' => 'What are mineral rights?', 'a' => 'Rights to oil, gas and minerals below the surface, which may have been sold separately.'],
                    ['q' => 'What details help the receiving broker?', 'a' => 'The county, acreage, use (cattle, crops, hunting, timber) and budget or asking price.'],
                    ['q' => 'How are farms and ranches financed?', 'a' => 'Often through agricultural lenders, which understand land and operations.'],
                ],
                'glossary' => [
                    ['term' => 'Water rights', 'definition' => 'The legal right to use water from a source.'],
                    ['term' => 'Mineral rights', 'definition' => 'Ownership of what lies beneath the surface.'],
                    ['term' => 'Conservation easement', 'definition' => 'A permanent limit on development, often for tax benefits.'],
                    ['term' => 'AUM', 'definition' => 'Animal unit month: the forage one cow and calf need for a month.'],
                ],
                'related' => ['land', 'livestock', 'equipment', 'title-escrow'],
                'sources' => [self::ARELLO_SRC],
            ], 'ranch brokers', 'ranch broker', 'client'),

            'livestock' => self::trade([
                'summary' => 'Livestock referrals connect a producer to a trusted livestock marketer in their state. On {site_name}, marketers pass producers they can’t serve to the verified marketer who holds that state’s seat, track each sale, and agree any referral fee up front as a share of the receiving marketer’s earnings.',
                'intro' => 'Producers sell where the buyers and markets are, and that is not always local. The {industry} network lets you pass a producer to a verified marketer in their state who knows the buyers.',
                'how' => 'Refer the producer to the marketer who holds the seat for their state, with the class and number of head. The receiving marketer accepts within the deadline, arranges the sale and updates you. Any referral fee is agreed first.',
                'request' => 'Selling livestock? Tell us your state, and we’ll introduce you to a verified livestock marketer.',
                'state_summary' => 'Find a verified livestock marketer in {state}. Dealers and market agencies on {site_name} are registered and bonded with USDA as federal law requires.',
                'city_summary' => 'Selling livestock near {city}, {state}? {site_name} introduces you to the verified marketer who serves {state}.',
                'facts' => [
                    'covers' => 'Livestock dealers, order buyers, auction markets and marketing agents for cattle, hogs, sheep and goats.',
                    'cost' => '',
                    'licensing' => 'Under the Packers and Stockyards Act, livestock dealers and market agencies buying on commission must register with USDA and keep a bond, generally at least $10,000.',
                    'fees' => 'Marketers may agree a referral fee with each other, as a share of what the receiving marketer earns from the sale.',
                ],
                'cost_note' => 'Commissions and prices depend on the market and class of livestock, so we don’t publish a typical figure.',
                'lookups' => [['name' => 'USDA Packers and Stockyards: dealers and market agencies', 'url' => 'https://www.ams.usda.gov/node/28381']],
                'hire_questions' => ['Are you registered and bonded with USDA?', 'How do you sell: auction, video sale or private treaty?', 'What is your commission and what other charges apply?', 'When and how will I be paid?', 'Who handles transport and paperwork?', 'Which buyers do you work with?'],
                'faqs' => [
                    ['q' => 'Do livestock dealers need to register?', 'a' => 'Yes. Dealers and market agencies buying on commission must register with USDA and keep a bond under the Packers and Stockyards Act.'],
                    ['q' => 'Why does bonding matter?', 'a' => 'The bond protects sellers if a dealer fails to pay for livestock.'],
                    ['q' => 'What details help the receiving marketer?', 'a' => 'The state, the class and number of head, weights and timing.'],
                    ['q' => 'What is a video auction?', 'a' => 'A sale where livestock are shown on video and delivered later, so they don’t travel to a sale barn.'],
                ],
                'glossary' => [
                    ['term' => 'Order buyer', 'definition' => 'A buyer who purchases livestock on behalf of others.'],
                    ['term' => 'Market agency', 'definition' => 'A business that buys or sells livestock on commission.'],
                    ['term' => 'Private treaty', 'definition' => 'A sale negotiated directly between buyer and seller.'],
                    ['term' => 'Bond', 'definition' => 'Security that protects sellers against non-payment.'],
                ],
                'related' => ['ranch-farm', 'equipment', 'land'],
                'sources' => [['title' => 'Dealer, market agency buying on commission and clearing agency (USDA Agricultural Marketing Service)', 'url' => 'https://www.ams.usda.gov/node/28381']],
            ], 'livestock marketers', 'livestock marketer', 'producer'),

            'equipment' => self::trade([
                'summary' => 'Equipment referrals connect a buyer of construction, farm or material-handling equipment to a trusted dealer in their state. On {site_name}, dealers pass buyers they can’t serve to the verified dealer who holds that state’s seat, track each sale, and agree any referral fee up front.',
                'intro' => 'Equipment buyers need a dealer nearby for parts and service, and brands are sold by territory. The {industry} network lets you pass a buyer to a verified dealer in their state and follow the sale to delivery.',
                'how' => 'Refer the buyer to the dealer who holds the seat for their state, with the equipment type and whether they want new, used or rental. The receiving dealer accepts within the deadline, quotes and updates you. Any referral fee is agreed first.',
                'request' => 'Buying or renting equipment? Tell us your state, and we’ll introduce you to a verified dealer.',
                'state_summary' => 'Find a verified equipment dealer in {state}. Dealers on {site_name} are chosen for how well they serve referred buyers.',
                'city_summary' => 'Buying equipment near {city}, {state}? {site_name} introduces you to the verified dealer who serves {state}.',
                'facts' => [
                    'covers' => 'Dealers selling, renting and servicing construction, farm and material-handling equipment.',
                    'cost' => '',
                    'licensing' => 'Equipment dealers generally need a business license; brands appoint dealers by territory.',
                    'fees' => 'Dealers may agree a referral fee with each other, as a share of what the receiving dealer earns from the sale.',
                ],
                'cost_note' => 'Prices depend on the machine, hours, condition and market, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Are you an authorized dealer for this brand?', 'What warranty comes with it, new or used?', 'How many hours are on a used machine, and is there a service record?', 'Where is your nearest service and parts location?', 'Do you offer financing, leasing or rental?', 'Who handles delivery?'],
                'faqs' => [
                    ['q' => 'What details help the receiving dealer?', 'a' => 'The state, equipment type, new or used, budget and timing.'],
                    ['q' => 'Should I buy new or used?', 'a' => 'Used saves money but check hours, service records and warranty; nearby service matters either way.'],
                    ['q' => 'Can I rent before buying?', 'a' => 'Many dealers offer rent-to-own or apply rental payments to a purchase; ask.'],
                    ['q' => 'Why buy from a dealer near me?', 'a' => 'Parts, service and warranty work are easier when the dealer is close.'],
                ],
                'glossary' => [
                    ['term' => 'Hours', 'definition' => 'The running time on a machine’s meter, used like mileage.'],
                    ['term' => 'Authorized dealer', 'definition' => 'A dealer the manufacturer appoints to sell and service its brand.'],
                    ['term' => 'Rent-to-own', 'definition' => 'Renting equipment with the option to buy it, often crediting rent.'],
                    ['term' => 'Compact equipment', 'definition' => 'Small machines such as skid steers and mini excavators.'],
                ],
                'related' => ['ranch-farm', 'livestock', 'custom-builders', 'auto-sales'],
                'sources' => [],
            ], 'equipment dealers', 'dealer', 'buyer'),

            'funeral-preplanning' => [
                'summary' => 'Funeral pre-planning introductions connect a family to a licensed pre-need counselor in the county where services will be held. On {site_name}, counselors introduce families with consent and track each introduction, without referral fees, since pre-need sales are tightly regulated by states.',
                'intro' => 'Families plan ahead for parents who live far away, or move after planning. The {industry} network lets a counselor they trust introduce them to a verified, licensed counselor in the right county, with clear deadlines and no payment for the introduction.',
                'how' => 'Introduce the family to the counselor who holds the seat for the county. The receiving counselor accepts within the deadline, meets the family and updates you. Introductions are exchanged without payment; members earn credits and recognition with no cash value.',
                'request' => 'Planning ahead for a funeral? Tell us the county, and we’ll introduce you to a verified, licensed pre-need counselor.',
                'state_summary' => 'Find a licensed pre-need counselor in {state}. Counselors on {site_name} hold the license {state} requires, and introductions are made without referral payments.',
                'city_summary' => 'Planning a funeral in advance near {city}, {state}? {site_name} introduces you to the verified counselor who serves the county.',
                'facts' => [
                    'covers' => 'Licensed counselors who help families plan and prepay funerals, burial and cremation.',
                    'cost' => 'The national median cost of an adult funeral with viewing and burial was $8,300 in 2023, and $6,280 with viewing and cremation, according to the National Funeral Directors Association.',
                    'licensing' => 'Pre-need sales are regulated by each state, often through the funeral board or the insurance department when funded by insurance; the FTC Funeral Rule requires funeral homes to give itemized price lists.',
                    'fees' => 'States tightly regulate pre-need sales and how they are paid for, so this network allows no referral fees.',
                ],
                'cost_note' => 'NFDA medians are national figures from 2023 and don’t include cemetery costs such as a plot or headstone.',
                'lookups' => [B::NAIC],
                'hire_questions' => ['Are you licensed to sell pre-need plans in this state?', 'Is my payment held in trust or funded by insurance?', 'Which prices are guaranteed?', 'What happens if I move or change my mind?', 'Can I see the funeral home’s General Price List?', 'What isn’t included, such as cemetery costs?'],
                'faqs' => [
                    ['q' => 'Can pre-need counselors pay referral fees?', 'a' => 'States tightly regulate pre-need sales and how they are paid for. This network allows no referral fees.'],
                    ['q' => 'How much does a funeral cost?', 'a' => 'The NFDA reported a 2023 national median of $8,300 for a funeral with viewing and burial, and $6,280 with cremation, before cemetery costs.'],
                    ['q' => 'What is the FTC Funeral Rule?', 'a' => 'A federal rule requiring funeral homes to give itemized price lists and let families buy only what they want.'],
                    ['q' => 'How is prepaid money protected?', 'a' => 'Depending on the state, it is held in a trust or funded through an insurance policy; ask which.'],
                    ['q' => 'What if we move?', 'a' => 'Ask whether the plan can be transferred to another funeral home and at what cost.'],
                    ['q' => 'Why introduce families if no fee is allowed?', 'a' => 'Because the family gets a verified counselor in the right county. Members earn credits and recognition with no cash value.'],
                    ...B::network('counselor', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'Pre-need', 'definition' => 'Arranging and often paying for a funeral in advance.'],
                    ['term' => 'General Price List', 'definition' => 'The itemized price list the FTC Funeral Rule requires funeral homes to provide.'],
                    ['term' => 'Pre-need trust', 'definition' => 'An account that holds prepaid funeral money until it is needed.'],
                    ['term' => 'Guaranteed price', 'definition' => 'A price locked in at purchase, whatever it costs later.'],
                ],
                'related' => ['estate-planning', 'hospice', 'insurance', 'senior-living'],
                'sources' => [
                    ['title' => '2023 NFDA General Price List Study, December 8, 2023 (National Funeral Directors Association)', 'url' => 'https://nfda.org/news/media-center/nfda-news-releases/articletype/articleview/articleid/8134'],
                    ['title' => 'Funeral Industry Practices Rule (Federal Trade Commission)', 'url' => 'https://www.ftc.gov/legal-library/browse/rules/funeral-industry-practices-rule'],
                ],
                'checked' => self::CHECKED,
            ],

            'timeshare' => [
                'summary' => 'Vacation ownership introductions connect an owner or buyer to a licensed specialist in the state where the resort is. On {site_name}, specialists introduce clients with consent and track each introduction, without referral fees, keeping clear of state licensing rules on timeshare sales.',
                'intro' => 'Owners want to buy, sell or exit in other states, and they are targets for resale scams. The {industry} network lets a specialist they trust introduce them to a verified, licensed colleague, with no payment for the introduction.',
                'how' => 'Introduce the client to the specialist who holds the seat for the state where the property is. The receiving specialist accepts within the deadline, contacts the client and updates you. Introductions are exchanged without payment; members earn credits and recognition with no cash value.',
                'request' => 'Buying, selling or reviewing a timeshare? Tell us where it is, and we’ll introduce you to a verified, licensed specialist.',
                'state_summary' => 'Find a licensed vacation ownership specialist in {state}. Specialists on {site_name} hold the license {state} requires, and introductions are made without referral payments.',
                'city_summary' => 'Buying or selling a timeshare near {city}, {state}? {site_name} introduces you to the verified specialist who serves {state}.',
                'facts' => [
                    'covers' => 'Licensed specialists who sell, resell and advise on timeshares and vacation club memberships.',
                    'cost' => '',
                    'licensing' => 'Many states require timeshare sellers and resellers to hold a real estate license, and state law or the contract sets a cancellation (rescission) period after signing.',
                    'fees' => 'States regulate who may be paid for timeshare sales and referrals, so this network allows no referral fees.',
                ],
                'cost_note' => 'Prices and maintenance fees depend on the resort and ownership type, so we don’t publish a typical figure.',
                'lookups' => [B::ARELLO, ['name' => 'FTC: timeshares, vacation clubs and related scams', 'url' => 'https://consumer.ftc.gov/articles/timeshares-vacation-clubs-and-related-scams']],
                'hire_questions' => ['Are you licensed in the state where the resort is?', 'What are the annual maintenance fees, and how have they changed?', 'How long is my cancellation period?', 'For resale: do you charge anything before it sells?', 'Can I see the full contract before signing?', 'How does exchange or booking really work?'],
                'faqs' => [
                    ['q' => 'Can I cancel a timeshare purchase?', 'a' => 'Usually within a cancellation period set by state law or the contract. Send notice in writing, ideally by certified mail.'],
                    ['q' => 'How do I avoid resale scams?', 'a' => 'The FTC advises working with resellers that take fees after the sale, getting any refund policy in writing, and avoiding anyone who guarantees a sale.'],
                    ['q' => 'Do timeshare sellers need a license?', 'a' => 'Many states require a real estate license for timeshare sales and resales.'],
                    ['q' => 'Can specialists pay referral fees?', 'a' => 'States regulate who may be paid for timeshare sales and referrals. This network allows no referral fees.'],
                    ['q' => 'Why introduce clients if no fee is allowed?', 'a' => 'Because the client gets a verified, licensed specialist. Members earn credits and recognition with no cash value.'],
                    ['q' => 'What are maintenance fees?', 'a' => 'Yearly charges owners pay for upkeep, which usually rise over time.'],
                    ...B::network('specialist', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'Rescission period', 'definition' => 'The time after signing during which a buyer can cancel.'],
                    ['term' => 'Deeded ownership', 'definition' => 'Owning a real property interest in a specific unit and time.'],
                    ['term' => 'Points-based club', 'definition' => 'Ownership as points spent on stays across resorts.'],
                    ['term' => 'Maintenance fee', 'definition' => 'The yearly charge for upkeep of the resort.'],
                ],
                'related' => ['travel', 'real-estate', 'estate-planning'],
                'sources' => [['title' => 'Timeshares, vacation clubs and related scams (Federal Trade Commission)', 'url' => 'https://consumer.ftc.gov/articles/timeshares-vacation-clubs-and-related-scams'], self::ARELLO_SRC],
                'checked' => self::CHECKED,
            ],
        ];
    }
}
