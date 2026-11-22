import { splitCarName } from '@/lib/car-name';
import { cn } from '@/lib/utils';

export function CarLabel({
    name,
    className,
}: {
    name: string;
    className?: string;
}) {
    const { model, variant } = splitCarName(name);

    return (
        <span className={cn('min-w-0', className)}>
            {model}
            {variant ? (
                <span className="ml-1.5 text-xs font-normal text-muted">
                    {variant}
                </span>
            ) : null}
        </span>
    );
}
