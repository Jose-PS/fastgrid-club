import { Moon, Sun } from 'lucide-react';
import { useT } from '@/hooks/use-t';
import { useTheme } from '@/hooks/use-theme';

export function ThemeToggle() {
    const t = useT();
    const { theme, toggleTheme } = useTheme();
    const Icon = theme === 'dark' ? Sun : Moon;

    return (
        <button
            type="button"
            aria-label={t('ui.theme')}
            onClick={toggleTheme}
            className="grid size-11 place-items-center rounded-sm border border-border text-fg transition-colors duration-150 ease-out hover:bg-surface-2"
        >
            <Icon className="size-[22px]" aria-hidden="true" />
        </button>
    );
}
