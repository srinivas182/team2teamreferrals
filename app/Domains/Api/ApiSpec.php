<?php

declare(strict_types=1);

namespace App\Domains\Api;

/**
 * The OpenAPI 3.1 description of API v1: served at /api/v1/openapi.json and shown at /developers. A test fails the
 * build if a route and this description ever disagree, so the docs can't drift from the API.
 */
final class ApiSpec
{
    /** @return array<string, mixed> */
    public static function document(): array
    {
        $ref = fn (string $s) => ['$ref' => '#/components/schemas/'.$s];
        $json = fn (array $schema) => ['content' => ['application/json' => ['schema' => $schema]]];
        $ok = fn (string $desc, array $schema) => ['description' => $desc, ...$json($schema)];
        $list = fn (string $item) => ['allOf' => [$ref('List'), ['properties' => ['data' => ['type' => 'array', 'items' => $ref($item)]]]]];
        $errors = ['401' => ['$ref' => '#/components/responses/Unauthorized'], '403' => ['$ref' => '#/components/responses/Forbidden'], '429' => ['$ref' => '#/components/responses/TooMany']];
        $notFound = ['404' => ['$ref' => '#/components/responses/NotFound']];
        $invalid = ['422' => ['$ref' => '#/components/responses/Invalid']];
        $idParam = fn (string $name, string $type = 'string', string $desc = '') => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => $type], 'description' => $desc];
        $q = fn (string $name, array $schema, string $desc, bool $required = false) => ['name' => $name, 'in' => 'query', 'required' => $required, 'schema' => $schema, 'description' => $desc];
        $paging = [$q('limit', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25], 'Items per page.'), $q('cursor', ['type' => 'string'], 'The next_cursor from the previous page.')];
        $idem = ['$ref' => '#/components/parameters/IdempotencyKey'];
        $op = fn (string $id, string $tag, string $summary, string $scope, array $extra) => ['operationId' => $id, 'tags' => [$tag], 'summary' => $summary,
            'x-scope' => $scope, ...$extra];
        $refId = $idParam('id', 'string', 'The referral id (e.g. 01J…).');

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Team to Team Referrals API', 'version' => '1.0.0',
                'description' => "Send and track referrals between verified teams, and read your leads, networks, seats and profile.\n\n"
                    ."Authenticate with a team API token: `Authorization: Bearer t2t_live_…`. Test tokens (`t2t_test_…`) work in a private sandbox network with sample teams and never contact anyone.\n\n"
                    .'Errors use RFC 9457 problem details. Lists use cursor paging. Send an `Idempotency-Key` header with any POST or PATCH to retry it safely.'],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/v1']],
            'security' => [['bearer' => []]],
            'tags' => [['name' => 'Account'], ['name' => 'Referrals'], ['name' => 'Sandbox'], ['name' => 'Leads'], ['name' => 'Networks'], ['name' => 'Seats'], ['name' => 'Profile']],
            'paths' => [
                '/me' => ['get' => $op('getMe', 'Account', 'The token and team you are calling as', '', ['responses' => ['200' => $ok('The token', $ref('Me')), ...$errors]])],
                '/referrals' => [
                    'get' => $op('listReferrals', 'Referrals', 'Referrals your team sent and received, newest first', 'referrals:read', [
                        'parameters' => [$q('direction', ['type' => 'string', 'enum' => ['sent', 'received']], 'Only sent or only received.'),
                            $q('status', ['type' => 'string', 'enum' => ['sent', 'accepted', 'declined', 'expired', 'withdrawn']], 'Filter by status.'),
                            $q('updated_since', ['type' => 'string', 'format' => 'date-time'], 'Only referrals changed since this time.'), ...$paging],
                        'responses' => ['200' => $ok('A page of referrals', $list('Referral')), ...$errors, ...$invalid]]),
                    'post' => $op('createReferral', 'Referrals', 'Send a referral (your client must have agreed to be contacted)', 'referrals:write', [
                        'parameters' => [$idem], 'requestBody' => ['required' => true, ...$json($ref('NewReferral'))],
                        'responses' => ['201' => $ok('The referral, routed to the seat holder', $ref('Referral')), '409' => ['$ref' => '#/components/responses/Conflict'], ...$errors, ...$invalid]]),
                ],
                '/referrals/{id}' => ['get' => $op('getReferral', 'Referrals', 'One referral', 'referrals:read', ['parameters' => [$refId], 'responses' => ['200' => $ok('The referral', $ref('Referral')), ...$errors, ...$notFound]])],
                '/referrals/{id}/events' => ['get' => $op('listReferralEvents', 'Referrals', 'A referral’s timeline, oldest first', 'referrals:read', [
                    'parameters' => [$refId], 'responses' => ['200' => $ok('The timeline', $list('Event')), ...$errors, ...$notFound]])],
                '/referrals/{id}/accept' => ['post' => $op('acceptReferral', 'Referrals', 'Accept a referral sent to your team (contact details appear)', 'referrals:write', [
                    'parameters' => [$refId, $idem], 'responses' => ['200' => $ok('The referral', $ref('Referral')), ...$errors, ...$notFound, ...$invalid]])],
                '/referrals/{id}/decline' => ['post' => $op('declineReferral', 'Referrals', 'Decline a referral sent to your team', 'referrals:write', [
                    'parameters' => [$refId, $idem], 'requestBody' => ['required' => true, ...$json(['type' => 'object', 'required' => ['reason'], 'properties' => ['reason' => ['type' => 'string', 'maxLength' => 500]]])],
                    'responses' => ['200' => $ok('The referral', $ref('Referral')), ...$errors, ...$notFound, ...$invalid]])],
                '/referrals/{id}/stage' => ['post' => $op('moveReferralStage', 'Referrals', 'Move an accepted referral to a pipeline stage', 'referrals:write', [
                    'parameters' => [$refId, $idem], 'requestBody' => ['required' => true, ...$json($ref('StageMove'))],
                    'responses' => ['200' => $ok('The referral', $ref('Referral')), ...$errors, ...$notFound, ...$invalid]])],
                '/referrals/{id}/notes' => ['post' => $op('addReferralNote', 'Referrals', 'Post an update for the other team', 'referrals:write', [
                    'parameters' => [$refId, $idem], 'requestBody' => ['required' => true, ...$json(['type' => 'object', 'required' => ['note'], 'properties' => ['note' => ['type' => 'string', 'maxLength' => 2000]]])],
                    'responses' => ['201' => $ok('The referral', $ref('Referral')), ...$errors, ...$notFound, ...$invalid]])],
                '/sandbox/incoming-referrals' => ['post' => $op('sandboxIncomingReferral', 'Sandbox', 'Test tokens: a sample team sends your sandbox team a referral', 'referrals:write', [
                    'parameters' => [$idem], 'requestBody' => ['required' => false, ...$json(['type' => 'object', 'properties' => ['answers' => ['type' => 'object']]])],
                    'responses' => ['201' => $ok('The referral', $ref('Referral')), ...$errors, ...$invalid]])],
                '/sandbox/referrals/{id}/simulate' => ['post' => $op('sandboxSimulate', 'Sandbox', 'Test tokens: the sample team that received your referral accepts, declines or moves it', 'referrals:write', [
                    'parameters' => [$refId, $idem], 'requestBody' => ['required' => true, ...$json(['type' => 'object', 'required' => ['action'], 'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['accept', 'decline', 'stage']], 'reason' => ['type' => 'string'], 'stage' => ['type' => 'string'],
                        'closing_date' => ['type' => 'string', 'format' => 'date'], 'closing_value' => ['type' => 'number'], 'earnings' => ['type' => 'number'], 'lost_reason' => ['type' => 'string']]])],
                    'responses' => ['200' => $ok('The referral', $ref('Referral')), ...$errors, ...$notFound, ...$invalid]])],
                '/leads' => ['get' => $op('listLeads', 'Leads', 'Leads from your public profile', 'leads:read', [
                    'parameters' => [$q('status', ['type' => 'string', 'enum' => ['new', 'contacted', 'closed']], 'Filter by status.'), ...$paging],
                    'responses' => ['200' => $ok('A page of leads', $list('Lead')), ...$errors, ...$invalid]])],
                '/leads/{id}' => [
                    'get' => $op('getLead', 'Leads', 'One lead', 'leads:read', ['parameters' => [$idParam('id', 'integer')], 'responses' => ['200' => $ok('The lead', $ref('Lead')), ...$errors, ...$notFound]]),
                    'patch' => $op('updateLead', 'Leads', 'Change a lead’s status', 'leads:write', ['parameters' => [$idParam('id', 'integer'), $idem],
                        'requestBody' => ['required' => true, ...$json(['type' => 'object', 'required' => ['status'], 'properties' => ['status' => ['type' => 'string', 'enum' => ['new', 'contacted', 'closed']]]])],
                        'responses' => ['200' => $ok('The lead', $ref('Lead')), ...$errors, ...$notFound, ...$invalid]]),
                ],
                '/groups' => ['get' => $op('listGroups', 'Networks', 'The networks your team can send referrals in', 'groups:read', ['responses' => ['200' => $ok('Your networks', $list('Group')), ...$errors]])],
                '/groups/{slug}' => ['get' => $op('getGroup', 'Networks', 'A network, with its referral form and pipeline stages', 'groups:read', [
                    'parameters' => [$idParam('slug')], 'responses' => ['200' => $ok('The network', $ref('Group')), ...$errors, ...$notFound]])],
                '/groups/{slug}/route' => ['get' => $op('routeReferral', 'Networks', 'Who would receive a referral for a city, before you send it', 'groups:read', [
                    'parameters' => [$idParam('slug'), $q('city_id', ['type' => 'integer'], 'From GET /cities.', true)], 'responses' => ['200' => $ok('The route', $ref('Route')), ...$errors, ...$notFound, ...$invalid]])],
                '/cities' => ['get' => $op('searchCities', 'Networks', 'Find a city’s id by name', 'groups:read', [
                    'parameters' => [$q('q', ['type' => 'string', 'minLength' => 2], 'Start of the city name.', true), $q('state', ['type' => 'string', 'minLength' => 2, 'maxLength' => 2], 'Two-letter state code.'),
                        $q('limit', ['type' => 'integer', 'maximum' => 50], 'Most results.')],
                    'responses' => ['200' => $ok('Matching cities', $list('City')), ...$errors, ...$invalid]])],
                '/seats' => ['get' => $op('listSeats', 'Seats', 'Your team’s seats (as holder or backup)', 'seats:read', ['responses' => ['200' => $ok('Your seats', $list('Seat')), ...$errors]])],
                '/seats/{id}' => ['get' => $op('getSeat', 'Seats', 'One of your seats', 'seats:read', ['parameters' => [$idParam('id', 'integer')], 'responses' => ['200' => $ok('The seat', $ref('Seat')), ...$errors, ...$notFound]])],
                '/profile' => [
                    'get' => $op('getProfile', 'Profile', 'Your team profile', 'profile:read', ['responses' => ['200' => $ok('The profile', $ref('Profile')), ...$errors]]),
                    'patch' => $op('updateProfile', 'Profile', 'Update your team profile (only the fields you send)', 'profile:write', ['parameters' => [$idem],
                        'requestBody' => ['required' => true, ...$json($ref('ProfileUpdate'))], 'responses' => ['200' => $ok('The profile', $ref('Profile')), ...$errors, ...$invalid]]),
                ],
            ],
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'A team API token from Portal → Developers.']],
                'parameters' => ['IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 100],
                    'description' => 'Any unique string. Sending the same request again with the same key within 24 hours returns the first answer instead of doing it twice.']],
                'responses' => [
                    'Unauthorized' => ['description' => 'Missing, invalid, expired or revoked token', ...self::problem()],
                    'Forbidden' => ['description' => 'The token lacks the permission (scope) needed', ...self::problem()],
                    'NotFound' => ['description' => 'No such item, or your team can’t see it', ...self::problem()],
                    'Invalid' => ['description' => 'Some fields need fixing (see errors)', ...self::problem()],
                    'Conflict' => ['description' => 'A likely duplicate (send again with allow_duplicate: true) or a retry still running', ...self::problem()],
                    'TooMany' => ['description' => 'Rate limit reached: wait for Retry-After seconds', ...self::problem()],
                ],
                'schemas' => self::schemas(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function problem(): array
    {
        return ['content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $s = fn (array $props, array $required = []) => ['type' => 'object', 'properties' => $props, ...($required ? ['required' => $required] : [])];
        $str = ['type' => 'string'];
        $nstr = ['type' => ['string', 'null']];
        $dt = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $team = $s(['name' => $str, 'profile' => $nstr]);

        return [
            'Problem' => $s(['type' => $str, 'title' => $str, 'status' => ['type' => 'integer'], 'detail' => $str, 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => $str]]], ['title', 'status']),
            'List' => $s(['object' => ['const' => 'list'], 'data' => ['type' => 'array'], 'has_more' => ['type' => 'boolean'], 'next_cursor' => $nstr], ['data', 'has_more']),
            'Me' => $s(['object' => ['const' => 'token'], 'name' => $str, 'mode' => ['enum' => ['live', 'test']], 'scopes' => ['type' => 'array', 'items' => $str],
                'team' => $s(['id' => $str, 'name' => $str]), 'acting_as' => $str, 'rate_limit_per_minute' => ['type' => 'integer'], 'expires_at' => $dt]),
            'NewReferral' => $s(['group' => ['type' => 'string', 'description' => 'Network slug, from GET /groups.'], 'city_id' => ['type' => 'integer', 'description' => 'From GET /cities.'],
                'answers' => ['type' => 'object', 'description' => 'The network’s referral form, by field key (GET /groups/{slug}): client_name, client_phone and/or client_email, and the industry’s questions.'],
                'consent' => ['const' => true, 'description' => 'Confirms your client agreed to be contacted.'], 'notes' => $nstr,
                'text_client' => ['type' => 'boolean', 'description' => 'Text the client to confirm (where texting is approved).'],
                'allow_duplicate' => ['type' => 'boolean', 'description' => 'Send even if this client was referred in this network in the last 30 days.']], ['group', 'city_id', 'answers', 'consent']),
            'Referral' => $s(['id' => $str, 'object' => ['const' => 'referral'], 'direction' => ['enum' => ['sent', 'received']], 'status' => ['enum' => ['sent', 'accepted', 'declined', 'expired', 'withdrawn']],
                'group' => $s(['slug' => $str, 'name' => $str]), 'territory' => $str, 'sender' => $team, 'receiver' => ['oneOf' => [$team, ['type' => 'null']]],
                'routed_to' => $str, 'client' => $s(['name' => ['type' => 'string', 'description' => 'First name and initial until the receiving team accepts.'], 'phone' => $nstr, 'email' => $nstr, 'confirmed_at' => $dt]),
                'answers' => ['type' => 'object', 'description' => 'Sensitive answers are null for the receiving team until it accepts.'], 'notes' => $nstr,
                'stage' => ['oneOf' => [$s(['key' => $str, 'label' => $str, 'kind' => ['enum' => ['open', 'won', 'lost']]]), ['type' => 'null']]],
                'respond_by' => $dt, 'accepted_at' => $dt, 'decline_reason' => $nstr, 'closed_at' => $dt, 'close_status' => $nstr,
                'closing' => ['oneOf' => [$s(['date' => $str, 'value' => ['type' => ['number', 'null']]]), ['type' => 'null']]], 'lost_reason' => $nstr,
                'consent' => $s(['at' => $dt, 'method' => $str]), 'sandbox' => ['type' => 'boolean'], 'created_at' => $dt, 'updated_at' => $dt, 'app_url' => $str]),
            'StageMove' => $s(['stage' => ['type' => 'string', 'description' => 'A stage key from GET /groups/{slug}.'], 'note' => $nstr,
                'closing_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Needed for a won stage.'], 'closing_value' => ['type' => 'number', 'description' => 'Needed for a won stage.'],
                'earnings' => ['type' => 'number', 'description' => 'Needed for a won stage when the fee is a percentage.'], 'lost_reason' => ['type' => 'string', 'description' => 'Needed for a lost stage.']], ['stage']),
            'Event' => $s(['type' => $str, 'at' => $dt, 'team' => $nstr, 'details' => ['type' => 'object']]),
            'Lead' => $s(['id' => ['type' => 'integer'], 'object' => ['const' => 'lead'], 'status' => ['enum' => ['new', 'contacted', 'closed']], 'source' => $str,
                'referrer' => $s(['name' => $str, 'email' => $str, 'business' => $nstr]), 'client' => $s(['name' => $str, 'phone' => $nstr, 'email' => $nstr]),
                'need' => $str, 'consent_text' => $str, 'verified_at' => $dt, 'created_at' => $dt]),
            'Group' => $s(['slug' => $str, 'object' => ['const' => 'group'], 'name' => $str, 'industry' => $s(['slug' => $str, 'name' => $str]), 'territory_unit' => $str,
                'sandbox' => ['type' => 'boolean'], 'accept_hours' => ['type' => 'integer'],
                'form' => ['type' => 'array', 'items' => $s(['key' => $str, 'label' => $str, 'type' => $str, 'required' => ['type' => 'boolean'], 'options' => ['type' => ['array', 'null']]])],
                'stages' => ['type' => 'array', 'items' => $s(['key' => $str, 'label' => $str, 'kind' => $str])],
                'sandbox_seats' => ['type' => 'array', 'items' => $s(['territory' => $str, 'city_id' => ['type' => 'integer'], 'team' => $str, 'yours' => ['type' => 'boolean']])]]),
            'Route' => $s(['object' => ['const' => 'route'], 'territory' => $str, 'available' => ['type' => 'boolean'], 'own_territory' => ['type' => 'boolean'], 'receiver' => ['oneOf' => [$team, ['type' => 'null']]], 'role' => $nstr]),
            'City' => $s(['id' => ['type' => 'integer'], 'name' => $str, 'state' => $str]),
            'Seat' => $s(['id' => ['type' => 'integer'], 'object' => ['const' => 'seat'], 'group' => $s(['slug' => $str, 'name' => $str]), 'territory' => $str, 'territory_type' => $str,
                'role' => ['enum' => ['primary', 'backup']], 'status' => $str, 'filled' => ['type' => 'boolean'], 'trial_ends_at' => $dt]),
            'Profile' => $s(['object' => ['const' => 'profile'], 'name' => $str, 'public' => ['type' => 'boolean'], 'url' => $nstr, 'bio' => $nstr, 'website' => $nstr, 'phone' => $nstr,
                'service_area' => $nstr, 'founded_year' => ['type' => ['integer', 'null']], 'specialties' => ['type' => 'array', 'items' => $str], 'languages' => ['type' => 'array', 'items' => $str]]),
            'ProfileUpdate' => $s(['name' => $str, 'bio' => $nstr, 'website' => $nstr, 'phone' => $nstr, 'service_area' => $nstr, 'founded_year' => ['type' => ['integer', 'null']],
                'specialties' => ['type' => 'array', 'items' => $str], 'languages' => ['type' => 'array', 'items' => $str]]),
        ];
    }
}
