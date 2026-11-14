import { useMemo, useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Schema = {
    $ref?: string;
    type?: string | string[];
    properties?: Record<string, Schema>;
    required?: string[];
    description?: string;
    enum?: string[];
    const?: unknown;
    items?: Schema;
};
type Param = { name: string; in: string; required?: boolean; description?: string; schema?: Schema; $ref?: string };
type Operation = {
    operationId: string;
    tags: string[];
    summary: string;
    'x-scope': string;
    parameters?: Param[];
    requestBody?: { content: { 'application/json': { schema: Schema } } };
    responses: Record<string, { description?: string; $ref?: string }>;
};
type Spec = {
    info: { title: string; description: string };
    servers: { url: string }[];
    tags: { name: string }[];
    paths: Record<string, Record<string, Operation>>;
    components: { schemas: Record<string, Schema>; parameters: Record<string, Param> };
};
type Props = { spec: Spec; scopes: Record<string, string>; limits: { base: number; plan: number }; seo: Seo };

const METHOD_TONE: Record<string, string> = {
    get: 'bg-primary/10 text-primary',
    post: 'bg-success/15 text-success',
    patch: 'bg-warning/15 text-warning',
};

/** /developers: the API documentation, generated from the same description the API is tested against. */
export default function Developers({ spec, scopes, limits, seo }: Props) {
    const ops = useMemo(
        () =>
            Object.entries(spec.paths).flatMap(([path, methods]) =>
                Object.entries(methods).map(([method, op]) => ({ path, method, op: op as Operation })),
            ),
        [spec],
    );
    const [tag, setTag] = useState(spec.tags[0]?.name ?? '');
    const base = spec.servers[0]?.url ?? '/api/v1';
    const deref = (s?: Schema): Schema | undefined => (s?.$ref ? spec.components.schemas[s.$ref.split('/').pop() ?? ''] : s);
    const param = (p: Param): Param => (p.$ref ? (spec.components.parameters[p.$ref.split('/').pop() ?? ''] ?? p) : p);
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-6xl px-4 py-10">
                <h1 className="font-display text-4xl font-bold">API for developers</h1>
                <p className="mt-3 max-w-3xl text-lg text-muted">
                    Send and track referrals between verified teams from your own systems: create a referral, follow every status change, and read
                    your leads, networks, seats and profile. Every team gets the API with its seat.
                </p>
                <section aria-labelledby="start" className="mt-8 grid gap-6 lg:grid-cols-2">
                    <div>
                        <h2 id="start" className="font-display text-2xl font-bold">
                            Getting started
                        </h2>
                        <ol className="mt-3 list-decimal space-y-2 pl-5">
                            <li>
                                In the portal, open <strong>Developers (API)</strong> and create a token. Start with a <strong>test</strong> token: it
                                works in a private sandbox network with sample teams and never contacts anyone.
                            </li>
                            <li>
                                Send it as <code>Authorization: Bearer t2t_test_…</code> to <code>{base}</code>.
                            </li>
                            <li>
                                Find your network with <code>GET /groups</code>, a city with <code>GET /cities?q=Austin</code>, then{' '}
                                <code>POST /referrals</code>.
                            </li>
                            <li>
                                In the sandbox, <code>POST /sandbox/referrals/{'{id}'}/simulate</code> makes the receiving sample team accept, decline
                                or move your referral.
                            </li>
                        </ol>
                    </div>
                    <pre className="overflow-x-auto rounded-lg bg-ink p-4 text-xs text-white">
                        {`curl ${base}/referrals \\
  -H "Authorization: Bearer t2t_test_..." \\
  -H "Idempotency-Key: crm-lead-4821" \\
  -H "Content-Type: application/json" \\
  -d '{
    "group": "api-sandbox-real-estate",
    "city_id": 1234,
    "consent": true,
    "answers": {
      "client_name": "Ana Lopez",
      "client_phone": "+15125550123",
      "client_email": "ana@example.com"
    }
  }'`}
                    </pre>
                </section>
                <section aria-labelledby="rules" className="mt-10 grid gap-6 md:grid-cols-2">
                    <div>
                        <h2 id="rules" className="font-display text-2xl font-bold">
                            The rules
                        </h2>
                        <ul className="mt-3 list-disc space-y-2 pl-5 text-sm">
                            <li>
                                <strong>Same rules as the app.</strong> Your client must have agreed to be contacted (<code>"consent": true</code>). A
                                receiving team sees the client’s first name and initial, and no contact details, until it accepts.
                            </li>
                            <li>
                                <strong>Rate limits:</strong> {limits.base} requests a minute per token ({limits.plan} with the API plan). Each
                                response carries <code>X-RateLimit-Remaining</code>; a <code>429</code> carries <code>Retry-After</code>.
                            </li>
                            <li>
                                <strong>Safe retries:</strong> send an <code>Idempotency-Key</code> header with any POST or PATCH. Repeating the
                                request with the same key within 24 hours returns the first answer instead of doing it twice.
                            </li>
                            <li>
                                <strong>Paging:</strong> lists return <code>has_more</code> and <code>next_cursor</code>; pass <code>cursor</code> to
                                get the next page.
                            </li>
                            <li id="errors-422">
                                <strong>Errors</strong> are{' '}
                                <a className="text-primary" href="https://www.rfc-editor.org/rfc/rfc9457">
                                    problem details
                                </a>
                                : <code>{'{ "title", "status", "detail", "errors" }'}</code>.
                            </li>
                        </ul>
                    </div>
                    <div>
                        <h2 className="font-display text-2xl font-bold">Permissions (scopes)</h2>
                        <table className="mt-3 w-full text-sm">
                            <tbody>
                                {Object.entries(scopes).map(([k, v]) => (
                                    <tr key={k} className="border-t border-line">
                                        <td className="py-1.5 pr-3 align-top">
                                            <code>{k}</code>
                                        </td>
                                        <td className="py-1.5 text-muted">{v}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
                <section aria-labelledby="reference" className="mt-10">
                    <div className="flex flex-wrap items-baseline justify-between gap-3">
                        <h2 id="reference" className="font-display text-2xl font-bold">
                            Reference
                        </h2>
                        <a href="/api/v1/openapi.json" className="text-sm font-medium text-primary">
                            OpenAPI description (JSON)
                        </a>
                    </div>
                    <nav className="mt-3 flex gap-1 overflow-x-auto border-b border-line" aria-label="API sections">
                        {spec.tags.map((t) => (
                            <button
                                key={t.name}
                                type="button"
                                aria-current={tag === t.name ? 'page' : undefined}
                                onClick={() => setTag(t.name)}
                                className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap ${tag === t.name ? 'border-primary' : 'border-transparent text-muted'}`}
                            >
                                {t.name}
                            </button>
                        ))}
                    </nav>
                    <div className="mt-4 space-y-4">
                        {ops
                            .filter((o) => o.op.tags.includes(tag))
                            .map(({ path, method, op }) => {
                                const body = deref(op.requestBody?.content['application/json'].schema);
                                return (
                                    <article key={op.operationId} id={op.operationId} className="rounded-lg border border-line p-4">
                                        <h3 className="flex flex-wrap items-center gap-2 font-semibold">
                                            <span className={`rounded px-2 py-0.5 text-xs uppercase ${METHOD_TONE[method] ?? ''}`}>{method}</span>
                                            <code className="text-sm break-all">{path}</code>
                                        </h3>
                                        <p className="mt-1">{op.summary}</p>
                                        {op['x-scope'] && (
                                            <p className="text-xs text-muted">
                                                Permission: <code>{op['x-scope']}</code>
                                            </p>
                                        )}
                                        {(op.parameters?.length ?? 0) > 0 && (
                                            <dl className="mt-2 grid gap-1 text-sm sm:grid-cols-[180px_1fr]">
                                                {op.parameters!.map(param).map((p) => (
                                                    <div key={`${p.in}-${p.name}`} className="contents">
                                                        <dt>
                                                            <code>{p.name}</code>{' '}
                                                            <span className="text-xs text-muted">
                                                                ({p.in}
                                                                {p.required ? ', required' : ''})
                                                            </span>
                                                        </dt>
                                                        <dd className="text-muted">{p.description}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                        {body?.properties && (
                                            <dl className="mt-2 grid gap-1 text-sm sm:grid-cols-[180px_1fr]">
                                                {Object.entries(body.properties).map(([k, v]) => (
                                                    <div key={k} className="contents">
                                                        <dt>
                                                            <code>{k}</code>{' '}
                                                            {body.required?.includes(k) && <span className="text-xs text-muted">(required)</span>}
                                                        </dt>
                                                        <dd className="text-muted">{v.description ?? (v.enum ? v.enum.join(', ') : '')}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                        <p className="mt-2 text-xs text-muted">Responses: {Object.keys(op.responses).join(', ')}</p>
                                    </article>
                                );
                            })}
                    </div>
                </section>
            </div>
        </PublicLayout>
    );
}
