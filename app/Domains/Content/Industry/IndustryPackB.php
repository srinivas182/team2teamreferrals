<?php

declare(strict_types=1);

namespace App\Domains\Content\Industry;

/**
 * Sprint 39b: drafted hub content for 20 more launch industries (property, home services, money, legal and care).
 * Same rules as IndustryPack: every outside fact is paraphrased from a listed source checked on the given date, cost
 * figures appear only where a reliable public source exists, and everything installs as a draft with "Review pending".
 */
final class IndustryPackB
{
    public const CHECKED = '2026-11-14';

    private const NASCLA = ['name' => 'State contractor licensing agencies (NASCLA directory)', 'url' => 'https://www.nascla.org/state-licensing-information'];

    private const NAIC = ['name' => 'State insurance departments (NAIC directory)', 'url' => 'https://content.naic.org/state-insurance-departments'];

    private const ARELLO = ['name' => 'ARELLO license verification (most states)', 'url' => 'https://www.arello.com/'];

    private const ABA = ['name' => 'Check a lawyer’s license and discipline (American Bar Association)', 'url' => 'https://www.americanbar.org/groups/professional_responsibility/resources/resources_for_the_public/consumer_faqs.html'];

    private const CARE_COMPARE = ['name' => 'Medicare Care Compare', 'url' => 'https://www.medicare.gov/care-compare/'];

    private const REG_X = ['title' => 'Regulation X §1024.14 (Consumer Financial Protection Bureau)', 'url' => 'https://www.consumerfinance.gov/rules-policy/regulations/1024/14/'];

    private const AKS = ['title' => 'Fraud and abuse laws (HHS Office of Inspector General)', 'url' => 'https://www.oig.hhs.gov/compliance/physician-education/fraud-abuse-laws/'];

    private const CARESCOUT = ['title' => 'Genworth and CareScout Cost of Care Survey 2024, March 2025 (Genworth)', 'url' => 'https://investor.genworth.com/news-events/press-releases/detail/982/'];

    private const COOLING_OFF = ['title' => 'Cooling-off period for sales made at home or other locations (Federal Trade Commission)', 'url' => 'https://www.ftc.gov/legal-library/browse/rules/cooling-period-sales-made-home-or-other-locations'];

    /**
     * The questions every network answers the same way: response speed and following a referral.
     *
     * @return list<array{q: string, a: string}>
     */
    private static function network(string $pro, string $what = 'referral'): array
    {
        return [
            ['q' => 'How quickly will the receiving '.$pro.' respond?', 'a' => 'Each network sets deadlines to accept or decline and to make first contact, with reminders before they pass; response speed counts toward the '.$pro.'’s trust score.'],
            ['q' => 'Can I follow the '.$what.' after I send it?', 'a' => 'Yes. The receiving '.$pro.' posts updates on a shared timeline until the work is done or the client decides not to go ahead.'],
        ];
    }

    /** @return list<array{q: string, a: string}> the fee questions for a Tier A trade */
    private static function feesOpen(string $pros, string $pro, string $client): array
    {
        return [
            ['q' => 'Can '.$pros.' pay referral fees?', 'a' => 'Usually yes, between businesses and where state law allows. On {site_name}, a referral fee is a share of what the receiving '.$pro.' earns from the job, or a flat amount, agreed before the '.$client.' is sent.'],
            ['q' => 'Does a referral cost the '.$client.' anything?', 'a' => 'No. Any referral fee is paid by the receiving '.$pro.' out of its own earnings.'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $c = self::CHECKED;

        return [
            'commercial-real-estate' => [
                'summary' => 'Commercial real estate referrals connect a business or investor to a licensed commercial broker in the market where they need property. On {site_name}, brokers refer clients with consent, track every deal, and agree any fee up front as a share of the receiving broker’s commission.',
                'intro' => 'Commercial clients expand into new markets, sell portfolios across state lines and need local knowledge of zoning, rents and tenants. The {industry} network gives one verified commercial broker per metro a seat, so you can hand a client to someone who knows the market and keep the relationship.',
                'how' => 'Refer the client to the broker who holds the seat for their market, with the asset type and their goals. The receiving broker accepts within the deadline, tours or lists the property and updates you through letter of intent, contract and closing. Any referral fee is agreed in the referral agreement before you send the client.',
                'request' => 'Looking for office, retail, industrial or investment property in another market? Tell us where, and we’ll introduce you to a verified commercial broker there.',
                'state_summary' => 'Find a verified commercial real estate broker in {state}. Brokers on {site_name} hold an active {state} license and are chosen for how well they serve referred clients.',
                'city_summary' => 'Leasing, buying or selling commercial property in {city}, {state}? {site_name} introduces you to the verified broker who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Licensed brokers who lease, buy and sell office, retail, industrial, multifamily and land for businesses and investors.',
                    'cost' => 'Commercial commissions are negotiated deal by deal and are not set by law.',
                    'licensing' => 'Commercial brokers hold the same state real estate license as residential agents and can only act in states where they are licensed.',
                    'fees' => 'Broker-to-broker referral fees are common in commercial real estate and are usually paid between licensed brokerages; state license law sets who can receive them.',
                ],
                'cost_note' => 'Commissions vary by deal type, size and market, so we don’t publish a typical rate.',
                'lookups' => [self::ARELLO],
                'hire_questions' => ['Are you licensed in this state, and with which brokerage?', 'Which property types and submarkets do you specialize in?', 'What recent deals like mine have you closed here?', 'How is your commission structured, and who pays it?', 'Do you represent landlords or sellers in this market too, and how do you handle conflicts?', 'How often will you report on progress?'],
                'faqs' => [
                    ['q' => 'What is a commercial real estate referral fee?', 'a' => 'A payment from the receiving brokerage to the referring brokerage when the referred deal closes, usually a negotiated share of the receiving broker’s commission.'],
                    ['q' => 'Is it legal to pay a commercial referral fee?', 'a' => 'Generally yes, between licensed brokers. State license law usually requires the fee to be paid to a licensed broker, broker to broker.'],
                    ['q' => 'Does the client pay the referral fee?', 'a' => 'No. It comes out of the receiving broker’s commission.'],
                    ['q' => 'How are commercial commissions set?', 'a' => 'They are negotiated deal by deal and depend on the deal type, size and market; no law sets them.'],
                    ['q' => 'What details help the receiving broker?', 'a' => 'The asset type, size, budget or price range, timing and the client’s goals, such as lease term or investment return.'],
                    ['q' => 'Why one broker per market?', 'a' => 'So every referral goes to a verified broker with a track record, instead of being shared with many competitors.'],
                    ...self::network('broker', 'deal'),
                ],
                'glossary' => [
                    ['term' => 'Letter of intent (LOI)', 'definition' => 'A non-binding summary of the main deal terms before a lease or sale contract is drafted.'],
                    ['term' => 'Tenant representation', 'definition' => 'A broker acting for a business that wants to lease space.'],
                    ['term' => 'Cap rate', 'definition' => 'A property’s net operating income divided by its price; a common measure of investment return.'],
                    ['term' => 'Triple net lease (NNN)', 'definition' => 'A lease where the tenant pays rent plus property taxes, insurance and maintenance.'],
                ],
                'related' => ['real-estate', 'property-management', 'business-brokers', 'land'],
                'sources' => [['title' => 'ARELLO license verification', 'url' => 'https://www.arello.com/']],
                'checked' => $c,
            ],

            'property-management' => [
                'summary' => 'Property management referrals connect a rental owner to a licensed property manager in the city where their property is. On {site_name}, managers refer owners with consent, track each referral, and agree any fee up front as a share of what the receiving manager earns.',
                'intro' => 'Owners move away, inherit property or buy rentals in other cities, and need someone local they can trust with tenants and maintenance. The {industry} network gives one verified property manager per city a seat, so you can hand an owner to a proven manager and stay in the loop.',
                'how' => 'Refer the owner to the manager who holds the seat for the property’s city, with the number of units. The receiving manager accepts within the deadline, sends a proposal and updates you until the management agreement is signed. Any referral fee is agreed in the referral agreement first.',
                'request' => 'Own a rental in another city? Tell us where, and we’ll introduce you to a verified local property manager.',
                'state_summary' => 'Find a verified property manager in {state}. Managers on {site_name} hold the license {state} requires and are chosen for how well they serve referred owners.',
                'city_summary' => 'Need a property manager in {city}, {state}? {site_name} introduces you to the verified manager who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Companies that manage rental homes and apartments for owners: tenants, rent collection, maintenance and inspections.',
                    'cost' => '',
                    'licensing' => 'Many states require anyone managing rentals for others to hold a real estate broker license or work under one, and some have a separate property manager license.',
                    'fees' => 'Managers may agree a referral fee with each other where state law allows, as a share of what the receiving manager earns from the account.',
                ],
                'cost_note' => 'Management fees depend on the market, property type and services, so we don’t publish a typical figure.',
                'lookups' => [self::ARELLO],
                'hire_questions' => ['Are you licensed to manage property in this state?', 'How many units like mine do you manage here?', 'What are your fees, including leasing and renewal fees?', 'How do you screen tenants?', 'How do you handle repairs, and what’s your approval limit?', 'What reports will I get, and how often?'],
                'faqs' => [
                    ['q' => 'Do property managers need a license?', 'a' => 'In many states, yes: usually a real estate broker license or working under a broker, and some states have a separate property manager license. Check the state’s real estate commission.'],
                    ...self::feesOpen('property managers', 'manager', 'owner'),
                    ['q' => 'What does a property manager do?', 'a' => 'Finds and screens tenants, collects rent, handles maintenance and inspections, and reports to the owner.'],
                    ['q' => 'What details help the receiving manager?', 'a' => 'The property’s city, type and number of units, whether it is rented now, and the owner’s goals.'],
                    ['q' => 'Can I refer an owner with properties in several cities?', 'a' => 'Yes. Send a referral to the manager holding the seat in each city.'],
                    ...self::network('manager'),
                ],
                'glossary' => [
                    ['term' => 'Management agreement', 'definition' => 'The contract between an owner and a property manager setting out services and fees.'],
                    ['term' => 'Leasing fee', 'definition' => 'A one-time charge for finding and placing a new tenant.'],
                    ['term' => 'Trust account', 'definition' => 'A separate bank account managers use to hold owners’ rents and tenants’ deposits.'],
                    ['term' => 'Vacancy rate', 'definition' => 'The share of units or time a property sits empty.'],
                ],
                'related' => ['real-estate', 'commercial-real-estate', 'moving', 'pest-control'],
                'sources' => [['title' => 'Property management licensing by state (Harbor Compliance)', 'url' => 'https://www.harborcompliance.com/property-management']],
                'checked' => $c,
            ],

            'title-escrow' => [
                'summary' => 'Title and escrow introductions connect a buyer, seller or agent closing in another county to a licensed title or escrow officer there. On {site_name}, officers introduce clients and track each closing, with no referral fees or anything of value exchanged, as federal rules require.',
                'intro' => 'Title and escrow work is local: county records, state rules and local closing customs all differ. When a client closes somewhere new, a title officer they trust can introduce them to a verified colleague in that county. The {industry} network makes those introductions reliable, with no payments for referrals of any kind.',
                'how' => 'Introduce the client to the officer who holds the seat for the county where they are closing. The receiving officer accepts within the deadline, opens the order and updates you through to clear to close. Introductions are exchanged without payment; members earn credits and recognition with no cash value.',
                'request' => 'Closing on a property in another county? Tell us where, and we’ll introduce you to a verified title or escrow officer there.',
                'state_summary' => 'Find a licensed title or escrow officer in {state}. Officers on {site_name} hold the license {state} requires, and introductions are made without any referral payment.',
                'city_summary' => 'Closing in {city}, {state}? {site_name} introduces you to the verified title or escrow officer who serves {city}.',
                'facts' => [
                    'covers' => 'Title agents and escrow officers who search title, issue title insurance and manage the money and documents at closing.',
                    'cost' => '',
                    'licensing' => 'Title insurance agents are licensed by each state’s department of insurance; escrow licensing varies by state.',
                    'fees' => 'Title and escrow are settlement services: under RESPA Section 8, no fee or thing of value may be given or accepted for referring that business on federally related mortgage loans.',
                ],
                'cost_note' => 'Title premiums and closing fees vary by state, and in some states rates are set or filed with regulators, so we don’t publish a national figure.',
                'lookups' => [self::NAIC],
                'hire_questions' => ['Are you licensed as a title agent in this state?', 'Which title insurers do you write for?', 'What fees will appear on the Closing Disclosure for your services?', 'How do you protect closing funds from wire fraud?', 'Who will be my contact through closing?', 'How will you keep me updated on title issues?'],
                'faqs' => [
                    ['q' => 'Can title companies pay referral fees?', 'a' => 'No. Title and escrow are settlement services, and RESPA Section 8 forbids giving or accepting anything of value for referring that business on federally related mortgage loans.'],
                    ['q' => 'What does a title company do?', 'a' => 'It searches public records for problems with the title, issues title insurance and, in many states, handles the closing and the money.'],
                    ['q' => 'What is the difference between title and escrow?', 'a' => 'Title work makes sure the property can be transferred and insured; escrow holds money and documents until every condition of the sale is met.'],
                    ['q' => 'How do I check a title agent’s license?', 'a' => 'Use your state department of insurance’s license lookup; the NAIC lists every state department.'],
                    ['q' => 'Why introduce clients if no fee is allowed?', 'a' => 'Because the client gets a verified officer in the right county. Members earn credits and recognition with no cash value.'],
                    ['q' => 'How do I avoid wire fraud at closing?', 'a' => 'Confirm wiring instructions by phone with your title or escrow officer, using a number you already know, before sending money.'],
                    ...self::network('officer', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'Title insurance', 'definition' => 'Insurance that protects the owner or lender against covered problems with a property’s title.'],
                    ['term' => 'Escrow', 'definition' => 'A neutral party holding money and documents until a sale’s conditions are met.'],
                    ['term' => 'Closing Disclosure', 'definition' => 'The five-page form showing a mortgage’s final terms and closing costs.'],
                    ['term' => 'Lien', 'definition' => 'A legal claim against a property, such as a mortgage or unpaid tax.'],
                ],
                'related' => ['real-estate', 'mortgage', 'home-inspection', 'insurance'],
                'sources' => [self::REG_X, ['title' => 'State insurance departments (NAIC)', 'url' => 'https://content.naic.org/state-insurance-departments']],
                'checked' => $c,
            ],

            'home-inspection' => [
                'summary' => 'Home inspection introductions connect a buyer or owner to a qualified home inspector in the city where the house is. On {site_name}, inspectors introduce clients and track each inspection, without referral fees, since many states bar inspectors from paying for referrals.',
                'intro' => 'Inspectors often get calls for houses outside their area. Rather than turning a buyer away, the {industry} network lets you introduce them to a verified inspector where the house is, held to clear deadlines, with no money changing hands for the introduction.',
                'how' => 'Introduce the client to the inspector who holds the seat for that city. The receiving inspector accepts within the deadline, books the inspection and updates you when the report is delivered. Introductions are exchanged without payment; members earn credits and recognition with no cash value.',
                'request' => 'Buying a home in another city? Tell us where, and we’ll introduce you to a verified home inspector there.',
                'state_summary' => 'Find a verified home inspector in {state}. Inspectors on {site_name} hold any license {state} requires, and introductions are made without referral payments.',
                'city_summary' => 'Need a home inspection in {city}, {state}? {site_name} introduces you to the verified inspector who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Home inspectors who examine a house’s structure and systems, usually before a sale, and write a report of what they find.',
                    'cost' => '',
                    'licensing' => 'Most states license or register home inspectors, but some do not regulate the profession at all, so requirements depend on the state.',
                    'fees' => 'Many states’ inspector rules prohibit paying referral or finder’s fees (Arizona’s rules are one example), so this network allows no referral fees.',
                ],
                'cost_note' => 'Inspection prices depend on the home’s size, age and the services added, so we don’t publish a typical figure.',
                'lookups' => [['name' => 'Find your state’s licensing board (ARELLO members include many inspector boards)', 'url' => 'https://www.arello.com/']],
                'hire_questions' => ['Are you licensed or certified in this state?', 'What does your inspection cover, and what does it exclude?', 'Can I attend the inspection?', 'When will I get the report, and what does a sample look like?', 'Do you carry errors and omissions insurance?', 'Do you offer radon, sewer or termite inspections?'],
                'faqs' => [
                    ['q' => 'Can home inspectors pay referral fees?', 'a' => 'Many states prohibit it. Arizona’s rules, for example, bar certified inspectors from paying commissions or finder’s fees for referrals. This network allows none.'],
                    ['q' => 'Do home inspectors need a license?', 'a' => 'In most states, yes, but some states don’t regulate home inspectors at all. Check the state’s licensing board.'],
                    ['q' => 'What does a home inspection cover?', 'a' => 'Usually the structure, roof, plumbing, electrical, heating and cooling systems, and visible defects. Specialist tests such as radon are often extra.'],
                    ['q' => 'Should the buyer attend the inspection?', 'a' => 'It helps: the inspector can explain findings in person. Ask the inspector first.'],
                    ['q' => 'Why introduce clients if no fee is allowed?', 'a' => 'Because the client gets a verified inspector in the right city. Members earn credits and recognition with no cash value.'],
                    ['q' => 'What details help the receiving inspector?', 'a' => 'The property address or area, its size and age, the inspection deadline in the contract and any extra tests wanted.'],
                    ...self::network('inspector', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'Inspection contingency', 'definition' => 'A clause letting a buyer renegotiate or cancel based on the inspection, within a set time.'],
                    ['term' => 'Radon test', 'definition' => 'A test for a radioactive gas that can build up in homes; usually an add-on.'],
                    ['term' => 'Errors and omissions insurance', 'definition' => 'Insurance that covers an inspector’s professional mistakes.'],
                    ['term' => 'Pre-listing inspection', 'definition' => 'An inspection a seller orders before putting the home on the market.'],
                ],
                'related' => ['real-estate', 'mortgage', 'title-escrow', 'pest-control', 'roofing'],
                'sources' => [
                    ['title' => 'Ariz. Admin. Code R4-30-301.01, home inspector rules of professional conduct (Cornell LII)', 'url' => 'https://www.law.cornell.edu/regulations/arizona/Ariz-Admin-Code-SS-R4-30-301.01'],
                    ['title' => 'Map reveals home inspection blind spots, August 2026 (Newsweek, citing ASHI)', 'url' => 'https://www.newsweek.com/map-reveals-home-inspection-blind-spots-where-anyone-can-be-an-inspector-12295236'],
                ],
                'checked' => $c,
            ],

            'medicare' => [
                'summary' => 'Medicare agent introductions connect a beneficiary to a licensed, certified Medicare agent where they live. On {site_name}, agents introduce beneficiaries with consent and track each introduction, without any referral fees between agents, keeping well inside CMS marketing and compensation rules.',
                'intro' => 'Beneficiaries who move, or whose family lives far away, need an agent licensed and certified to sell plans where they live. The {industry} network lets an agent they trust introduce them to a verified local agent, with clear deadlines and no payments for the introduction.',
                'how' => 'Introduce the beneficiary to the agent who holds the seat for their county, with what they are interested in. The receiving agent accepts within the deadline, contacts them and updates you. Introductions are exchanged without payment; members earn credits and recognition with no cash value.',
                'request' => 'Need help choosing a Medicare plan where you live? Tell us your county, and we’ll introduce you to a verified, licensed Medicare agent.',
                'state_summary' => 'Find a licensed Medicare agent in {state}. Agents on {site_name} hold a {state} health insurance license, and introductions are made without referral payments.',
                'city_summary' => 'Looking at Medicare options in {city}, {state}? {site_name} introduces you to the verified Medicare agent who serves {city}.',
                'facts' => [
                    'covers' => 'Licensed agents who help people choose Medicare Advantage, Medicare Supplement and Part D drug plans.',
                    'cost' => 'The standard Medicare Part B premium for 2026 is $202.90 a month, with a $283 annual deductible.',
                    'licensing' => 'Agents need a state health insurance license where they sell, an appointment from each plan, and the annual training and testing that CMS requires to sell Medicare Advantage and Part D plans.',
                    'fees' => 'CMS rules cap what plans may pay agents, including referral fees, and this network allows no referral fees between agents.',
                ],
                'cost_note' => 'The Part B figures are CMS’s standard 2026 amounts; some people pay more based on income, and plan premiums vary by plan and county.',
                'lookups' => [self::NAIC, ['name' => 'NIPR producer lookup', 'url' => 'https://nipr.com/producer-lookup']],
                'hire_questions' => ['Are you licensed in my state and certified for this year’s plans?', 'Which companies’ plans can you offer in my county?', 'Can you check my doctors and prescriptions against each plan?', 'How are you paid, and does it cost me anything?', 'Will you help me again at the next enrollment period?', 'How do I reach you if I have a problem with my plan?'],
                'faqs' => [
                    ['q' => 'Do Medicare agents charge beneficiaries?', 'a' => 'No. Agents are paid by the plans, under compensation limits set by CMS.'],
                    ['q' => 'Can Medicare agents pay each other referral fees?', 'a' => 'CMS caps what plans may pay agents, including referral fees. To keep it simple and compliant, this network allows no referral fees between agents.'],
                    ['q' => 'What is the 2026 Part B premium?', 'a' => 'The standard Part B premium is $202.90 a month in 2026, with a $283 deductible. Some people pay more based on income.'],
                    ['q' => 'What licenses does a Medicare agent need?', 'a' => 'A state health insurance license, an appointment from each plan they sell, and annual Medicare training and testing for Medicare Advantage and Part D.'],
                    ['q' => 'What is the difference between Medicare Advantage and Medicare Supplement?', 'a' => 'Medicare Advantage plans replace Original Medicare with a private plan; Medicare Supplement plans help pay costs Original Medicare leaves.'],
                    ['q' => 'Is my information shared before I agree?', 'a' => 'No. You’re introduced only after you agree, and the receiving agent sees your details only after accepting.'],
                    ...self::network('agent', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'Medicare Advantage (Part C)', 'definition' => 'Private plans approved by Medicare that cover hospital and medical services, often with drug coverage.'],
                    ['term' => 'Medicare Supplement (Medigap)', 'definition' => 'Private insurance that helps pay costs Original Medicare doesn’t, such as coinsurance.'],
                    ['term' => 'Part D', 'definition' => 'Medicare prescription drug coverage.'],
                    ['term' => 'Annual Enrollment Period', 'definition' => 'October 15 to December 7, when people can change Medicare Advantage and Part D plans.'],
                ],
                'related' => ['insurance', 'financial-advisors', 'senior-living'],
                'sources' => [
                    ['title' => 'Medicare Part B premium and deductible for 2026, Federal Register, November 19, 2025 (GovInfo)', 'url' => 'https://www.govinfo.gov/content/pkg/FR-2025-11-19/html/2025-20251.htm'],
                    ['title' => '42 CFR §422.2274, agent and broker compensation (Cornell LII)', 'url' => 'https://www.law.cornell.edu/cfr/text/42/422.2274'],
                ],
                'checked' => $c,
            ],

            'roofing' => [
                'summary' => 'Roofing referrals connect a homeowner to a licensed roofing contractor where they live. On {site_name}, roofers pass homeowners they can’t serve to the verified roofer who holds that city’s seat, track every job, and agree any referral fee up front as a share of the receiving roofer’s earnings.',
                'intro' => 'Roofers get calls from outside their area, especially after storms. Rather than leaving a homeowner to storm chasers, the {industry} network lets you pass them to a verified local roofer who answers quickly and keeps you informed.',
                'how' => 'Send the homeowner to the roofer who holds the seat for their city, with what they need and whether there is an insurance claim. The receiving roofer accepts within the deadline, inspects, quotes and updates you through the job. Any referral fee is agreed first.',
                'request' => 'Need a roof repaired or replaced? Tell us your city, and we’ll introduce you to a verified local roofer.',
                'state_summary' => 'Find a verified roofing contractor in {state}. Roofers on {site_name} hold any license {state} requires and are chosen for how well they serve referred homeowners.',
                'city_summary' => 'Need a roofer in {city}, {state}? {site_name} introduces you to the verified roofer who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Contractors who repair, replace and inspect roofs, including storm and insurance work.',
                    'cost' => '',
                    'licensing' => 'Roofing licensing is set by each state (and sometimes city), from a dedicated roofing license to a general contractor license; some states have none.',
                    'fees' => 'Roofers may agree a referral fee with each other where state law allows, as a share of what the receiving roofer earns from the job.',
                ],
                'cost_note' => 'Roof prices depend on size, pitch, materials and region, so we don’t publish a typical figure.',
                'lookups' => [self::NASCLA],
                'hire_questions' => ['Are you licensed and insured in this state?', 'Will you give me a written estimate with materials and warranty?', 'Who will do the work: your crew or subcontractors?', 'How do you handle insurance claims?', 'Who pulls the permit?', 'What warranty covers your workmanship?'],
                'faqs' => [
                    ...self::feesOpen('roofers', 'roofer', 'homeowner'),
                    ['q' => 'Do roofers need a license?', 'a' => 'It depends on the state and sometimes the city. Check the state contractor licensing board; NASCLA links to them.'],
                    ['q' => 'How do I avoid storm chasers?', 'a' => 'Choose a licensed, insured local roofer, get a written estimate, and don’t sign over insurance payments before work is agreed.'],
                    ['q' => 'What details help the receiving roofer?', 'a' => 'The city, what’s needed (repair, replacement, inspection, storm damage) and whether there is an insurance claim.'],
                    ['q' => 'What if the roofer can’t take the job?', 'a' => 'They decline with a reason, and you can send the homeowner to someone else straight away.'],
                    ...self::network('roofer', 'job'),
                ],
                'glossary' => [
                    ['term' => 'Square', 'definition' => '100 square feet of roof; roofs are measured and priced in squares.'],
                    ['term' => 'Underlayment', 'definition' => 'The water-resistant layer under shingles.'],
                    ['term' => 'Flashing', 'definition' => 'Metal pieces that seal joints around chimneys, vents and valleys.'],
                    ['term' => 'Tear-off', 'definition' => 'Removing old roofing down to the deck before re-roofing.'],
                ],
                'related' => ['solar', 'windows-siding', 'home-inspection', 'insurance'],
                'sources' => [['title' => 'State licensing information (NASCLA)', 'url' => 'https://www.nascla.org/state-licensing-information']],
                'checked' => $c,
            ],

            'hvac' => [
                'summary' => 'HVAC referrals connect a homeowner to a licensed heating and cooling contractor where they live. On {site_name}, HVAC pros pass customers they can’t serve to the verified pro who holds that city’s seat, track each job, and agree any referral fee up front as a share of the receiving pro’s earnings.',
                'intro' => 'Heating and cooling problems can’t wait, and customers call whoever they know, even out of area. The {industry} network lets you pass them to a verified local HVAC pro who answers quickly, so the customer gets help and you keep their trust.',
                'how' => 'Send the homeowner to the HVAC pro who holds the seat for their city, with what they need. The receiving pro accepts within the deadline, visits, quotes and updates you through the job. Any referral fee is agreed first.',
                'request' => 'Heating or cooling problem? Tell us your city, and we’ll introduce you to a verified local HVAC pro.',
                'state_summary' => 'Find a verified HVAC contractor in {state}. Pros on {site_name} hold any license {state} requires and EPA refrigerant certification.',
                'city_summary' => 'Need heating or cooling help in {city}, {state}? {site_name} introduces you to the verified HVAC pro who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Contractors who install, repair and maintain furnaces, air conditioners, heat pumps and ductwork.',
                    'cost' => '',
                    'licensing' => 'Technicians who work with refrigerants in air conditioners and heat pumps must hold EPA Section 608 certification; contractor licensing is set by each state.',
                    'fees' => 'HVAC companies may agree a referral fee with each other where state law allows, as a share of what the receiving company earns from the job.',
                ],
                'cost_note' => 'Prices depend on the system, home size and region, so we don’t publish a typical figure.',
                'lookups' => [self::NASCLA],
                'hire_questions' => ['Are you licensed in this state, and are your technicians EPA 608 certified?', 'Will you size the system for my home rather than match the old one?', 'What’s included in the quote, and what could change it?', 'What warranties cover parts and labor?', 'Do you offer a maintenance plan?', 'Who handles permits?'],
                'faqs' => [
                    ...self::feesOpen('HVAC companies', 'HVAC pro', 'homeowner'),
                    ['q' => 'What is EPA Section 608 certification?', 'a' => 'Federal certification required for technicians who maintain, service, repair or dispose of equipment containing refrigerants.'],
                    ['q' => 'Do HVAC contractors need a license?', 'a' => 'Most states license HVAC or mechanical contractors; requirements vary. Check the state licensing board.'],
                    ['q' => 'What details help the receiving HVAC pro?', 'a' => 'The city, the problem or project (repair, new system, maintenance, ducts) and how urgent it is.'],
                    ['q' => 'What if it’s an emergency?', 'a' => 'Say so in the referral; the receiving pro sees it straight away and can accept or decline quickly.'],
                    ...self::network('HVAC pro', 'job'),
                ],
                'glossary' => [
                    ['term' => 'SEER2', 'definition' => 'A rating of an air conditioner’s or heat pump’s cooling efficiency; higher is more efficient.'],
                    ['term' => 'Heat pump', 'definition' => 'A system that moves heat in or out of a home to heat and cool it.'],
                    ['term' => 'Load calculation', 'definition' => 'A calculation of how much heating and cooling a home needs, used to size a system.'],
                    ['term' => 'Refrigerant', 'definition' => 'The fluid that carries heat in cooling systems; handling it requires EPA certification.'],
                ],
                'related' => ['solar', 'home-warranty', 'windows-siding', 'water-treatment'],
                'sources' => [
                    ['title' => 'Section 608 technician certification requirements (US EPA)', 'url' => 'https://epa.gov/section608/section-608-technician-certification-requirements'],
                    ['title' => 'State licensing information (NASCLA)', 'url' => 'https://www.nascla.org/state-licensing-information'],
                ],
                'checked' => $c,
            ],

            'windows-siding' => [
                'summary' => 'Windows and siding referrals connect a homeowner to a licensed contractor where they live. On {site_name}, contractors pass homeowners they can’t serve to the verified contractor who holds that city’s seat, track each job, and agree any referral fee up front as a share of the receiving contractor’s earnings.',
                'intro' => 'Window and siding projects are big decisions, and homeowners ask the contractors they trust for names, even in other cities. The {industry} network lets you pass them to a verified local contractor and see the project through.',
                'how' => 'Send the homeowner to the contractor who holds the seat for their city. The receiving contractor accepts within the deadline, measures, quotes and updates you through installation. Any referral fee is agreed first.',
                'request' => 'Replacing windows or siding? Tell us your city, and we’ll introduce you to a verified local contractor.',
                'state_summary' => 'Find a verified window and siding contractor in {state}. Contractors on {site_name} hold any license {state} requires.',
                'city_summary' => 'Replacing windows or siding in {city}, {state}? {site_name} introduces you to the verified contractor who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Contractors who replace and install windows, doors and exterior siding.',
                    'cost' => '',
                    'licensing' => 'Contractor licensing is set by each state; for homes built before 1978, firms disturbing painted surfaces must be EPA Lead-Safe Certified under the Renovation, Repair and Painting rule.',
                    'fees' => 'Contractors may agree a referral fee with each other where state law allows, as a share of what the receiving contractor earns from the job.',
                ],
                'cost_note' => 'Prices depend on the number and type of windows, materials and region, so we don’t publish a typical figure.',
                'lookups' => [self::NASCLA, ['name' => 'Find an EPA Lead-Safe Certified firm', 'url' => 'https://epa.gov/lead/i-am-planning-renovate-my-home-how-can-i-find-lead-safe-certified-firm']],
                'hire_questions' => ['Are you licensed and insured in this state?', 'If my home was built before 1978, are you EPA Lead-Safe Certified?', 'Which products do you install, and what are their ratings?', 'Who does the installation?', 'What warranty covers products and labor?', 'Can I cancel if I sign at home?'],
                'faqs' => [
                    ...self::feesOpen('window and siding contractors', 'contractor', 'homeowner'),
                    ['q' => 'What is the EPA lead-safe rule?', 'a' => 'For homes built before 1978, firms paid to do work that disturbs painted surfaces must be EPA Lead-Safe Certified and follow lead-safe work practices.'],
                    ['q' => 'Can I cancel a contract signed at home?', 'a' => 'Under the FTC’s Cooling-Off Rule, many sales made at your home for more than $25 can be cancelled within three business days. Ask for the cancellation form.'],
                    ['q' => 'What details help the receiving contractor?', 'a' => 'The city, the number of windows or the area of siding, the home’s age and the timing.'],
                    ['q' => 'What if the contractor can’t take the job?', 'a' => 'They decline with a reason, and you can send the homeowner to someone else straight away.'],
                    ...self::network('contractor', 'job'),
                ],
                'glossary' => [
                    ['term' => 'U-factor', 'definition' => 'How well a window keeps heat in; lower is better.'],
                    ['term' => 'Low-E glass', 'definition' => 'Glass with a coating that reflects heat to improve efficiency.'],
                    ['term' => 'RRP rule', 'definition' => 'The EPA Renovation, Repair and Painting rule for lead-safe work in pre-1978 homes.'],
                    ['term' => 'Fiber cement', 'definition' => 'A durable siding made from cement, sand and fibers.'],
                ],
                'related' => ['roofing', 'hvac', 'solar', 'home-security'],
                'sources' => [
                    ['title' => 'How can I find a lead-safe certified firm? (US EPA)', 'url' => 'https://epa.gov/lead/i-am-planning-renovate-my-home-how-can-i-find-lead-safe-certified-firm'],
                    self::COOLING_OFF,
                ],
                'checked' => $c,
            ],

            'pest-control' => [
                'summary' => 'Pest control referrals connect a customer to a licensed pest control company where they live. On {site_name}, companies pass customers they can’t serve to the verified company that holds that city’s seat, track each job, and agree any referral fee up front as a share of the receiving company’s earnings.',
                'intro' => 'Customers move, own second homes or call about properties outside your routes. The {industry} network lets you pass them to a verified, licensed pest control company nearby, and see the job through.',
                'how' => 'Send the customer to the company that holds the seat for their city, with the pest and property type. The receiving company accepts within the deadline, inspects, treats and updates you. Any referral fee is agreed first.',
                'request' => 'Pest problem? Tell us your city, and we’ll introduce you to a verified, licensed local pest control company.',
                'state_summary' => 'Find a licensed pest control company in {state}. Companies on {site_name} use applicators certified by {state}.',
                'city_summary' => 'Need pest control in {city}, {state}? {site_name} introduces you to the verified company that holds the {city} seat.',
                'facts' => [
                    'covers' => 'Licensed companies that inspect for and treat insects, termites, rodents and other pests in homes and businesses.',
                    'cost' => '',
                    'licensing' => 'People who apply restricted-use pesticides must be certified under standards set by the EPA and run by each state’s pesticide agency; most states also license pest control businesses.',
                    'fees' => 'Pest control companies may agree a referral fee with each other where state law allows, as a share of what the receiving company earns.',
                ],
                'cost_note' => 'Prices depend on the pest, property size and treatment plan, so we don’t publish a typical figure.',
                'lookups' => [['name' => 'State pesticide regulatory agencies (National Pesticide Information Center)', 'url' => 'https://npic.orst.edu/regstate.html']],
                'hire_questions' => ['Are your company and technicians licensed in this state?', 'Which products will you use, and are they safe for children and pets?', 'Is this a one-time treatment or a plan?', 'What guarantee do you offer if pests return?', 'Do you need access inside the home?', 'Will you give me the product labels?'],
                'faqs' => [
                    ...self::feesOpen('pest control companies', 'company', 'customer'),
                    ['q' => 'Do pest control technicians need a license?', 'a' => 'Applicators of restricted-use pesticides must be certified, and most states license pest control businesses and technicians. Check the state pesticide agency.'],
                    ['q' => 'Are treatments safe for pets and children?', 'a' => 'Ask the company which products it uses and for the labels, and follow its instructions about re-entry.'],
                    ['q' => 'What details help the receiving company?', 'a' => 'The city, the pest, the property type and how long the problem has been going on.'],
                    ['q' => 'Can I refer a business customer?', 'a' => 'Yes, if the receiving company serves commercial properties; say so in the referral.'],
                    ...self::network('company', 'job'),
                ],
                'glossary' => [
                    ['term' => 'Certified applicator', 'definition' => 'A person certified by the state to apply restricted-use pesticides.'],
                    ['term' => 'Restricted-use pesticide', 'definition' => 'A pesticide the EPA allows only certified applicators to use.'],
                    ['term' => 'Integrated pest management', 'definition' => 'Controlling pests by combining prevention, monitoring and targeted treatment.'],
                    ['term' => 'WDI inspection', 'definition' => 'A wood-destroying insect (termite) inspection, often required in home sales.'],
                ],
                'related' => ['home-inspection', 'property-management', 'landscaping', 'commercial-cleaning'],
                'sources' => [
                    ['title' => 'Certification standards for pesticide applicators (US EPA)', 'url' => 'https://www.epa.gov/pesticide-worker-safety/certification-standards-pesticide-applicators/'],
                    ['title' => 'State pesticide regulatory agencies (National Pesticide Information Center)', 'url' => 'https://npic.orst.edu/regstate.html'],
                ],
                'checked' => $c,
            ],

            'home-security' => [
                'summary' => 'Home security referrals connect a customer to a licensed alarm and security installer where they live. On {site_name}, installers pass customers to the verified installer who holds that city’s seat, track each install, and agree any referral fee up front as a share of the receiving installer’s earnings.',
                'intro' => 'Customers who move want the same peace of mind in their new home. The {industry} network lets you pass them to a verified, licensed local installer instead of a door-to-door salesperson, and see the install through.',
                'how' => 'Send the customer to the installer who holds the seat for their city. The receiving installer accepts within the deadline, designs the system, installs it and updates you. Any referral fee is agreed first.',
                'request' => 'Want a security system for your home? Tell us your city, and we’ll introduce you to a verified local installer.',
                'state_summary' => 'Find a verified home security installer in {state}. Installers on {site_name} hold any alarm or contractor license {state} requires.',
                'city_summary' => 'Securing a home in {city}, {state}? {site_name} introduces you to the verified installer who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Companies that design, install and monitor alarm, camera, smart-lock and other home security systems.',
                    'cost' => '',
                    'licensing' => 'Many states license alarm and security system installers, often through a state licensing board; requirements vary.',
                    'fees' => 'Installers may agree a referral fee with each other where state law allows, as a share of what the receiving installer earns.',
                ],
                'cost_note' => 'Prices depend on equipment, monitoring and contract length, so we don’t publish a typical figure.',
                'lookups' => [self::NASCLA],
                'hire_questions' => ['Are you licensed as an alarm installer in this state?', 'Do I own the equipment or lease it?', 'How long is the monitoring contract, and what does it cost to cancel?', 'Who monitors the system, and where?', 'What happens if I move?', 'Can I cancel if I sign at home?'],
                'faqs' => [
                    ...self::feesOpen('security installers', 'installer', 'customer'),
                    ['q' => 'Do alarm installers need a license?', 'a' => 'In many states, yes; requirements vary. Check the state licensing board.'],
                    ['q' => 'Can I cancel a security contract signed at home?', 'a' => 'Under the FTC’s Cooling-Off Rule, many sales made at your home for more than $25 can be cancelled within three business days.'],
                    ['q' => 'Should I own or lease the equipment?', 'a' => 'Owning usually costs more up front but avoids long contracts; ask about both and the cancellation terms.'],
                    ['q' => 'What details help the receiving installer?', 'a' => 'The city, the home type, what the customer wants to protect and whether they want monitoring.'],
                    ...self::network('installer', 'install'),
                ],
                'glossary' => [
                    ['term' => 'Monitoring', 'definition' => 'A service that watches alarm signals and contacts the owner or emergency services.'],
                    ['term' => 'Monitoring contract', 'definition' => 'An agreement for monitoring over a set term, often with cancellation fees.'],
                    ['term' => 'Smart lock', 'definition' => 'A door lock that can be controlled from a phone.'],
                    ['term' => 'Cooling-Off Rule', 'definition' => 'An FTC rule giving buyers three business days to cancel many sales made at home.'],
                ],
                'related' => ['real-estate', 'moving', 'windows-siding', 'telecom'],
                'sources' => [self::COOLING_OFF, ['title' => 'State licensing information (NASCLA)', 'url' => 'https://www.nascla.org/state-licensing-information']],
                'checked' => $c,
            ],

            'pools' => [
                'summary' => 'Pool builder referrals connect a homeowner to a licensed pool builder where they live. On {site_name}, builders pass homeowners they can’t serve to the verified builder who holds that city’s seat, track each project, and agree any referral fee up front as a share of the receiving builder’s earnings.',
                'intro' => 'A pool is a long project and a big investment, so homeowners want a builder someone they trust can vouch for. The {industry} network lets you pass out-of-area homeowners to a verified local builder and follow the project from design to first swim.',
                'how' => 'Send the homeowner to the builder who holds the seat for their city, with the pool type. The receiving builder accepts within the deadline, holds a design consultation and updates you through construction. Any referral fee is agreed first.',
                'request' => 'Planning a pool? Tell us your city, and we’ll introduce you to a verified local pool builder.',
                'state_summary' => 'Find a verified pool builder in {state}. Builders on {site_name} hold any contractor license {state} requires.',
                'city_summary' => 'Building a pool in {city}, {state}? {site_name} introduces you to the verified builder who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Contractors who design and build in-ground and above-ground pools and spas, and renovate existing ones.',
                    'cost' => '',
                    'licensing' => 'Pool contractors are licensed by each state, sometimes with a pool-specific license; local permits and safety barriers are usually required.',
                    'fees' => 'Pool builders may agree a referral fee with each other where state law allows, as a share of what the receiving builder earns.',
                ],
                'cost_note' => 'Prices depend on size, type, features and region, so we don’t publish a typical figure.',
                'lookups' => [self::NASCLA],
                'hire_questions' => ['Are you licensed and insured in this state?', 'How many pools like mine have you built here?', 'What’s in the contract price, and what costs extra?', 'Who handles permits and inspections?', 'What fencing and safety features do you recommend?', 'What warranty covers the structure and equipment?'],
                'faqs' => [
                    ...self::feesOpen('pool builders', 'builder', 'homeowner'),
                    ['q' => 'What safety barriers does a home pool need?', 'a' => 'Local codes decide, but the CPSC recommends a fence at least four feet high around the pool with self-closing, self-latching gates.'],
                    ['q' => 'Do pool builders need a license?', 'a' => 'Usually a contractor license, and some states have a pool-specific one. Check the state licensing board.'],
                    ['q' => 'What details help the receiving builder?', 'a' => 'The city, the pool type, the budget range and the timing.'],
                    ['q' => 'What if the builder can’t take the project?', 'a' => 'They decline with a reason, and you can send the homeowner to someone else straight away.'],
                    ...self::network('builder', 'project'),
                ],
                'glossary' => [
                    ['term' => 'Gunite', 'definition' => 'A sprayed concrete mix used to build many in-ground pools.'],
                    ['term' => 'Barrier', 'definition' => 'A fence or wall around a pool that keeps young children out.'],
                    ['term' => 'Coping', 'definition' => 'The cap along the edge of a pool wall.'],
                    ['term' => 'Variable-speed pump', 'definition' => 'A pool pump that runs at different speeds to save energy.'],
                ],
                'related' => ['landscaping', 'water-treatment', 'custom-builders'],
                'sources' => [
                    ['title' => 'Safety Barrier Guidelines for Residential Pools, Publication 362 (US Consumer Product Safety Commission)', 'url' => 'https://cpsc.gov/s3fs-public/SafetyBarrierGuidelinesPub362_0423.pdf'],
                    ['title' => 'State licensing information (NASCLA)', 'url' => 'https://www.nascla.org/state-licensing-information'],
                ],
                'checked' => $c,
            ],

            'water-treatment' => [
                'summary' => 'Water treatment referrals connect a homeowner to a trusted water treatment dealer where they live. On {site_name}, dealers pass homeowners they can’t serve to the verified dealer who holds that city’s seat, track each install, and agree any referral fee up front as a share of the receiving dealer’s earnings.',
                'intro' => 'Water quality is local: hardness, well water and city supplies differ from place to place. The {industry} network lets you pass homeowners in other cities to a verified dealer who knows the local water.',
                'how' => 'Send the homeowner to the dealer who holds the seat for their city. The receiving dealer accepts within the deadline, tests the water, recommends a system and updates you through installation. Any referral fee is agreed first.',
                'request' => 'Concerned about your water? Tell us your city, and we’ll introduce you to a verified local water treatment specialist.',
                'state_summary' => 'Find a verified water treatment specialist in {state}. Dealers on {site_name} are chosen for how well they serve referred homeowners.',
                'city_summary' => 'Treating water in {city}, {state}? {site_name} introduces you to the verified specialist who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Dealers who test home water and install softeners, filters and other treatment systems.',
                    'cost' => '',
                    'licensing' => 'There is no national license for water treatment dealers; look for products certified to NSF/ANSI standards for the contaminant you want to reduce.',
                    'fees' => 'Dealers may agree a referral fee with each other where state law allows, as a share of what the receiving dealer earns.',
                ],
                'cost_note' => 'Prices depend on the water problem and system, so we don’t publish a typical figure.',
                'lookups' => [],
                'hire_questions' => ['Will you test my water before recommending a system?', 'Is the system certified to NSF/ANSI standards for my contaminant?', 'What maintenance does it need, and what does that cost?', 'Is this a purchase, rental or financing?', 'What warranty covers it?', 'Can I cancel if I sign at home?'],
                'faqs' => [
                    ...self::feesOpen('water treatment dealers', 'dealer', 'homeowner'),
                    ['q' => 'How do I know a water filter works?', 'a' => 'Look for certification to NSF/ANSI standards for the specific contaminant, and test your water first.'],
                    ['q' => 'Do I need my water tested first?', 'a' => 'Yes. The right system depends on what is in your water; private well owners should test regularly.'],
                    ['q' => 'Can I cancel a contract signed at home?', 'a' => 'Under the FTC’s Cooling-Off Rule, many sales made at your home for more than $25 can be cancelled within three business days.'],
                    ['q' => 'What details help the receiving dealer?', 'a' => 'The city, whether the home is on city water or a well, and the problem the homeowner has noticed.'],
                    ...self::network('dealer', 'install'),
                ],
                'glossary' => [
                    ['term' => 'Water softener', 'definition' => 'A system that removes the calcium and magnesium that make water hard.'],
                    ['term' => 'Reverse osmosis', 'definition' => 'A filter that pushes water through a membrane to remove many dissolved contaminants.'],
                    ['term' => 'Point-of-use', 'definition' => 'Treatment at one tap, such as an under-sink filter.'],
                    ['term' => 'Point-of-entry', 'definition' => 'Treatment of all water entering the home.'],
                ],
                'related' => ['hvac', 'pools', 'home-inspection'],
                'sources' => [
                    ['title' => 'Home water treatment guide, November 2024 (US EPA)', 'url' => 'https://www.epa.gov/system/files/documents/2024-11/ws-products-home-water-treatment-guide_508.pdf'],
                    ['title' => 'Water filters and treatment certification (NSF)', 'url' => 'https://www.nsf.org/consumer-resources/what-is-nsf-certification/water-filters-treatment-certification'],
                    self::COOLING_OFF,
                ],
                'checked' => $c,
            ],

            'home-warranty' => [
                'summary' => 'Home warranty introductions connect a buyer or homeowner to a home warranty representative serving their state. On {site_name}, reps introduce clients with consent and track each introduction, with no referral payments, because RESPA treats home warranties as settlement services.',
                'intro' => 'Buyers and homeowners in other states need a warranty company that operates where they live. The {industry} network lets a representative they trust introduce them to a verified colleague there, with no money changing hands for the introduction.',
                'how' => 'Introduce the client to the representative who holds the seat for their state. The receiving rep accepts within the deadline, explains the coverage and updates you. Introductions are exchanged without payment; members earn credits and recognition with no cash value.',
                'request' => 'Looking for a home warranty in another state? Tell us where, and we’ll introduce you to a verified representative.',
                'state_summary' => 'Find a home warranty representative serving {state}. Introductions on {site_name} are made without referral payments.',
                'city_summary' => 'Looking at home warranties in {city}, {state}? {site_name} introduces you to the verified representative who serves {state}.',
                'facts' => [
                    'covers' => 'Companies that sell service contracts covering repair or replacement of home systems and appliances.',
                    'cost' => '',
                    'licensing' => 'States regulate home warranty companies differently, some through the insurance department and others as service contracts.',
                    'fees' => 'HUD’s 2010 interpretive rule treats a home warranty as a settlement service: paying real estate agents or brokers for referrals violates RESPA Section 8, though payment for real, separate services can be lawful.',
                ],
                'cost_note' => 'Prices depend on the plan, home size and state, so we don’t publish a typical figure.',
                'lookups' => [self::NAIC],
                'hire_questions' => ['Is your company registered or licensed in my state?', 'What exactly is covered, and what is excluded?', 'What is the service call fee?', 'Are there coverage limits per item?', 'How are contractors chosen and how fast do they come?', 'How do I cancel, and is there a refund?'],
                'faqs' => [
                    ['q' => 'Can a home warranty company pay agents for referrals?', 'a' => 'No. HUD’s 2010 rule says home warranties are settlement services, so paying agents or brokers for referrals violates RESPA. Payment for real, separate services can be lawful.'],
                    ['q' => 'What does a home warranty cover?', 'a' => 'Repair or replacement of listed home systems and appliances, subject to the contract’s limits and exclusions.'],
                    ['q' => 'Is a home warranty insurance?', 'a' => 'Usually it is a service contract, but some states regulate it through the insurance department.'],
                    ['q' => 'What is a service call fee?', 'a' => 'The amount the homeowner pays each time a contractor is sent out under the warranty.'],
                    ['q' => 'Why introduce clients if no fee is allowed?', 'a' => 'Because the client gets a verified representative for their state. Members earn credits and recognition with no cash value.'],
                    ['q' => 'How do I check a warranty company?', 'a' => 'Ask the state insurance department or consumer protection office how home warranties are regulated there.'],
                    ...self::network('representative', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'Service contract', 'definition' => 'An agreement to repair or replace covered items for a set period, for a fee.'],
                    ['term' => 'Service call fee', 'definition' => 'A fixed charge each time a contractor is sent out.'],
                    ['term' => 'Exclusion', 'definition' => 'Something the contract does not cover.'],
                    ['term' => 'Settlement service', 'definition' => 'A service connected with a home purchase that RESPA’s referral rules cover.'],
                ],
                'related' => ['real-estate', 'home-inspection', 'hvac', 'insurance'],
                'sources' => [['title' => 'RESPA: Home warranty companies’ payments to real estate brokers and agents, June 25, 2010 (Federal Register)', 'url' => 'https://www.federalregister.gov/documents/2010/06/25/2010-15355/real-estate-settlement-procedures-act-respa-home-warranty-companies-payments-to-real-estate-brokers']],
                'checked' => $c,
            ],

            'financial-advisors' => [
                'summary' => 'Financial advisor introductions connect a client who is moving to a registered advisor in their new city. On {site_name}, advisors introduce clients with consent and track each introduction, without referral fees, since paid referrals for advisers are tightly regulated by the SEC and FINRA.',
                'intro' => 'Clients who relocate often want a local advisor they can meet in person. The {industry} network lets an advisor they trust introduce them to a verified, registered advisor in the new city, with clear deadlines and no payment for the introduction.',
                'how' => 'Introduce the client to the advisor who holds the seat for their new city. The receiving advisor accepts within the deadline, meets the client and updates you. Introductions are exchanged without payment; members earn credits and recognition with no cash value.',
                'request' => 'Moving and want a local financial advisor? Tell us where, and we’ll introduce you to a verified advisor there.',
                'state_summary' => 'Find a registered financial advisor in {state}. Advisors on {site_name} are checked against public SEC and FINRA records, and introductions are made without referral payments.',
                'city_summary' => 'Looking for a financial advisor in {city}, {state}? {site_name} introduces you to the verified advisor who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Investment advisers, brokers and financial planners who manage investments and plan for retirement and other goals.',
                    'cost' => '',
                    'licensing' => 'Investment advisers register with the SEC or their state, and brokers register with FINRA; both records are public in BrokerCheck and the SEC’s adviser search.',
                    'fees' => 'The SEC’s marketing rule sets conditions, including disclosure, on paying for endorsements and referrals of investment advisers, and FINRA rules limit paying unregistered people; this network allows no referral fees.',
                ],
                'cost_note' => 'Advisory fees depend on the advisor, services and account size, so we don’t publish a typical figure.',
                'lookups' => [['name' => 'FINRA BrokerCheck', 'url' => 'https://brokercheck.finra.org/'], ['name' => 'SEC Investment Adviser Public Disclosure', 'url' => 'https://adviserinfo.sec.gov/']],
                'hire_questions' => ['Are you registered with the SEC, my state or FINRA?', 'Are you a fiduciary for all of my accounts?', 'How are you paid: fees, commissions or both?', 'What will I pay in total each year?', 'Can I see your Form CRS?', 'How often will we review my plan?'],
                'faqs' => [
                    ['q' => 'Can financial advisors pay referral fees?', 'a' => 'Paid referrals are tightly regulated: the SEC’s marketing rule requires disclosures and other conditions, and FINRA limits paying unregistered people. This network allows no referral fees.'],
                    ['q' => 'How do I check an advisor?', 'a' => 'Search FINRA BrokerCheck and the SEC’s Investment Adviser Public Disclosure site for registration and any disciplinary history.'],
                    ['q' => 'What is Form CRS?', 'a' => 'A short relationship summary that advisers and brokers must give clients, explaining services, fees and conflicts.'],
                    ['q' => 'What is a fiduciary?', 'a' => 'An advisor legally required to act in the client’s best interest; ask whether that applies to all of your accounts.'],
                    ['q' => 'Why introduce clients if no fee is allowed?', 'a' => 'Because the client gets a verified advisor where they now live. Members earn credits and recognition with no cash value.'],
                    ['q' => 'Is my information shared before I agree?', 'a' => 'No. You’re introduced only after you agree, and the receiving advisor sees your details only after accepting.'],
                    ...self::network('advisor', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'Registered investment adviser (RIA)', 'definition' => 'A firm registered with the SEC or a state to give investment advice for a fee.'],
                    ['term' => 'Broker-dealer', 'definition' => 'A firm registered with FINRA that buys and sells securities for clients.'],
                    ['term' => 'Fiduciary', 'definition' => 'Someone legally bound to act in the client’s best interest.'],
                    ['term' => 'Form CRS', 'definition' => 'The client relationship summary describing services, fees and conflicts.'],
                ],
                'related' => ['tax-accounting', 'estate-planning', 'insurance', 'medicare'],
                'sources' => [
                    ['title' => 'Investment Adviser Marketing final rule, Federal Register, March 5, 2021 (GovInfo)', 'url' => 'https://www.govinfo.gov/content/pkg/FR-2021-03-05/pdf/2020-28868.pdf'],
                    ['title' => 'FINRA BrokerCheck', 'url' => 'https://brokercheck.finra.org/'],
                    ['title' => 'SEC Investment Adviser Public Disclosure', 'url' => 'https://adviserinfo.sec.gov/'],
                ],
                'checked' => $c,
            ],

            'tax-accounting' => [
                'summary' => 'Tax and accounting introductions connect a client who moves or has business in another state to a qualified preparer or CPA there. On {site_name}, professionals introduce clients with consent and track each introduction, without referral fees between members.',
                'intro' => 'State taxes differ, and clients who move or expand often need a professional who knows the new state. The {industry} network lets an accountant they trust introduce them to a verified colleague there, with no payment for the introduction.',
                'how' => 'Introduce the client to the professional who holds the seat for their city. The receiving accountant accepts within the deadline, contacts the client and updates you. Introductions are exchanged without payment; members earn credits and recognition with no cash value.',
                'request' => 'Need a tax preparer or accountant in another state? Tell us where, and we’ll introduce you to a verified professional there.',
                'state_summary' => 'Find a qualified tax preparer or CPA in {state}. Professionals on {site_name} hold the credentials they list, checked against public records.',
                'city_summary' => 'Looking for a tax preparer or accountant in {city}, {state}? {site_name} introduces you to the verified professional who holds the {city} seat.',
                'facts' => [
                    'covers' => 'CPAs, enrolled agents and tax preparers who prepare returns, keep books and advise individuals and businesses.',
                    'cost' => '',
                    'licensing' => 'CPAs are licensed by state boards of accountancy; enrolled agents are licensed by the IRS; every paid preparer needs an IRS Preparer Tax Identification Number.',
                    'fees' => 'Under the AICPA Code of Professional Conduct, CPAs who pay or receive referral fees must disclose them to the client, and state boards add their own rules; this network allows no referral fees.',
                ],
                'cost_note' => 'Fees depend on the return’s complexity and the services, so we don’t publish a typical figure.',
                'lookups' => [['name' => 'CPAverify (CPA license lookup)', 'url' => 'https://cpaverify.org/'], ['name' => 'IRS directory of preparers with credentials', 'url' => 'https://www.irs.gov/tax-professionals/frequently-asked-questions-directory-of-federal-tax-return-preparers-with-credentials-and-select-qualifications']],
                'hire_questions' => ['Are you a CPA, enrolled agent or other credentialed preparer?', 'Do you know this state’s taxes?', 'How do you charge: per form, hourly or flat?', 'Will you sign the return and include your PTIN?', 'Can you represent me if I’m audited?', 'How do you keep my documents secure?'],
                'faqs' => [
                    ['q' => 'Can accountants pay referral fees?', 'a' => 'CPAs who pay or receive referral fees must disclose them to the client under the AICPA Code, and state boards add rules. This network allows no referral fees.'],
                    ['q' => 'How do I check a CPA’s license?', 'a' => 'Search CPAverify, which draws on state boards of accountancy.'],
                    ['q' => 'What is an enrolled agent?', 'a' => 'A tax professional licensed by the IRS who can represent taxpayers before it.'],
                    ['q' => 'What should every paid preparer do?', 'a' => 'Sign the return and include their Preparer Tax Identification Number (PTIN), as the IRS requires.'],
                    ['q' => 'Why introduce clients if no fee is allowed?', 'a' => 'Because the client gets a verified professional who knows the new state. Members earn credits and recognition with no cash value.'],
                    ['q' => 'Is my information shared before I agree?', 'a' => 'No. You’re introduced only after you agree, and the receiving professional sees your details only after accepting.'],
                    ...self::network('accountant', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'CPA', 'definition' => 'Certified public accountant, licensed by a state board of accountancy.'],
                    ['term' => 'Enrolled agent (EA)', 'definition' => 'A tax professional licensed by the IRS.'],
                    ['term' => 'PTIN', 'definition' => 'The Preparer Tax Identification Number every paid preparer must use.'],
                    ['term' => 'Nexus', 'definition' => 'Enough connection with a state that a business must pay or collect its taxes.'],
                ],
                'related' => ['financial-advisors', 'estate-planning', 'business-brokers'],
                'sources' => [
                    ['title' => 'Directory of federal tax return preparers: FAQs (IRS)', 'url' => 'https://www.irs.gov/tax-professionals/frequently-asked-questions-directory-of-federal-tax-return-preparers-with-credentials-and-select-qualifications'],
                    ['title' => 'AICPA Code of Professional Conduct (Minnesota Board of Accountancy copy)', 'url' => 'https://mn.gov/boa/assets/AICPA%20Code%20of%20Professional%20Conduct_tcm1233-764395.pdf'],
                    ['title' => 'CPAverify', 'url' => 'https://cpaverify.org/'],
                ],
                'checked' => $c,
            ],

            'estate-planning' => [
                'summary' => 'The {industry} directory lists verified estate planning attorneys by county so people can contact them directly. {site_name} does not route clients or take any share of legal fees; attorneys pay a flat listing fee, and each attorney’s bar license is checked.',
                'intro' => 'Wills, trusts and powers of attorney depend on state law, so people need an attorney licensed where they live. The {industry} directory lists verified attorneys county by county. Lawyers’ ethics rules strictly limit paying for recommendations, so this is a directory, not a referral network.',
                'how' => 'Attorneys appear for each county they serve, for a flat listing fee that never depends on clients or outcomes. People contact the attorney directly. {site_name} does not recommend attorneys, route clients or share in fees.',
                'request' => 'Planning a will or trust? Browse verified estate planning attorneys in your county and contact them directly.',
                'state_summary' => 'Browse verified estate planning attorneys licensed in {state}. Listings on {site_name} show each attorney’s bar license; contact attorneys directly.',
                'city_summary' => 'Looking for an estate planning attorney near {city}, {state}? Browse verified, licensed attorneys and contact them directly.',
                'facts' => [
                    'covers' => 'Attorneys who prepare wills, trusts, powers of attorney and health care directives, and handle probate.',
                    'cost' => '',
                    'licensing' => 'Attorneys are licensed by the highest court or bar of each state; the state disciplinary agency keeps records of licensing and discipline.',
                    'fees' => 'Under ABA Model Rule 7.2, which most states follow, a lawyer may not give anything of value for recommending their services, apart from narrow exceptions.',
                ],
                'cost_note' => 'Fees depend on the plan’s complexity and the attorney; many quote flat fees, so ask for one in writing.',
                'lookups' => [self::ABA],
                'hire_questions' => ['Are you licensed in my state?', 'How much of your practice is estate planning?', 'Do you charge a flat fee, and what does it include?', 'Do I need a will, a trust or both?', 'Will you help fund the trust?', 'How often should I review my plan?'],
                'faqs' => [
                    ['q' => 'Why is this a directory and not a referral network?', 'a' => 'Lawyers’ ethics rules strictly limit giving anything of value for recommending a lawyer, so {site_name} only lists attorneys for a flat fee and never routes clients.'],
                    ['q' => 'Do I need a will or a trust?', 'a' => 'It depends on your family, property and state; an estate planning attorney can explain the options.'],
                    ['q' => 'How do estate planning attorneys charge?', 'a' => 'Many charge flat fees for standard plans; others bill hourly. Ask for the fee and what it includes in writing.'],
                    ['q' => 'How do I check an attorney’s license?', 'a' => 'Contact the state disciplinary agency or search the state bar’s lawyer directory; the American Bar Association explains how.'],
                    ['q' => 'Does {site_name} recommend an attorney?', 'a' => 'No. The directory lists verified attorneys; you choose and contact them directly.'],
                    ['q' => 'Does using the directory create an attorney-client relationship?', 'a' => 'No. That only begins when you and an attorney agree to it.'],
                    ['q' => 'Is this page legal advice?', 'a' => 'No. It is general information; speak to a licensed attorney about your situation.'],
                    ['q' => 'What does a listing cost attorneys?', 'a' => 'A flat listing fee that never depends on clients, referrals or outcomes.'],
                ],
                'glossary' => [
                    ['term' => 'Will', 'definition' => 'A document saying who receives your property and who manages your estate after death.'],
                    ['term' => 'Revocable living trust', 'definition' => 'A trust you control during life that can pass property without probate.'],
                    ['term' => 'Power of attorney', 'definition' => 'A document letting someone act for you financially or legally.'],
                    ['term' => 'Probate', 'definition' => 'The court process for settling an estate.'],
                ],
                'related' => ['financial-advisors', 'tax-accounting', 'personal-injury', 'senior-living'],
                'sources' => [
                    ['title' => 'Model Rule 7.2 (American Bar Association)', 'url' => 'https://www.americanbar.org/groups/professional_responsibility/publications/model_rules_of_professional_conduct/rule_7_2_advertising/'],
                    ['title' => 'Consumer FAQs: checking a lawyer (American Bar Association)', 'url' => 'https://www.americanbar.org/groups/professional_responsibility/resources/resources_for_the_public/consumer_faqs.html'],
                ],
                'checked' => $c,
            ],

            'senior-living' => [
                'summary' => 'Senior living introductions connect a family to a placement advisor in the city where their loved one will live. On {site_name}, advisors introduce families with consent and track each introduction, without referral fees between advisors, keeping clear of federal rules on paying for health care referrals.',
                'intro' => 'Families often look for care far from home, near the parent or near the adult child. The {industry} network lets an advisor they trust introduce them to a verified advisor who knows the local communities, with clear deadlines and no payment for the introduction.',
                'how' => 'Introduce the family to the advisor who holds the seat for the city, with the level of care needed. The receiving advisor accepts within the deadline, contacts the family and updates you through tours and move-in. Introductions are exchanged without payment.',
                'request' => 'Looking for senior living for a loved one in another city? Tell us where, and we’ll introduce you to a verified local placement advisor.',
                'state_summary' => 'Find a verified senior living placement advisor in {state}. Advisors on {site_name} know local communities, and introductions are made without referral payments.',
                'city_summary' => 'Looking at senior living in {city}, {state}? {site_name} introduces you to the verified placement advisor who serves {city}.',
                'facts' => [
                    'covers' => 'Placement advisors who help families find independent living, assisted living and memory care communities.',
                    'cost' => 'The national median cost of an assisted living community was $70,800 a year in 2024, according to the Genworth and CareScout Cost of Care Survey.',
                    'licensing' => 'Some states license or register senior placement agencies; the communities themselves are licensed by state agencies.',
                    'fees' => 'Placement advisors are usually paid by the community a family chooses; federal law makes paying for referrals of Medicare or Medicaid business a crime, and this network allows no referral fees between advisors.',
                ],
                'cost_note' => 'The figure is a 2024 national median; costs vary widely by state, city and level of care.',
                'lookups' => [['name' => 'Eldercare Locator (US Administration for Community Living)', 'url' => 'https://eldercare.acl.gov/']],
                'hire_questions' => ['How are you paid, and does it cost my family anything?', 'Which communities do you work with here, and which don’t you?', 'Will you visit communities with us?', 'Can you check a community’s state inspection reports?', 'What level of care do you think fits, and why?', 'How will you keep us updated?'],
                'faqs' => [
                    ['q' => 'Do placement advisors charge families?', 'a' => 'Usually not: most are paid by the community the family chooses. Ask each advisor how they are paid.'],
                    ['q' => 'Can placement advisors pay each other referral fees?', 'a' => 'Federal law makes paying for referrals of Medicare or Medicaid business a crime, and some states regulate placement agencies. This network allows no referral fees between advisors.'],
                    ['q' => 'How much does assisted living cost?', 'a' => 'The national median was $70,800 a year in 2024 according to the Genworth and CareScout survey; local costs vary widely.'],
                    ['q' => 'What is the difference between assisted living and memory care?', 'a' => 'Assisted living helps with daily activities; memory care adds secure settings and staff trained for dementia.'],
                    ['q' => 'How do I check a community?', 'a' => 'Ask for its state license and recent inspection reports, and visit more than once.'],
                    ['q' => 'Is this medical advice?', 'a' => 'No. It is general information; talk to your loved one’s doctor about the care they need.'],
                    ...self::network('advisor', 'introduction'),
                ],
                'glossary' => [
                    ['term' => 'Independent living', 'definition' => 'Senior housing with services but no personal care.'],
                    ['term' => 'Assisted living', 'definition' => 'Housing with help for daily activities such as bathing and medication.'],
                    ['term' => 'Memory care', 'definition' => 'Secure care for people living with dementia.'],
                    ['term' => 'Placement advisor', 'definition' => 'A professional who helps families compare and choose senior living communities.'],
                ],
                'related' => ['in-home-care', 'hospice', 'medicare', 'estate-planning', 'real-estate'],
                'sources' => [self::CARESCOUT, self::AKS, ['title' => 'Eldercare Locator (US Administration for Community Living)', 'url' => 'https://eldercare.acl.gov/']],
                'checked' => $c,
            ],

            'in-home-care' => [
                'summary' => 'The {industry} directory lists verified in-home care providers by city so families can contact them directly. {site_name} does not route families or take any fee tied to referrals; providers pay a flat listing fee, in line with federal rules on paying for health care referrals.',
                'intro' => 'Families arranging care for a parent at home, often from far away, need a provider they can trust. The {industry} directory lists verified providers city by city. Because paying for referrals of federally funded health care is a crime, this is a directory, not a referral network.',
                'how' => 'Providers appear for each city they serve, for a flat listing fee that never depends on families or hours of care. Families contact providers directly. {site_name} does not recommend providers or take any share of fees.',
                'request' => 'Arranging care at home for a loved one? Browse verified in-home care providers in their city and contact them directly.',
                'state_summary' => 'Browse verified in-home care providers in {state}. Listings on {site_name} show each provider’s state license where {state} requires one.',
                'city_summary' => 'Looking for in-home care in {city}, {state}? Browse verified providers and contact them directly.',
                'facts' => [
                    'covers' => 'Agencies that provide personal care, companionship and home health aides for people living at home.',
                    'cost' => 'A full-time home health aide (44 hours a week) had a national median cost of $77,792 a year in 2024, according to the Genworth and CareScout Cost of Care Survey.',
                    'licensing' => 'Home care agencies are licensed by many states; Medicare-certified home health agencies are listed in Medicare’s Care Compare.',
                    'fees' => 'Federal law makes paying for referrals of Medicare or Medicaid business a crime, so listings here are flat-fee and never tied to referrals.',
                ],
                'cost_note' => 'The figure is a 2024 national median for 44 hours a week; local rates and hours vary.',
                'lookups' => [self::CARE_COMPARE],
                'hire_questions' => ['Are you licensed in this state, and are you Medicare-certified?', 'How do you screen and train caregivers?', 'Are caregivers employees or contractors?', 'What do you charge per hour, and is there a minimum?', 'Who do we call if a caregiver can’t come?', 'How do you update the family?'],
                'faqs' => [
                    ['q' => 'Why is this a directory and not a referral network?', 'a' => 'Paying for referrals of Medicare or Medicaid business is a crime under federal law, so providers here pay a flat listing fee and {site_name} never routes families.'],
                    ['q' => 'How much does in-home care cost?', 'a' => 'A full-time home health aide had a national median cost of $77,792 a year in 2024; local rates vary.'],
                    ['q' => 'What is the difference between home care and home health care?', 'a' => 'Home care helps with daily living; home health care is skilled care ordered by a doctor, sometimes covered by Medicare.'],
                    ['q' => 'How do I check a provider?', 'a' => 'Ask for its state license; Medicare-certified home health agencies appear in Medicare’s Care Compare.'],
                    ['q' => 'Does {site_name} recommend a provider?', 'a' => 'No. The directory lists verified providers; families choose and contact them directly.'],
                    ['q' => 'Does Medicare pay for in-home care?', 'a' => 'Medicare covers some home health care when a doctor orders it and conditions are met; it generally doesn’t cover long-term personal care.'],
                    ['q' => 'Is this medical advice?', 'a' => 'No. It is general information; talk to a doctor about the care needed.'],
                    ['q' => 'What does a listing cost providers?', 'a' => 'A flat listing fee that never depends on families, referrals or hours of care.'],
                ],
                'glossary' => [
                    ['term' => 'Home health aide', 'definition' => 'A trained aide who helps with personal care and basic health tasks at home.'],
                    ['term' => 'Personal care', 'definition' => 'Help with bathing, dressing, eating and moving around.'],
                    ['term' => 'Home health care', 'definition' => 'Skilled care at home, such as nursing or therapy, ordered by a doctor.'],
                    ['term' => 'Respite care', 'definition' => 'Short-term care that gives family caregivers a break.'],
                ],
                'related' => ['senior-living', 'hospice', 'medicare', 'estate-planning'],
                'sources' => [self::CARESCOUT, self::AKS, ['title' => 'Medicare Care Compare', 'url' => 'https://www.medicare.gov/care-compare/']],
                'checked' => $c,
            ],

            'hospice' => [
                'summary' => 'The {industry} directory lists verified hospice providers by county so families can contact them directly. {site_name} does not route patients or take any fee tied to referrals; providers pay a flat listing fee, in line with federal rules on paying for health care referrals.',
                'intro' => 'Choosing hospice for someone you love is hard, and often done in a hurry and from far away. The {industry} directory lists verified hospice providers by county. Because paying for referrals of Medicare business is a crime, this is a directory, not a referral network.',
                'how' => 'Providers appear for each county they serve, for a flat listing fee that never depends on patients. Families contact providers directly. {site_name} does not recommend providers or take any share of fees.',
                'request' => 'Looking for hospice care for a loved one? Browse verified hospice providers in their county and contact them directly.',
                'state_summary' => 'Browse verified hospice providers in {state}. Listings on {site_name} show whether each provider is Medicare-approved.',
                'city_summary' => 'Looking for hospice care near {city}, {state}? Browse verified providers and contact them directly.',
                'facts' => [
                    'covers' => 'Medicare-approved and other hospice providers giving comfort care to people with a terminal illness, and support to their families.',
                    'cost' => 'Under Original Medicare, people pay nothing for hospice care from a Medicare-approved provider, apart from a copay of up to $5 per prescription for symptom relief and 5% of the approved amount for inpatient respite care.',
                    'licensing' => 'Hospices are licensed by states and must be Medicare-approved to be paid by Medicare; Medicare’s Care Compare lists them with quality information.',
                    'fees' => 'Federal law makes paying for referrals of Medicare or Medicaid business a crime, so listings here are flat-fee and never tied to referrals.',
                ],
                'cost_note' => 'These are Original Medicare rules; Medicare Advantage and private insurance can differ.',
                'lookups' => [self::CARE_COMPARE],
                'hire_questions' => ['Are you Medicare-approved?', 'How quickly can care start?', 'How often will nurses and aides visit?', 'Who do we call at night or on weekends?', 'Do you offer inpatient and respite care?', 'What support do you give the family, including after a death?'],
                'faqs' => [
                    ['q' => 'What does hospice cost under Medicare?', 'a' => 'Nothing for hospice care from a Medicare-approved provider, except up to $5 per prescription for symptom relief and 5% of the approved amount for inpatient respite care.'],
                    ['q' => 'Who qualifies for the Medicare hospice benefit?', 'a' => 'People with Part A whose doctors certify a life expectancy of six months or less, who choose comfort care instead of curative treatment.'],
                    ['q' => 'Why is this a directory and not a referral network?', 'a' => 'Paying for referrals of Medicare business is a crime under federal law, so providers here pay a flat listing fee and {site_name} never routes patients.'],
                    ['q' => 'How do I compare hospices?', 'a' => 'Use Medicare’s Care Compare, which lists Medicare-approved hospices and quality information.'],
                    ['q' => 'Does {site_name} recommend a hospice?', 'a' => 'No. The directory lists verified providers; families choose and contact them directly.'],
                    ['q' => 'Can someone leave hospice?', 'a' => 'Yes. Patients can stop hospice care and return to treatment aimed at a cure.'],
                    ['q' => 'Is this medical advice?', 'a' => 'No. It is general information; talk to the doctor and the hospice team.'],
                    ['q' => 'What does a listing cost providers?', 'a' => 'A flat listing fee that never depends on patients or referrals.'],
                ],
                'glossary' => [
                    ['term' => 'Hospice', 'definition' => 'Comfort care for people with a terminal illness, focused on quality of life.'],
                    ['term' => 'Palliative care', 'definition' => 'Care that relieves symptoms and stress; hospice is one form of it.'],
                    ['term' => 'Respite care', 'definition' => 'A short inpatient stay that gives family caregivers a break.'],
                    ['term' => 'Hospice election statement', 'definition' => 'The form a patient signs to choose the Medicare hospice benefit.'],
                ],
                'related' => ['in-home-care', 'senior-living', 'medicare', 'funeral-preplanning'],
                'sources' => [['title' => 'Hospice care coverage (Medicare.gov)', 'url' => 'https://www.medicare.gov/coverage/hospice-care'], self::AKS, ['title' => 'Medicare Care Compare', 'url' => 'https://www.medicare.gov/care-compare/']],
                'checked' => $c,
            ],

            'landscaping' => [
                'summary' => 'Landscaping referrals connect a homeowner to a licensed landscaper where they live. On {site_name}, landscapers pass homeowners they can’t serve to the verified landscaper who holds that city’s seat, track each project, and agree any referral fee up front as a share of the receiving landscaper’s earnings.',
                'intro' => 'Landscaping and hardscaping are local trades, and homeowners ask the pros they trust for names in other cities. The {industry} network lets you pass them to a verified local landscaper and follow the project through.',
                'how' => 'Send the homeowner to the landscaper who holds the seat for their city. The receiving landscaper accepts within the deadline, designs and quotes the project, and updates you. Any referral fee is agreed first.',
                'request' => 'Planning a landscape or hardscape project? Tell us your city, and we’ll introduce you to a verified local landscaper.',
                'state_summary' => 'Find a verified landscaper in {state}. Landscapers on {site_name} hold any contractor or pesticide license {state} requires.',
                'city_summary' => 'Planning a landscape project in {city}, {state}? {site_name} introduces you to the verified landscaper who holds the {city} seat.',
                'facts' => [
                    'covers' => 'Landscapers and hardscape contractors who design and build gardens, lawns, patios, walls and outdoor living spaces.',
                    'cost' => '',
                    'licensing' => 'Some states license landscape contractors; anyone applying restricted-use pesticides must be certified through the state pesticide agency.',
                    'fees' => 'Landscapers may agree a referral fee with each other where state law allows, as a share of what the receiving landscaper earns.',
                ],
                'cost_note' => 'Prices depend on the design, materials and region, so we don’t publish a typical figure.',
                'lookups' => [self::NASCLA, ['name' => 'State pesticide regulatory agencies (National Pesticide Information Center)', 'url' => 'https://npic.orst.edu/regstate.html']],
                'hire_questions' => ['Are you licensed and insured in this state?', 'Can I see a design and a written estimate?', 'Who handles permits for walls, patios or drainage?', 'Will you apply pesticides, and are you certified to?', 'What plant and workmanship warranty do you offer?', 'Do you offer ongoing maintenance?'],
                'faqs' => [
                    ...self::feesOpen('landscapers', 'landscaper', 'homeowner'),
                    ['q' => 'Do landscapers need a license?', 'a' => 'Some states license landscape contractors, and anyone applying restricted-use pesticides must be certified. Check the state boards.'],
                    ['q' => 'What is hardscaping?', 'a' => 'The built parts of a landscape: patios, walkways, walls, steps and outdoor kitchens.'],
                    ['q' => 'What details help the receiving landscaper?', 'a' => 'The city, the project, the yard size and the budget range.'],
                    ['q' => 'What if the landscaper can’t take the project?', 'a' => 'They decline with a reason, and you can send the homeowner to someone else straight away.'],
                    ...self::network('landscaper', 'project'),
                ],
                'glossary' => [
                    ['term' => 'Hardscape', 'definition' => 'Built features such as patios, walls and walkways.'],
                    ['term' => 'Softscape', 'definition' => 'The living parts of a landscape: plants, trees and lawn.'],
                    ['term' => 'Xeriscaping', 'definition' => 'Landscaping designed to need little water.'],
                    ['term' => 'Retaining wall', 'definition' => 'A wall that holds back soil on a slope; tall ones often need a permit.'],
                ],
                'related' => ['pools', 'pest-control', 'custom-builders', 'commercial-cleaning'],
                'sources' => [
                    ['title' => 'State licensing information (NASCLA)', 'url' => 'https://www.nascla.org/state-licensing-information'],
                    ['title' => 'Certification standards for pesticide applicators (US EPA)', 'url' => 'https://www.epa.gov/pesticide-worker-safety/certification-standards-pesticide-applicators/'],
                ],
                'checked' => $c,
            ],
        ];
    }
}
