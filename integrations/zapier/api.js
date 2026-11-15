'use strict';

/** Shared HTTP helpers for the Team to Team Referrals API v1 (documented at /developers). */
const BASE = (process.env.BASE_URL || 'https://teamtoteamreferrals.com/api/v1').replace(/\/$/, '');

/** Every request carries the member's API token. */
const addAuth = (request, z, bundle) => {
    request.headers = request.headers || {};
    request.headers.Authorization = `Bearer ${bundle.authData.api_key}`;
    request.headers.Accept = 'application/json';
    return request;
};

/** Errors come back as problem details: show their plain message in Zapier. */
const handleErrors = (response, z) => {
    if (response.status >= 400) {
        const body = response.data || response.json || {};
        const fields = body.errors ? ` ${Object.values(body.errors).flat().join(' ')}` : '';
        const message = `${body.detail || body.title || `HTTP ${response.status}`}${fields}`;
        if (response.status === 401) {
            throw new z.errors.Error(`${message} Reconnect with a working API token.`, 'AuthenticationError', 401);
        }
        throw new z.errors.Error(message, body.title || 'ApiError', response.status);
    }
    return response;
};

const call = async (z, method, path, body, params) => {
    const response = await z.request({ method, url: `${BASE}${path}`, body, params, skipThrowForStatus: true });
    return response.data || response.json;
};

module.exports = { BASE, addAuth, handleErrors, call };
