import { useEffect, useRef } from 'react';

const TRACK_PATH =
    'M60 230 L250 232 C285 233 300 222 296 205 L286 188 C280 176 292 168 304 170 L330 176 C350 180 362 166 354 150 L340 128 C333 116 318 112 308 118 L282 132 C268 139 252 134 250 122 L248 106 C246 92 232 84 218 88 L186 98 C170 103 156 96 154 82 C152 66 136 58 120 66 L84 86 C68 94 60 108 64 124 L74 150 C78 162 72 172 62 178 L50 186 C38 194 38 214 60 230 Z';

export function TrackDiagram() {
    return (
        <svg
            viewBox="0 0 400 270"
            className="block h-auto w-full"
            aria-hidden="true"
            focusable="false"
        >
            <path className="track-edge" d={TRACK_PATH} />
            <path className="track-asphalt" d={TRACK_PATH} />
            <line className="track-line" x1="140" y1="222" x2="140" y2="240" />
            <circle className="track-car" r="5" />
            <circle className="track-car" r="5" />
            <circle className="track-car" r="5" />
        </svg>
    );
}

const SVG_NS = 'http://www.w3.org/2000/svg';
const CAR_COUNT = 3;
const LAP_MIN_SECONDS = 18;
const LAP_MAX_SECONDS = 40;
const LAP_UNITS_PER_SECOND = 38;

export type TrackSvgData = {
    markup: string;
    attribution: string | null;
};

export function TrackSvgFigure({
    data,
    label,
}: {
    data: TrackSvgData;
    label: string;
}) {
    const figureRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const svg = figureRef.current?.querySelector('svg');
        const asphalt =
            svg?.querySelector<SVGPathElement>('path.track-asphalt');
        const pathData = asphalt?.getAttribute('d');

        if (!svg || !asphalt || !pathData) {
            return;
        }

        const length = asphalt.getTotalLength();
        const lap = Math.min(
            LAP_MAX_SECONDS,
            Math.max(LAP_MIN_SECONDS, length / LAP_UNITS_PER_SECOND),
        );
        const cars: SVGCircleElement[] = [];

        for (let index = 0; index < CAR_COUNT; index += 1) {
            const car = document.createElementNS(SVG_NS, 'circle');
            car.setAttribute('class', 'track-car');
            car.setAttribute('r', '5');
            car.style.offsetPath = `path("${pathData}")`;
            car.style.setProperty('--lap-duration', `${lap.toFixed(1)}s`);
            svg.appendChild(car);
            cars.push(car);
        }

        return () => {
            cars.forEach((car) => car.remove());
        };
    }, [data.markup]);

    return (
        <div className="grid gap-1.5">
            <div
                ref={figureRef}
                role="img"
                aria-label={label}
                className="text-fg [&>svg]:block [&>svg]:h-auto [&>svg]:w-full"
                dangerouslySetInnerHTML={{ __html: data.markup }}
            />
            {data.attribution !== null ? (
                <p className="text-xs text-muted">{data.attribution}</p>
            ) : null}
        </div>
    );
}
