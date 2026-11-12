import { router, useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Preview = {
    weights: Record<string, number>;
    result: { teams: number; up: number; down: number; averageChange: number; biggest: { team: string; from: number; to: number }[] };
};
type Props = {
    parts: { key: string; label: string; default: number }[];
    weights: Record<string, number>;
    preview?: Preview | null;
    status?: string;
    seo: Seo;
};

/** Admin → Trust score: how much each part counts. Preview the effect, then save; it applies at the next nightly run. */
export default function Trust({ parts, weights, preview, status, seo }: Props) {
    const f = useForm({ weights: preview?.weights ?? weights });
    const total = Object.values(f.data.weights).reduce((a, b) => a + Number(b || 0), 0);
    return (
        <AdminLayout title="Trust score">
            <SeoHead seo={seo} />
            <div className="max-w-3xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <Card className="space-y-3 p-5">
                    <p className="text-sm text-muted">
                        The public trust score covers the last 12 months (recent activity counts more). Parts without at least 3 items don’t count,
                        and their weight is shared among the others, so fee reliability drops out where there are no fees. Seat challenges use the
                        service score, not this.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {parts.map((p) => (
                            <Field
                                key={p.key}
                                label={`${p.label} (default ${p.default})`}
                                error={(f.errors as Record<string, string>)[`weights.${p.key}`]}
                            >
                                <Input
                                    type="number"
                                    min="0"
                                    max="100"
                                    value={f.data.weights[p.key] ?? 0}
                                    onChange={(e) => f.setData('weights', { ...f.data.weights, [p.key]: Number(e.target.value) })}
                                />
                            </Field>
                        ))}
                    </div>
                    <p className="text-xs text-muted">
                        Weights are relative (total {total}); each part counts {total ? '' : 'nothing until one is above zero'}in proportion to its
                        weight.
                    </p>
                    {(f.errors as Record<string, string>).weights && (
                        <p className="text-sm text-danger">{(f.errors as Record<string, string>).weights}</p>
                    )}
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => router.post('/admin/trust/preview', { weights: f.data.weights }, { preserveScroll: true })}
                        >
                            Preview the effect
                        </Button>
                        <Button
                            size="sm"
                            onClick={() =>
                                window.confirm('Save these weights? Scores update at the next nightly run.') &&
                                f.post('/admin/trust', { preserveScroll: true })
                            }
                        >
                            Save
                        </Button>
                    </div>
                </Card>
                {preview && (
                    <Card className="space-y-2 p-5 text-sm">
                        <h2 className="font-semibold">With these weights</h2>
                        <p>
                            Of {preview.result.teams} scored teams, {preview.result.up} would go up and {preview.result.down} down (average change{' '}
                            {preview.result.averageChange > 0 ? '+' : ''}
                            {preview.result.averageChange}).
                        </p>
                        {preview.result.biggest.length > 0 && (
                            <ul className="space-y-0.5 text-muted">
                                {preview.result.biggest.map((b) => (
                                    <li key={b.team}>
                                        {b.team}: {b.from} → {b.to}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
            </div>
        </AdminLayout>
    );
}
