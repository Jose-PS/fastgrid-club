import { Link } from '@inertiajs/react';
import { Coins } from 'lucide-react';
import { DonationMeter } from '@/components/donations/donation-meter';
import { usePublicRoutes } from '@/hooks/use-public-routes';
import { useT } from '@/hooks/use-t';
import type { Donation } from '@/lib/donation';

export function DonationBlock({ donation }: { donation: Donation }) {
    const t = useT();
    const { donate } = usePublicRoutes();

    return (
        <aside
            aria-label={t('donate.title')}
            className="grid gap-3 rounded-md border border-border px-4 py-3.5"
        >
            <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                <div className="grid gap-1.5">
                    <h3 className="text-[15px] leading-tight font-semibold">
                        {t('donate.title')}
                    </h3>
                    <p className="max-w-[52ch] text-sm text-muted">
                        {t('donate.body')}
                    </p>
                </div>
                {donation.methods.length > 0 ? (
                    <Link
                        href={donate().url}
                        className="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-border-strong px-3 text-sm font-semibold text-fg transition-colors duration-150 ease-out hover:bg-surface-2"
                    >
                        <Coins className="size-4" aria-hidden="true" />
                        {t('donate.cta')}
                    </Link>
                ) : null}
            </div>
            <DonationMeter donation={donation} />
        </aside>
    );
}
