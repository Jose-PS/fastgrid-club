import { router, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { useT } from '@/hooks/use-t';
import { update } from '@/routes/locale';

const LOCALES = ['es', 'en'] as const;

export function LocaleSwitcher() {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;

    const change = (next: string): void => {
        if (next === locale) {
            return;
        }

        router.post(update().url, { locale: next }, { preserveScroll: true });
    };

    return (
        <div
            role="group"
            aria-label={t('ui.lang')}
            className="inline-flex items-center rounded-sm border border-border bg-surface p-0.5"
        >
            {LOCALES.map((code) => (
                <button
                    key={code}
                    type="button"
                    lang={code}
                    aria-pressed={locale === code}
                    onClick={() => change(code)}
                    className={cn(
                        'inline-flex min-h-11 min-w-11 items-center justify-center rounded-[4px] px-3 text-[13px] leading-none font-semibold uppercase transition-colors duration-150 ease-out',
                        locale === code
                            ? 'bg-surface-2 text-fg ring-1 ring-border-strong ring-inset'
                            : 'text-muted hover:text-fg',
                    )}
                >
                    {code}
                </button>
            ))}
        </div>
    );
}
