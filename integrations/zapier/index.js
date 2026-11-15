'use strict';

const { version } = require('./package.json');
const authentication = require('./authentication');
const { addAuth, handleErrors } = require('./api');
const hookTriggers = require('./triggers/hooks');
const groups = require('./triggers/groups');
const actions = require('./creates/actions');
const findReferral = require('./searches/find-referral');

const byKey = (list) => Object.fromEntries(list.map((x) => [x.key, x]));

/** The Team to Team Referrals Zapier app (Sprint 45b). Push with `zapier push` from this folder. */
module.exports = {
    version,
    platformVersion: '19.3.0',
    authentication,
    beforeRequest: [addAuth],
    afterResponse: [handleErrors],
    triggers: byKey([...hookTriggers, groups]),
    creates: byKey(actions),
    searches: byKey([findReferral]),
    searchOrCreates: {},
};
