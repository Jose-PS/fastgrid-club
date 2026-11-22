export function normalizeSearch(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

export function searchScore(text: string, query: string): number {
    const needle = normalizeSearch(query);

    if (needle === '') {
        return 1;
    }

    const haystack = normalizeSearch(text);
    const index = haystack.indexOf(needle);

    if (index === -1) {
        return 0;
    }

    return index === 0 ? 1 : 0.5;
}
