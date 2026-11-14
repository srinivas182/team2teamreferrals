import { Input, Select, Textarea } from '@/components/ui/field';

export type FormField = { key: string; label: string; type: string; required?: boolean; options?: string[] };
export type Answer = string | string[] | boolean;

/** One question from an industry's referral form, as the right kind of input. */
export function AnswerInput({ f, value, onChange }: { f: FormField; value: Answer | undefined; onChange: (v: Answer) => void }) {
    if (f.type === 'textarea') return <Textarea rows={3} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} />;
    if (f.type === 'choice')
        return (
            <Select value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)}>
                <option value="">Choose…</option>
                {(f.options ?? []).map((o) => (
                    <option key={o}>{o}</option>
                ))}
            </Select>
        );
    if (f.type === 'multi_choice')
        return (
            <div className="flex flex-wrap gap-3">
                {(f.options ?? []).map((o) => {
                    const arr = (value as string[]) ?? [];
                    return (
                        <label key={o} className="flex items-center gap-1.5 text-sm">
                            <input
                                type="checkbox"
                                checked={arr.includes(o)}
                                onChange={(e) => onChange(e.target.checked ? [...arr, o] : arr.filter((x) => x !== o))}
                            />{' '}
                            {o}
                        </label>
                    );
                })}
            </div>
        );
    if (f.type === 'boolean')
        return (
            <Select value={value === undefined ? '' : value ? 'yes' : 'no'} onChange={(e) => onChange(e.target.value === 'yes')}>
                <option value="">Choose…</option>
                <option value="yes">Yes</option>
                <option value="no">No</option>
            </Select>
        );
    return (
        <Input
            type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'}
            value={(value as string) ?? ''}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}
