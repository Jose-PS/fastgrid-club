import { Head } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { sectionClass, sectionTitleClass } from '@/components/account-styles';
import { PlatformIcon } from '@/components/donations/platform-icon';
import { DonationMeter } from '@/components/donations/donation-meter';
import { useT } from '@/hooks/use-t';
import type { Donation } from '@/lib/donation';

export default function Donate({ donation }: { donation: Donation }) {
    const t = useT();

    return (
        <>
            <Head title={t('donate.pageTitle')} />
            <div className="grid gap-7">
                <div className="grid gap-2">
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('donate.pageTitle')}
                    </h1>
                    <p className="max-w-[52ch] text-muted">
                        {t('donate.pageBody')}
                    </p>
                </div>
                <section
                    aria-labelledby="donate-month-title"
                    className={sectionClass}
                >
                    <h2 id="donate-month-title" className={sectionTitleClass}>
                        {t('donate.title')}
                    </h2>
                    <p className="max-w-[52ch] text-sm text-muted">
                        {t('donate.body')}
                    </p>
                    <DonationMeter donation={donation} />
                </section>
                {donation.methods.length > 0 ? (
                    <section
                        aria-labelledby="donate-methods-title"
                        className="grid gap-4"
                    >
                        <h2
                            id="donate-methods-title"
                            className={sectionTitleClass}
                        >
                            {t('donate.methods')}
                        </h2>
                        <ul className="grid gap-4 md:grid-cols-3">
                            {donation.methods.map((method) => {
                                const name = t(
                                    `donate.platform.${method.platform}`,
                                );

                                return (
                                    <li
                                        key={method.platform}
                                        className="grid content-between gap-4 rounded-md border border-border bg-surface p-4.5"
                                    >
                                        <div className="grid gap-1.5">
                                            <div className="flex items-center gap-2.5">
                                                <PlatformIcon
                                                    platform={method.platform}
                                                    className="size-6 flex-none"
                                                />
                                                <h3 className="font-display text-xl leading-tight font-bold tracking-[-0.01em]">
                                                    {name}
                                                </h3>
                                            </div>
                                            <p className="text-sm text-muted">
                                                {t(
                                                    `donate.platform.${method.platform}.desc`,
                                                )}
                                            </p>
                                        </div>
                                        <a
                                            href={method.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-primary bg-primary px-4 text-sm font-semibold text-on-primary transition-[filter] duration-150 ease-out hover:brightness-110"
                                        >
                                            {t('donate.open', {
                                                platform: name,
                                            })}
                                            <ExternalLink
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                ) : null}
            </div>
        </>
    );
}
