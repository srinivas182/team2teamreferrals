'use strict';

const { call } = require('../api');
const samples = require('../triggers/samples');

/** Send a referral. The city is looked up by name (and state), as in the app. */
const sendReferral = {
    key: 'send_referral',
    noun: 'Referral',
    display: { label: 'Send Referral', description: 'Sends a client to the team holding the seat for that city in one of your networks.' },
    operation: {
        inputFields: [
            { key: 'group', label: 'Network', required: true, dynamic: 'groups_list.slug.name' },
            { key: 'city', label: 'City', required: true, helpText: 'Where the client needs help, e.g. Austin.' },
            { key: 'state', label: 'State', required: false, helpText: 'Two-letter code, e.g. TX. Helps when city names repeat.' },
            { key: 'client_name', label: 'Client name', required: true },
            { key: 'client_phone', label: 'Client mobile', required: false },
            { key: 'client_email', label: 'Client email', required: false },
            {
                key: 'answers',
                label: 'Other answers',
                dict: true,
                required: false,
                helpText: 'The network’s other referral questions, by field key (see GET /groups/{slug} in the API docs).',
            },
            { key: 'notes', label: 'Notes for the receiving team', type: 'text', required: false },
            {
                key: 'consent',
                label: 'The client agreed to be contacted',
                type: 'boolean',
                required: true,
                helpText: 'Required: only send people who agreed to be contacted by a pro.',
            },
            {
                key: 'allow_duplicate',
                label: 'Send even if this client was referred in the last 30 days',
                type: 'boolean',
                required: false,
                default: 'false',
            },
        ],
        perform: async (z, bundle) => {
            const d = bundle.inputData;
            if (String(d.consent) !== 'true') {
                throw new z.errors.Error('Your client must agree to be contacted before you send a referral.', 'ConsentRequired', 422);
            }
            const cities = await call(z, 'GET', '/cities', undefined, { q: d.city, state: d.state || undefined, limit: 1 });
            const city = (cities.data || [])[0];
            if (!city) throw new z.errors.Error(`No city called “${d.city}”${d.state ? ` in ${d.state}` : ''} was found.`, 'CityNotFound', 422);
            return call(z, 'POST', '/referrals', {
                group: d.group,
                city_id: city.id,
                consent: true,
                notes: d.notes || null,
                allow_duplicate: String(d.allow_duplicate) === 'true',
                answers: {
                    ...(d.answers || {}),
                    client_name: d.client_name,
                    client_phone: d.client_phone || null,
                    client_email: d.client_email || null,
                },
            });
        },
        sample: samples.referral,
    },
};

const addNote = {
    key: 'add_note',
    noun: 'Note',
    display: { label: 'Add Referral Update', description: 'Posts an update on a referral your team accepted (the sending team is told).' },
    operation: {
        inputFields: [
            { key: 'referral_id', label: 'Referral ID', required: true },
            { key: 'note', label: 'Update', type: 'text', required: true },
        ],
        perform: async (z, bundle) => call(z, 'POST', `/referrals/${bundle.inputData.referral_id}/notes`, { note: bundle.inputData.note }),
        sample: samples.referral,
    },
};

const moveStage = {
    key: 'move_stage',
    noun: 'Referral',
    display: { label: 'Move Referral Stage', description: 'Moves a referral your team accepted to another pipeline stage.' },
    operation: {
        inputFields: [
            { key: 'referral_id', label: 'Referral ID', required: true },
            { key: 'stage', label: 'Stage key', required: true, helpText: 'A stage key from the network’s pipeline (GET /groups/{slug}).' },
            { key: 'note', label: 'Note', type: 'text', required: false },
            { key: 'closing_date', label: 'Closing date (won stages)', type: 'datetime', required: false },
            { key: 'closing_value', label: 'Closing value (won stages)', type: 'number', required: false },
            { key: 'earnings', label: 'Your earnings (won stages with a percentage fee)', type: 'number', required: false },
            { key: 'lost_reason', label: 'Why it was lost (lost stages)', required: false },
        ],
        perform: async (z, bundle) => {
            const d = bundle.inputData;
            const body = { stage: d.stage };
            for (const k of ['note', 'lost_reason']) if (d[k]) body[k] = d[k];
            if (d.closing_date) body.closing_date = String(d.closing_date).slice(0, 10);
            for (const k of ['closing_value', 'earnings']) if (d[k] !== undefined && d[k] !== '') body[k] = Number(d[k]);
            return call(z, 'POST', `/referrals/${d.referral_id}/stage`, body);
        },
        sample: samples.referral,
    },
};

const updateLead = {
    key: 'update_lead',
    noun: 'Lead',
    display: { label: 'Update Lead Status', description: 'Marks a profile lead as new, contacted or closed.' },
    operation: {
        inputFields: [
            { key: 'lead_id', label: 'Lead ID', required: true, type: 'integer' },
            { key: 'status', label: 'Status', required: true, choices: { new: 'New', contacted: 'Contacted', closed: 'Closed' } },
        ],
        perform: async (z, bundle) => call(z, 'PATCH', `/leads/${bundle.inputData.lead_id}`, { status: bundle.inputData.status }),
        sample: samples.lead,
    },
};

module.exports = [sendReferral, addNote, moveStage, updateLead];
