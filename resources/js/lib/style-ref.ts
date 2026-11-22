export type StyleProperties = Record<string, string>;

export function styleRef(
    properties: StyleProperties,
): (element: HTMLElement | null) => void {
    return (element) => {
        if (element === null) {
            return;
        }

        Object.entries(properties).forEach(([name, value]) => {
            element.style.setProperty(name, value);
        });
    };
}
