export type Theme = 'dark' | 'light';

export const THEME_STORAGE_KEY = 'sg-theme';

const listeners = new Set<() => void>();

const isTheme = (value: unknown): value is Theme =>
    value === 'dark' || value === 'light';

function readStoredTheme(): Theme | null {
    try {
        const value = localStorage.getItem(THEME_STORAGE_KEY);

        return isTheme(value) ? value : null;
    } catch {
        return null;
    }
}

function systemTheme(): Theme {
    return window.matchMedia('(prefers-color-scheme: light)').matches
        ? 'light'
        : 'dark';
}

export function getTheme(): Theme {
    return document.documentElement.dataset.theme === 'light'
        ? 'light'
        : 'dark';
}

function applyTheme(theme: Theme): void {
    document.documentElement.dataset.theme = theme;
    listeners.forEach((listener) => listener());
}

function storeTheme(theme: Theme): void {
    try {
        localStorage.setItem(THEME_STORAGE_KEY, theme);
    } catch {
        return;
    }
}

export function setTheme(theme: Theme): void {
    storeTheme(theme);
    applyTheme(theme);
}

export function subscribeTheme(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function watchSystemTheme(): () => void {
    const query = window.matchMedia('(prefers-color-scheme: light)');

    const onChange = (): void => {
        if (readStoredTheme() === null) {
            applyTheme(systemTheme());
        }
    };

    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}
