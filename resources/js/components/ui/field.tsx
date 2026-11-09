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

export const Select = React.forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(({ className, ...p }, ref) => (
    <select ref={ref} className={cn(control, 'h-11 pr-8', className)} {...p} />
));
Select.displayName = 'Select';

export const Textarea = React.forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement>>(({ className, ...p }, ref) => (
    <textarea ref={ref} className={cn(control, 'min-h-24 py-2.5', className)} {...p} />
));
Textarea.displayName = 'Textarea';
