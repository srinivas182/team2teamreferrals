import { Eye, EyeOff } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

const control =
    'w-full rounded-md border border-line bg-surface px-3 text-[15px] text-ink placeholder:text-subtle focus:border-primary focus:ring-2 focus:ring-primary/25 focus:outline-none disabled:opacity-60 aria-[invalid=true]:border-danger aria-[invalid=true]:focus:ring-danger/25';

type FieldProps = {
    label: string;
    hint?: string;
    error?: string;
    className?: string;
    children: React.ReactElement<{ id?: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean }>;
};

/** Label, control, and hint or error — wired together for screen readers. */
export function Field({ label, hint, error, className, children }: FieldProps) {
    const id = React.useId();
    const controlId = children.props.id ?? id;
    const describedBy = error || hint ? `${controlId}-desc` : undefined;
    return (
        <div className={cn('space-y-1.5', className)}>
            <label htmlFor={controlId} className="block text-sm font-medium text-ink">
                {label}
            </label>
            {React.cloneElement(children, { id: controlId, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined })}
            {(error || hint) && (
                <p id={describedBy} className={cn('text-sm', error ? 'text-danger dark:text-[#FDA4AF]' : 'text-muted')}>
                    {error ?? hint}
                </p>
            )}
        </div>
    );
}

export const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(({ className, ...p }, ref) => (
    <input ref={ref} className={cn(control, 'h-11', className)} {...p} />
));
Input.displayName = 'Input';

/** A password box with a show/hide button, so people can check what they typed (especially on phones). */
export const PasswordInput = React.forwardRef<HTMLInputElement, Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'>>(
    ({ className, ...p }, ref) => {
        const [shown, setShown] = React.useState(false);
        return (
            <div className="relative">
                <input
                    ref={ref}
                    type={shown ? 'text' : 'password'}
                    className={cn(control, 'h-11 pr-11', className)}
                    autoCapitalize="none"
                    spellCheck={false}
                    {...p}
                />
                <button
                    type="button"
                    onClick={() => setShown((v) => !v)}
                    aria-label={shown ? 'Hide password' : 'Show password'}
                    aria-pressed={shown}
                    className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-md text-muted hover:text-ink focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                >
                    {shown ? <EyeOff className="size-[18px]" aria-hidden /> : <Eye className="size-[18px]" aria-hidden />}
                </button>
            </div>
        );
    },
);
PasswordInput.displayName = 'PasswordInput';

/** A US mobile number: the country is shown in front (the service is US-only for now); people type the rest. */
export const PhoneInput = React.forwardRef<HTMLInputElement, Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'>>(
    ({ className, ...p }, ref) => (
        <div className="flex">
            <span
                className="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-l-md border border-r-0 border-line bg-bg px-3 text-[15px] text-ink"
                title="United States (+1)"
            >
                <span aria-hidden>🇺🇸</span>
                <span>+1</span>
                <span className="sr-only">United States</span>
            </span>
            <input
                ref={ref}
                type="tel"
                inputMode="tel"
                autoComplete="tel-national"
                className={cn(control, 'h-11 rounded-l-none', className)}
                {...p}
            />
        </div>
    ),
);
PhoneInput.displayName = 'PhoneInput';

export const Select = React.forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(({ className, ...p }, ref) => (
    <select ref={ref} className={cn(control, 'h-11 pr-8', className)} {...p} />
));
Select.displayName = 'Select';

export const Textarea = React.forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement>>(({ className, ...p }, ref) => (
    <textarea ref={ref} className={cn(control, 'min-h-24 py-2.5', className)} {...p} />
));
Textarea.displayName = 'Textarea';
