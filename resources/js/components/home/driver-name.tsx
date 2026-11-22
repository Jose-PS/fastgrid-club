import { Badge } from '@/components/ui/badge';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';

export function DriverName({
    name,
    hidden,
    isYou,
}: {
    name: string;
    hidden: boolean;
    isYou: boolean;
}) {
    const t = useT();

    return (
        <span className="inline-flex items-center gap-2">
            <span className={cn(hidden && 'text-muted italic')}>{name}</span>
            {isYou ? <Badge>{t('lb.you')}</Badge> : null}
        </span>
    );
}
