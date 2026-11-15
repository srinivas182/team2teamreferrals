'use strict';

const { call } = require('../api');
const samples = require('./samples');

/**
 * Instant triggers: Zapier subscribes by adding a webhook endpoint for its own URL (POST /webhooks) and removes it
 * when the Zap is turned off (DELETE /webhooks/{id}). Each event's object (a referral or lead) becomes the Zap's data,
 * with the event id as `id` so every change is a separate run.
 */
const hookTrigger = ({ key, noun, label, description, events, accept = () => true, list, sample }) => ({
    key,
    noun,
    display: { label, description },
    operation: {
        type: 'hook',
        performSubscribe: async (z, bundle) => call(z, 'POST', '/webhooks', { url: bundle.targetUrl, events, description: `Zapier: ${label}` }),
        performUnsubscribe: async (z, bundle) => {
            await call(z, 'DELETE', `/webhooks/${bundle.subscribeData.id}`);
            return {};
        },
        perform: async (z, bundle) => {
            const event = bundle.cleanedRequest || {};
            const object = event.data && event.data.object;
            if (!object || !events.includes(event.type) || !accept(object)) return [];
            return [{ ...object, id: event.id, [`${noun.toLowerCase()}_id`]: object.id, event_type: event.type, event_at: event.created_at }];
        },
        // Recent items, so Zapier can show real data while the Zap is set up.
        performList: async (z) => {
            const page = await call(z, 'GET', list.path, undefined, { limit: 10, ...list.params });
            return (page.data || [])
                .filter(accept)
                .map((o) => ({ ...o, id: `${o.id}-${o.updated_at || o.created_at}`, [`${noun.toLowerCase()}_id`]: o.id }));
        },
        sample,
    },
});

module.exports = [
    hookTrigger({
        key: 'referral_received',
        noun: 'Referral',
        label: 'New Referral Received',
        description: 'Triggers when another team sends your team a referral (client contact details appear once you accept).',
        events: ['referral.sent'],
        accept: (r) => r.direction === 'received',
        list: { path: '/referrals', params: { direction: 'received' } },
        sample: samples.referral,
    }),
    hookTrigger({
        key: 'referral_accepted',
        noun: 'Referral',
        label: 'Referral Accepted',
        description: 'Triggers when a referral is accepted: by your team, or by the team you sent it to.',
        events: ['referral.accepted'],
        list: { path: '/referrals', params: { status: 'accepted' } },
        sample: samples.referral,
    }),
    hookTrigger({
        key: 'referral_stage_changed',
        noun: 'Referral',
        label: 'Referral Stage Changed',
        description: 'Triggers when a referral moves to another pipeline stage.',
        events: ['referral.stage_changed'],
        list: { path: '/referrals', params: { status: 'accepted' } },
        sample: samples.referral,
    }),
    hookTrigger({
        key: 'referral_won',
        noun: 'Referral',
        label: 'Referral Won',
        description: 'Triggers when a referral moves to a won stage, with the closing date and value.',
        events: ['referral.stage_changed'],
        accept: (r) => Boolean(r.stage && r.stage.kind === 'won'),
        list: { path: '/referrals', params: { status: 'accepted' } },
        sample: samples.referral,
    }),
    hookTrigger({
        key: 'lead_received',
        noun: 'Lead',
        label: 'New Profile Lead',
        description: 'Triggers when a verified lead arrives through your public profile or website widgets.',
        events: ['lead.received'],
        list: { path: '/leads', params: {} },
        sample: samples.lead,
    }),
];
