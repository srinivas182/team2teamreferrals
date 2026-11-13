import { Link } from '@inertiajs/react';

/** Admin → Website sections. */
export function ContentTabs({ current }: { current: string }) {
    return (
        <nav aria-label="Website sections" className="mb-4 flex gap-1 border-b border-line text-sm">
            {[
                ['/admin/content', 'Pages'],
                ['/admin/content/posts', 'Blog'],
                ['/admin/content/menus', 'Menus'],
                ['/admin/content/media', 'Media'],
                ['/admin/content/messages', 'Messages'],
                ['/admin/content/testimonials', 'Testimonials'],
            ].map(([href, label]) => (
                <Link
                    key={href}
                    href={href!}
                    aria-current={current === href ? 'page' : undefined}
                    className={`-mb-px border-b-2 px-3 py-2 ${current === href ? 'border-primary font-semibold' : 'border-transparent text-muted'}`}
                >
                    {label}
                </Link>
            ))}
        </nav>
    );
}
