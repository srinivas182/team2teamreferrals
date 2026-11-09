import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

export const buttonVariants = cva(
    'inline-flex shrink-0 items-center justify-center gap-2 rounded-md font-semibold whitespace-nowrap transition-colors duration-[var(--duration-fast)] disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                primary: 'bg-primary text-on-primary hover:bg-primary-hover',
                accent: 'bg-accent text-on-accent hover:brightness-95',
                secondary: 'border border-line bg-surface text-ink hover:bg-bg',
                ghost: 'text-ink hover:bg-bg',
                danger: 'bg-danger text-white hover:brightness-95',
                link: 'h-auto px-0 text-primary underline-offset-4 hover:underline',
            },
            size: { sm: 'h-9 px-3 text-sm', md: 'h-11 px-4 text-[15px]', lg: 'h-12 px-6 text-base', icon: 'size-10' },
        },
        defaultVariants: { variant: 'primary', size: 'md' },
    },
);

export type ButtonProps = React.ButtonHTMLAttributes<HTMLButtonElement> &
    VariantProps<typeof buttonVariants> & {
        /** Render the child element (e.g. an Inertia Link) with button styles. */
        asChild?: boolean;
        /** Shows a spinner and keeps the button's width. */
        loading?: boolean;
    };

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, asChild = false, loading = false, children, disabled, ...props }, ref) => {
        const Comp = asChild ? Slot : 'button';
        return (
            <Comp
                ref={ref}
                className={cn(buttonVariants({ variant, size }), className)}
                disabled={disabled || loading}
                aria-busy={loading || undefined}
                {...props}
            >
                {asChild ? (
                    children
                ) : (
                    <>
                        {loading && <Loader2 className="animate-spin" aria-hidden />}
                        {children}
                    </>
                )}
            </Comp>
        );
    },
);
Button.displayName = 'Button';
