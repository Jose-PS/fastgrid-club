export function resolveTabId(
    ids: string[],
    candidate: string | null | undefined,
    fallback?: string,
): string {
    if (candidate && ids.includes(candidate)) {
        return candidate;
    }

    if (fallback && ids.includes(fallback)) {
        return fallback;
    }

    return ids[0] ?? '';
}

export function readTabFromUrl(url: string, queryKey: string): string | null {
    const queryStart = url.indexOf('?');

    if (queryStart === -1) {
        return null;
    }

    const hashStart = url.indexOf('#', queryStart);
    const query = url.slice(
        queryStart + 1,
        hashStart === -1 ? undefined : hashStart,
    );

    return new URLSearchParams(query).get(queryKey);
}

export function buildTabUrl(url: string, queryKey: string, id: string): string {
    const parsed = new URL(url, 'http://localhost');

    parsed.searchParams.set(queryKey, id);

    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
}
