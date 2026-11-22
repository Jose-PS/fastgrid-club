import { usePage } from '@inertiajs/react';
import { DiscordSymbol } from '@/components/social-symbols';
import { SocialLinks } from '@/components/social-links';
import type { SocialUrls } from '@/components/social-links';
import { useT } from '@/hooks/use-t';

export function DiscordInvite() {
    const t = useT();
    const { social } = usePage<{ social: SocialUrls }>().props;

    return (
        <aside
            aria-labelledby="discord-invite-title"
            className="grid gap-3 rounded-md border border-border px-4 py-3.5"
        >
            <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                <div className="grid gap-1.5">
                    <h2
                        id="discord-invite-title"
                        className="text-[15px] leading-tight font-semibold"
                    >
                        {t('hero.discordTitle')}
                    </h2>
                    <p className="max-w-[52ch] text-sm text-muted">
                        {t('hero.discordBody')}
                    </p>
                </div>
                <a
                    href={social.discord}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-primary bg-primary px-4 text-sm font-semibold text-on-primary transition-[filter] duration-150 ease-out hover:brightness-110"
                >
                    <DiscordSymbol className="size-4" />
                    {t('hero.discordCta')}
                    <span className="sr-only">{t('foot.newTab')}</span>
                </a>
            </div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-border pt-3">
                <h3 className="text-sm font-semibold text-muted">
                    {t('hero.follow')}
                </h3>
                <SocialLinks
                    networks={['instagram', 'x', 'bluesky']}
                    showLabels
                />
            </div>
        </aside>
    );
}
