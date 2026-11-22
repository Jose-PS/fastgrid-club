import { usePage } from '@inertiajs/react';
import { useT } from '@/hooks/use-t';
import type { Donation } from '@/lib/donation';
import { clampPercent, formatEuros } from '@/lib/donation';

export function DonationMeter({ donation }: { donation: Donation }) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;

    if (donation.goal_cents <= 0) {
        return null;
    }

    const amounts = t('donate.amounts', {
        covered: formatEuros(donation.covered_cents, locale),
        goal: formatEuros(donation.goal_cents, locale),
    });
    const percent = clampPercent(donation.percent);

    return (
        <div className="grid gap-2">
            <p className="mono text-sm tabular-nums">{amounts}</p>
            <div
                role="progressbar"
                aria-label={t('donate.title')}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                aria-valuetext={amounts}
                className="h-2 overflow-hidden rounded-full border border-border bg-surface-2"
            >
                <i
                    className="block h-full bg-fg opacity-85"
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}
