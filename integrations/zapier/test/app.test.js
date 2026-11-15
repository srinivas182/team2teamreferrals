import { describe, expect, it, vi } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const app = require('../index.js');
const { BASE } = require('../api.js');

/** A stand-in for Zapier's `z`: records requests and answers from a small fake API. */
const makeZ = (routes) => {
    class ZError extends Error {
        constructor(message, type, status) {
            super(message);
            this.type = type;
            this.status = status;
        }
    }
    const z = { errors: { Error: ZError }, calls: [] };
    z.request = vi.fn(async (opts) => {
        const req = app.beforeRequest.reduce((r, f) => f(r, z, z.bundle), { ...opts, headers: {} });
        z.calls.push(req);
        const path = req.url.replace(BASE, '');
        const [status, data] = routes(req.method, path, req.body, req.params || {});
        return app.afterResponse.reduce((r, f) => f(r, z), { status, data });
    });
    return z;
};
const bundle = (inputData = {}, extra = {}) => ({ authData: { api_key: 't2t_live_abc' }, inputData, ...extra });

describe('Zapier app', () => {
    it('signs in with the token and names the connection after the team', () => {
        expect(app.authentication.test.url).toBe(`${BASE}/me`);
        expect(app.authentication.connectionLabel({}, { inputData: { name: 'Zapier', team: { name: 'Holder Solar' } } })).toBe(
            'Holder Solar (Zapier)',
        );
    });

    it('subscribes, receives and unsubscribes instant triggers through webhooks', async () => {
        const z = makeZ((method, path, body) => {
            if (method === 'POST' && path === '/webhooks') return [201, { id: 'wh_1', ...body }];
            if (method === 'DELETE') return [204, null];
            return [404, { title: 'Not found', status: 404 }];
        });
        z.bundle = bundle();
        const won = app.triggers.referral_won.operation;
        const sub = await won.performSubscribe(z, bundle({}, { targetUrl: 'https://hooks.zapier.com/hooks/standard/1/abc' }));
        expect(z.calls[0].headers.Authorization).toBe('Bearer t2t_live_abc');
        expect(z.calls[0].body).toMatchObject({ url: 'https://hooks.zapier.com/hooks/standard/1/abc', events: ['referral.stage_changed'] });
        const open = { id: 'R1', stage: { kind: 'open' } };
        const wonRef = { id: 'R1', stage: { kind: 'won' }, closing: { date: '2027-02-01', value: 30000 } };
        expect(await won.perform(z, bundle({}, { cleanedRequest: { id: 'evt_1', type: 'referral.stage_changed', data: { object: open } } }))).toEqual(
            [],
        );
        const out = await won.perform(
            z,
            bundle({}, { cleanedRequest: { id: 'evt_2', type: 'referral.stage_changed', created_at: 'now', data: { object: wonRef } } }),
        );
        expect(out).toEqual([{ ...wonRef, id: 'evt_2', referral_id: 'R1', event_type: 'referral.stage_changed', event_at: 'now' }]);
        // A ping or another event type never starts a Zap.
        expect(await won.perform(z, bundle({}, { cleanedRequest: { id: 'evt_3', type: 'ping', data: { object: {} } } }))).toEqual([]);
        await won.performUnsubscribe(z, bundle({}, { subscribeData: sub }));
        expect(z.calls[1]).toMatchObject({ method: 'DELETE', url: `${BASE}/webhooks/wh_1` });
    });

    it('only starts "New Referral Received" for referrals sent to your team', async () => {
        const z = makeZ(() => [
            200,
            {
                data: [
                    { id: 'A', direction: 'received', updated_at: 't1' },
                    { id: 'B', direction: 'sent', updated_at: 't2' },
                ],
            },
        ]);
        z.bundle = bundle();
        const t = app.triggers.referral_received.operation;
        expect(
            await t.perform(z, bundle({}, { cleanedRequest: { id: 'e', type: 'referral.sent', data: { object: { id: 'B', direction: 'sent' } } } })),
        ).toEqual([]);
        expect((await t.performList(z, bundle())).map((r) => r.referral_id)).toEqual(['A']);
        expect(z.calls[0].params).toMatchObject({ direction: 'received', limit: 10 });
    });

    it('sends a referral after finding the city, and requires consent', async () => {
        const z = makeZ((method, path, body, params) => {
            if (path === '/cities') return [200, { data: params.q === 'Austin' ? [{ id: 44, name: 'Austin', state: 'TX' }] : [] }];
            if (method === 'POST' && path === '/referrals') return [201, { id: 'R9', ...body }];
            return [404, {}];
        });
        z.bundle = bundle();
        const send = app.creates.send_referral.operation;
        const input = {
            group: 'solar-installers-network',
            city: 'Austin',
            state: 'TX',
            client_name: 'Tom Brennan',
            client_email: 'tom@example.com',
            answers: { roof_type: 'Tile' },
            consent: true,
        };
        const r = await send.perform(z, bundle(input));
        expect(r).toMatchObject({
            id: 'R9',
            group: 'solar-installers-network',
            city_id: 44,
            consent: true,
            answers: { roof_type: 'Tile', client_name: 'Tom Brennan', client_email: 'tom@example.com', client_phone: null },
        });
        await expect(send.perform(z, bundle({ ...input, consent: false }))).rejects.toThrow('agree to be contacted');
        await expect(send.perform(z, bundle({ ...input, city: 'Nowhere' }))).rejects.toThrow('No city called “Nowhere” in TX');
    });

    it('shows the API’s plain error messages', async () => {
        const z = makeZ(() => [
            422,
            { title: 'Invalid', detail: 'Some fields need fixing.', errors: { stage: ['That stage isn’t in this network’s pipeline.'] } },
        ]);
        z.bundle = bundle();
        await expect(app.creates.move_stage.operation.perform(z, bundle({ referral_id: 'R1', stage: 'nope' }))).rejects.toThrow(
            'Some fields need fixing. That stage isn’t in this network’s pipeline.',
        );
        const z401 = makeZ(() => [401, { title: 'Not authenticated', detail: 'Missing, invalid, expired or revoked API token.' }]);
        z401.bundle = bundle();
        await expect(app.searches.find_referral.operation.perform(z401, bundle({ client_email: 'x@example.com' }))).rejects.toThrow(
            'Reconnect with a working API token',
        );
    });

    it('moves stages with closing details, updates leads, and finds referrals by email', async () => {
        const z = makeZ((method, path, body, params) => [
            200,
            path === '/referrals' ? { data: [{ id: 'R1', client: { email: params.client_email } }] } : { ok: true, method, path, body },
        ]);
        z.bundle = bundle();
        const moved = await app.creates.move_stage.operation.perform(
            z,
            bundle({ referral_id: 'R1', stage: 'closed', closing_date: '2027-02-01T00:00:00Z', closing_value: '30000', earnings: '' }),
        );
        expect(moved.body).toEqual({ stage: 'closed', closing_date: '2027-02-01', closing_value: 30000 });
        expect(await app.creates.update_lead.operation.perform(z, bundle({ lead_id: 4, status: 'contacted' }))).toMatchObject({
            method: 'PATCH',
            path: '/leads/4',
            body: { status: 'contacted' },
        });
        expect(await app.creates.add_note.operation.perform(z, bundle({ referral_id: 'R1', note: 'Booked' }))).toMatchObject({
            path: '/referrals/R1/notes',
            body: { note: 'Booked' },
        });
        expect(await app.searches.find_referral.operation.perform(z, bundle({ client_email: 'tom@example.com' }))).toEqual([
            { id: 'R1', client: { email: 'tom@example.com' } },
        ]);
    });

    it('has a sample for every trigger, action and search, and a hidden network list for the dropdown', () => {
        for (const group of [app.triggers, app.creates, app.searches]) {
            for (const def of Object.values(group)) expect(def.operation.sample, def.key).toBeTruthy();
        }
        expect(app.triggers.groups_list.display.hidden).toBe(true);
        expect(app.creates.send_referral.operation.inputFields.find((f) => f.key === 'group').dynamic).toBe('groups_list.slug.name');
    });
});
