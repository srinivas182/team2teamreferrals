'use strict';

/** Example data shown in Zapier before real data exists (same shape as the API). */
module.exports = {
    referral: {
        id: 'evt_01jexample0000000000000000',
        referral_id: '01JEXAMPLE0000000000000000',
        object: 'referral',
        direction: 'received',
        status: 'accepted',
        group: { slug: 'solar-installers-network', name: 'Solar Installers Network' },
        territory: 'Austin, TX',
        sender: { name: 'Sender Solar', profile: null },
        receiver: { name: 'Your Team', profile: null },
        client: { name: 'Tom Brennan', phone: '+15125550100', email: 'tom@example.com', confirmed_at: null },
        answers: {},
        notes: 'Prefers mornings.',
        stage: { key: 'contacted', label: 'Contacted', kind: 'open' },
        closing: null,
        created_at: '2027-01-05T15:00:00+00:00',
        updated_at: '2027-01-05T16:00:00+00:00',
        app_url: 'https://teamtoteamreferrals.com/app/referrals/01JEXAMPLE0000000000000000',
        event_type: 'referral.accepted',
    },
    lead: {
        id: 'evt_01jexample0000000000000001',
        lead_id: 42,
        object: 'lead',
        status: 'new',
        source: 'profile',
        referrer: { name: 'Pat Lee', email: 'pat@example.com', business: 'Lee Realty' },
        client: { name: 'Jo Diaz', phone: '+15125550111', email: 'jo@example.com' },
        need: 'A quote for rooftop solar',
        created_at: '2027-01-05T15:00:00+00:00',
        event_type: 'lead.received',
    },
};
