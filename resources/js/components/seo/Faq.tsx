/** Visible FAQ that mirrors the FAQPage structured data (answer engines expect both to match). */
export function Faq({ items, title = 'Questions pros ask' }: { items: { q: string; a: string }[]; title?: string }) {
    return (
        <section aria-labelledby="faq-title" className="mx-auto max-w-3xl px-5 py-14">
            <h2 id="faq-title" className="font-display text-2xl font-bold">
                {title}
            </h2>
            <div className="mt-5 divide-y divide-line rounded-lg border border-line bg-surface">
                {items.map((x) => (
                    <details key={x.q} className="group p-5">
                        <summary className="cursor-pointer list-none font-medium marker:hidden">
                            <h3 className="inline">{x.q}</h3>
                        </summary>
                        <p className="mt-2 text-muted">{x.a}</p>
                    </details>
                ))}
            </div>
        </section>
    );
}
