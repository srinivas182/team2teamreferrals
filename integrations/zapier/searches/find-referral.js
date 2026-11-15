'use strict';

const { call } = require('../api');
const samples = require('../triggers/samples');

/** Find a referral by the client's email (referrals your team sent, or accepted). */
module.exports = {
    key: 'find_referral',
    noun: 'Referral',
    display: { label: 'Find Referral', description: 'Finds the latest referral for a client’s email.' },
    operation: {
        inputFields: [{ key: 'client_email', label: 'Client email', required: true }],
        perform: async (z, bundle) => {
            const page = await call(z, 'GET', '/referrals', undefined, { client_email: bundle.inputData.client_email, limit: 1 });
            return page.data || [];
        },
        sample: samples.referral,
    },
};
