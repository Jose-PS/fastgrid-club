export function BrandSymbol({ size = 22 }: { size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            strokeWidth="2.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
        >
            <path d="M4 21V13C4 7.5 7.5 4 13 4H21" stroke="currentColor" />
            <path
                d="M11 21V17C11 14 13 12 16 12H21"
                className="stroke-primary-ink"
            />
        </svg>
    );
}
