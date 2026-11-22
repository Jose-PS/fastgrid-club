import { Skeleton } from '@/components/skeleton';
import { useT } from '@/hooks/use-t';

export function EmptyState() {
    const t = useT();

    return (
        <div className="grid gap-4 rounded-md border border-border bg-surface p-4.5">
            <Skeleton className="h-6 w-2/5" />
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-4/5" />
            <p className="text-sm text-muted">{t('ui.empty')}</p>
        </div>
    );
}
