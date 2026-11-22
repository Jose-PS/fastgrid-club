import { usePage } from '@inertiajs/react';

export type TranslateParams = Record<string, string | number>;

export type Translate = (key: string, params?: TranslateParams) => string;

type TranslationProps = {
    translations?: Record<string, string>;
    pageTranslations?: Record<string, string>;
};

export function useT(): Translate {
    const { translations, pageTranslations } =
        usePage<TranslationProps>().props;

    return (key, params) => {
        const text = pageTranslations?.[key] ?? translations?.[key];

        if (text === undefined) {
            return key;
        }

        if (!params) {
            return text;
        }

        return text.replace(/:([A-Za-z_]+)/g, (match, name: string) =>
            name in params ? String(params[name]) : match,
        );
    };
}
