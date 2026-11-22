export type CarName = {
    model: string;
    variant: string | null;
};

const VARIANT_SEPARATOR = ' - ';

export function splitCarName(displayName: string): CarName {
    const index = displayName.lastIndexOf(VARIANT_SEPARATOR);

    if (index <= 0) {
        return { model: displayName, variant: null };
    }

    const variant = displayName.slice(index + VARIANT_SEPARATOR.length).trim();
    const model = displayName.slice(0, index).trim();

    if (variant === '' || model === '') {
        return { model: displayName, variant: null };
    }

    return { model, variant };
}
