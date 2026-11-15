'use strict';

const { BASE } = require('./api');

/** Sign in with a live API token from Portal → Developers (API). */
module.exports = {
    type: 'custom',
    fields: [
        {
            key: 'api_key',
            label: 'API token',
            required: true,
            type: 'password',
            helpText:
                'In Team to Team Referrals, open **Developers (API)** and create a **live** token with the permissions your Zaps need (for example referrals:read, referrals:write, leads:read and webhooks:manage).',
        },
    ],
    test: { url: `${BASE}/me` },
    connectionLabel: (z, bundle) => `${bundle.inputData.team ? bundle.inputData.team.name : 'Team'} (${bundle.inputData.name || 'API token'})`,
};
