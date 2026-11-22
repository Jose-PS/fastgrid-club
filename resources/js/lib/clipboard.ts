type ClipboardLike = { writeText: (text: string) => Promise<void> };

export async function copyText(
    text: string,
    clipboard: ClipboardLike | undefined = typeof navigator === 'undefined'
        ? undefined
        : navigator.clipboard,
): Promise<boolean> {
    if (clipboard === undefined || typeof clipboard.writeText !== 'function') {
        return false;
    }

    try {
        await clipboard.writeText(text);

        return true;
    } catch {
        return false;
    }
}
