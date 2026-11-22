import { useSyncExternalStore } from 'react';
import { getTheme, setTheme, subscribeTheme } from '@/lib/theme';
import type { Theme } from '@/lib/theme';

export type UseThemeReturn = {
    readonly theme: Theme;
    readonly toggleTheme: () => void;
};

export function useTheme(): UseThemeReturn {
    const theme = useSyncExternalStore<Theme>(
        subscribeTheme,
        getTheme,
        () => 'dark',
    );

    const toggleTheme = (): void => {
        setTheme(theme === 'dark' ? 'light' : 'dark');
    };

    return { theme, toggleTheme } as const;
}
