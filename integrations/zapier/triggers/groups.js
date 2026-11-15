'use strict';

const { call } = require('../api');

/** Hidden: the networks your team can send in, for the "Send Referral" dropdown. */
module.exports = {
    key: 'groups_list',
    noun: 'Network',
    display: { label: 'List Networks', description: 'Networks your team can send referrals in.', hidden: true },
    operation: {
        perform: async (z) => {
            const page = await call(z, 'GET', '/groups');
            return (page.data || []).map((g) => ({ id: g.slug, slug: g.slug, name: g.name }));
        },
        sample: { id: 'solar-installers-network', slug: 'solar-installers-network', name: 'Solar Installers Network' },
    },
};
