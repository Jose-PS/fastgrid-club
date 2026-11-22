import { usePage } from '@inertiajs/react';
import type { ComponentType } from 'react';
import {
    BlueskySymbol,
    DiscordSymbol,
    GitHubSymbol,
    InstagramSymbol,
    XSymbol,
} from '@/components/social-symbols';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';

export type SocialKey = 'discord' | 'instagram' | 'x' | 'bluesky' | 'github';

export type SocialUrls = Record<SocialKey, string>;

const NETWORKS: Record<
    SocialKey,
    { label: string; Icon: ComponentType<{ className?: string }> }
> = {
    discord: { label: 'Discord', Icon: DiscordSymbol },
    instagram: { label: 'Instagram', Icon: InstagramSymbol },
    x: { label: 'x.com', Icon: XSymbol },
    bluesky: { label: 'Bluesky', Icon: BlueskySymbol },
    github: { label: 'GitHub', Icon: GitHubSymbol },
};

export function SocialLinks({
    networks,
    showLabels = false,
    className,
}: {
    networks: SocialKey[];
    showLabels?: boolean;
    className?: string;
}) {
    const t = useT();
    const { social } = usePage<{ social: SocialUrls }>().props;

    return (
        <ul
            className={cn(
                'flex flex-wrap items-center gap-x-1 gap-y-1',
                className,
            )}
        >
            {networks.map((network) => {
                const entry = NETWORKS[network];
                const { label, Icon } = entry;

                return (
                    <li key={network}>
                        <a
                            href={social[network]}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-sm px-2 text-fg transition-colors duration-150 ease-out hover:bg-surface-2 hover:text-primary-ink"
                        >
                            <Icon className="size-4 shrink-0" />
                            {showLabels ? (
                                <span className="text-sm font-semibold">
                                    {label}
                                </span>
                            ) : (
                                <span className="sr-only">{label}</span>
                            )}
                            <span className="sr-only">{t('foot.newTab')}</span>
                        </a>
                    </li>
                );
            })}
        </ul>
    );
}
