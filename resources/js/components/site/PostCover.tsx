import { Newspaper } from 'lucide-react';
import { cn } from '@/lib/utils';

const palettes = [
    'from-[#1E3A8A] to-[#2563EB]',
    'from-[#9A3412] to-[#F97316]',
    'from-[#065F46] to-[#10B981]',
    'from-[#312E81] to-[#6366F1]',
    'from-[#134E4A] to-[#14B8A6]',
    'from-[#831843] to-[#EC4899]',
];

/** A post's cover image, or a cover in a colour picked from its category so each category looks the same everywhere. */
export function PostCover({ image, alt, category, className }: { image: string | null; alt: string; category: string | null; className?: string }) {
    if (image) {
        return <img src={image} alt={alt} loading="lazy" decoding="async" className={cn('size-full object-cover', className)} />;
    }
    const key = [...(category ?? 'blog')].reduce((n, c) => n + c.charCodeAt(0), 0) % palettes.length;
    return (
        <div className={cn('flex size-full items-center justify-center bg-gradient-to-br', palettes[key], className)} aria-hidden>
            <Newspaper className="size-12 text-white/85" />
        </div>
    );
}
