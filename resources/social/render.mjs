import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import satori from 'satori';
import { Resvg } from '@resvg/resvg-js';

const WIDTH = 1080;
const HEIGHT = 1350;
const PAD = 72;

const C = {
    bg: '#0b0d10',
    surface: '#14181d',
    surface2: '#1b2027',
    border: '#262c34',
    text: '#eceef2',
    muted: '#8b94a1',
    primary: '#c6f432',
    onPrimary: '#0b0d10',
};

const FONT_DIR = join(
    dirname(fileURLToPath(import.meta.url)),
    '..',
    'fonts',
    'ttf',
);

const fonts = [
    ['Inter', 'inter-400.ttf', 400],
    ['Inter', 'inter-600.ttf', 600],
    ['Space Grotesk', 'space-grotesk-600.ttf', 600],
    ['Space Grotesk', 'space-grotesk-700.ttf', 700],
    ['JetBrains Mono', 'jetbrains-mono-500.ttf', 500],
].map(([name, file, weight]) => ({
    name,
    data: readFileSync(join(FONT_DIR, file)),
    weight,
    style: 'normal',
}));

const display = (style = {}) => ({
    fontFamily: 'Space Grotesk',
    fontWeight: 700,
    ...style,
});
const mono = (style = {}) => ({
    fontFamily: 'JetBrains Mono',
    fontWeight: 500,
    ...style,
});

function h(type, style, ...children) {
    const kids = children
        .flat()
        .filter(
            (child) => child !== null && child !== undefined && child !== false,
        );
    const props = {
        style: { display: 'flex', boxSizing: 'border-box', ...style },
    };
    if (kids.length === 1) {
        props.children = kids[0];
    } else if (kids.length > 1) {
        props.children = kids;
    }
    return { type, props };
}

const box = (style, ...children) => h('div', style, ...children);

const text = (style, value) =>
    box(
        { fontFamily: 'Inter', fontWeight: 400, color: C.text, ...style },
        String(value),
    );

const ellipsis = {
    display: 'block',
    overflow: 'hidden',
    textOverflow: 'ellipsis',
    whiteSpace: 'nowrap',
    minWidth: 0,
};

const dataUri = (svg) =>
    `data:image/svg+xml;base64,${Buffer.from(svg).toString('base64')}`;

const LOGO = dataUri(
    `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V13C4 7.5 7.5 4 13 4H21" stroke="${C.text}"/><path d="M11 21V17C11 14 13 12 16 12H21" stroke="${C.primary}"/></svg>`,
);

function formatTime(ms) {
    if (typeof ms !== 'number' || !Number.isFinite(ms)) {
        return '-';
    }
    const hours = Math.floor(ms / 3600000);
    const minutes = Math.floor((ms % 3600000) / 60000);
    const seconds = Math.floor((ms % 60000) / 1000);
    const millis = ms % 1000;
    const tail = `${String(seconds).padStart(2, '0')}.${String(millis).padStart(3, '0')}`;
    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${tail}`;
    }
    return `${minutes}:${tail}`;
}

function dateOf(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ''));
    if (!match) {
        return null;
    }
    return new Date(
        Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12),
    );
}

function formatDate(value, options, locale = 'es-ES') {
    const date = dateOf(value);
    if (!date) {
        return null;
    }
    return new Intl.DateTimeFormat(locale, {
        timeZone: 'UTC',
        ...options,
    }).format(date);
}

const shortDate = (value) =>
    formatDate(value, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    })?.replace(/[.,]/g, '');
const longDate = (value) =>
    formatDate(value, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    })?.replace(',', '');

const longDateEn = (value) => {
    const formatted = formatDate(
        value,
        { weekday: 'long', day: 'numeric', month: 'long' },
        'en-GB',
    )?.replace(',', '');
    return formatted ? formatted.replace(' ', ', ') : null;
};

const upper = (value) => String(value).toLocaleUpperCase('es-ES');

function trackMarkup(svg) {
    if (typeof svg !== 'string') {
        return null;
    }
    const view = /viewBox="([^"]+)"/.exec(svg)?.[1];
    const edge =
        /<path[^>]*class="track-edge"[^>]*\sd="([^"]+)"/.exec(svg) ??
        /<path[^>]*\sd="([^"]+)"[^>]*class="track-edge"/.exec(svg);
    if (!view || !edge) {
        return null;
    }
    const line = /<line[^>]*class="track-line"[^>]*>/.exec(svg)?.[0];
    const attr = (name) =>
        line ? new RegExp(`${name}="([^"]+)"`).exec(line)?.[1] : null;
    const finish =
        line && attr('x1') && attr('y1') && attr('x2') && attr('y2')
            ? `<line x1="${attr('x1')}" y1="${attr('y1')}" x2="${attr('x2')}" y2="${attr('y2')}" stroke="${C.text}" stroke-width="3.5" stroke-linecap="round"/>`
            : '';
    const [, , w, hgt] = view.split(/\s+/).map(Number);
    return {
        ratio: w / hgt,
        src: dataUri(
            `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${view}" fill="none"><path d="${edge[1]}" stroke="${C.primary}" stroke-opacity="0.18" stroke-width="12" stroke-linejoin="round" stroke-linecap="round"/><path d="${edge[1]}" stroke="${C.primary}" stroke-width="3.5" stroke-linejoin="round" stroke-linecap="round"/>${finish}</svg>`,
        ),
    };
}

function trackImage(svg, maxWidth, maxHeight) {
    const track = trackMarkup(svg);
    if (!track) {
        return null;
    }
    let width = maxWidth;
    let height = width / track.ratio;
    if (height > maxHeight) {
        height = maxHeight;
        width = height * track.ratio;
    }
    return {
        type: 'img',
        props: {
            src: track.src,
            width: Math.round(width),
            height: Math.round(height),
            style: { display: 'flex' },
        },
    };
}

function chip(label, en, style = {}) {
    return box(
        {
            alignItems: 'center',
            padding: '10px 22px',
            borderRadius: 999,
            border: `2px solid ${C.primary}`,
            color: C.primary,
            fontFamily: 'Inter',
            fontWeight: 600,
            fontSize: 24,
            letterSpacing: 3,
            ...style,
        },
        upper(label),
        box({ marginLeft: 14, opacity: 0.6 }, `· ${upper(en)}`),
    );
}

function header(label, en) {
    return box(
        {
            alignItems: 'center',
            justifyContent: 'space-between',
            width: '100%',
        },
        box(
            { alignItems: 'center' },
            {
                type: 'img',
                props: {
                    src: LOGO,
                    width: 52,
                    height: 52,
                    style: { display: 'flex' },
                },
            },
            text(
                {
                    ...display({
                        fontSize: 40,
                        marginLeft: 16,
                        letterSpacing: -0.5,
                    }),
                },
                'FastGrid',
            ),
        ),
        chip(label, en),
    );
}

function footer(host, cta, ctaEn) {
    return box(
        {
            alignItems: 'center',
            justifyContent: 'space-between',
            width: '100%',
            paddingTop: 28,
            borderTop: `2px solid ${C.border}`,
        },
        text({ ...mono({ fontSize: 30, color: C.muted }) }, host),
        cta
            ? box(
                  {
                      alignItems: 'center',
                      padding: '16px 30px',
                      borderRadius: 18,
                      backgroundColor: C.primary,
                      color: C.onPrimary,
                      fontFamily: 'Space Grotesk',
                      fontWeight: 700,
                      fontSize: 30,
                  },
                  cta,
                  box(
                      {
                          marginLeft: 12,
                          fontFamily: 'Inter',
                          fontWeight: 600,
                          fontSize: 24,
                          opacity: 0.6,
                      },
                      `· ${ctaEn}`,
                  ),
              )
            : box(
                  { flexDirection: 'column', alignItems: 'flex-end' },
                  text(
                      { fontSize: 26, color: C.muted },
                      'Corre limpio, sé más rápido.',
                  ),
                  text(
                      {
                          fontSize: 22,
                          color: C.muted,
                          opacity: 0.65,
                          marginTop: 4,
                      },
                      'Race fair. Get faster.',
                  ),
              ),
    );
}

function page(label, labelEn, host, cta, ctaEn, ...body) {
    return box(
        {
            flexDirection: 'column',
            justifyContent: 'space-between',
            width: WIDTH,
            height: HEIGHT,
            padding: PAD,
            backgroundColor: C.bg,
            backgroundImage: `radial-gradient(circle at 100% 0%, rgba(198,244,50,0.14) 0%, rgba(198,244,50,0) 55%)`,
        },
        header(label, labelEn),
        box(
            {
                flexDirection: 'column',
                flex: 1,
                width: '100%',
                marginTop: 44,
                marginBottom: 36,
            },
            ...body,
        ),
        footer(host, cta, ctaEn),
    );
}

function eyebrow(value, en) {
    return box(
        { flexDirection: 'column' },
        text(
            {
                fontWeight: 600,
                fontSize: 26,
                letterSpacing: 4,
                color: C.primary,
            },
            upper(value),
        ),
        text({ fontSize: 22, color: C.muted, marginTop: 6 }, en),
    );
}

function label(value, en) {
    return text(
        { fontWeight: 600, fontSize: 22, letterSpacing: 3, color: C.muted },
        `${upper(value)} · ${upper(en)}`,
    );
}

function circuitTitle(value, size) {
    return box(
        {
            ...display({
                fontSize: size,
                lineHeight: 1.04,
                letterSpacing: -2,
                color: C.text,
            }),
            width: '100%',
        },
        value,
    );
}

function announcement({ data, svg }, host) {
    const cars = Array.isArray(data.cars) ? data.cars : [];
    const shownCars = cars.slice(0, 4);
    const restCars = cars.length - shownCars.length;
    const from = shortDate(data.starts_at);
    const to = shortDate(data.ends_at);
    const circuit = data.circuit ?? 'Circuito por anunciar';
    const title = data.title ? String(data.title) : null;
    const track = trackImage(svg, 840, 430);
    const roomy = track === null;

    const datesBlock = box(
        {
            flexDirection: 'column',
            ...(roomy ? {} : { width: 360 }),
        },
        label('Fechas', 'Dates'),
        from && to
            ? text(
                  {
                      fontSize: roomy ? 46 : 32,
                      fontWeight: 600,
                      marginTop: roomy ? 16 : 10,
                      lineHeight: 1.25,
                  },
                  `${from} - ${to}`,
              )
            : null,
    );

    const carsBlock = box(
        {
            flexDirection: 'column',
            ...(roomy ? {} : { flex: 1, marginLeft: 24 }),
        },
        label(
            cars.length > 1 ? 'Coches' : 'Coche',
            cars.length > 1 ? 'Cars' : 'Car',
        ),
        box(
            { flexDirection: 'column', marginTop: roomy ? 16 : 10 },
            ...shownCars.map((car) =>
                box(
                    {
                        ...ellipsis,
                        fontFamily: 'Inter',
                        fontWeight: 600,
                        fontSize: roomy ? 40 : 30,
                        color: C.text,
                        lineHeight: 1.3,
                    },
                    String(car),
                ),
            ),
            restCars > 0
                ? text(
                      { fontSize: roomy ? 30 : 26, color: C.muted },
                      `+${restCars} más · more`,
                  )
                : null,
        ),
    );

    return page(
        'Nueva semana',
        'New week',
        host,
        'Vota y corre',
        'Vote and race',
        eyebrow('Circuito de la semana', 'Track of the week'),
        box(
            { marginTop: 14 },
            circuitTitle(
                circuit,
                roomy
                    ? circuit.length > 22
                        ? 84
                        : 104
                    : circuit.length > 22
                      ? 78
                      : 96,
            ),
        ),
        title
            ? text(
                  {
                      ...display({
                          fontSize: roomy ? 48 : 40,
                          color: C.primary,
                          fontWeight: 600,
                          marginTop: 16,
                      }),
                  },
                  title,
              )
            : null,
        roomy
            ? box(
                  {
                      flex: 1,
                      flexDirection: 'column',
                      justifyContent: 'center',
                      width: '100%',
                  },
                  box(
                      {
                          flexDirection: 'column',
                          width: '100%',
                          paddingTop: 36,
                          borderTop: `2px solid ${C.border}`,
                      },
                      datesBlock,
                  ),
                  box(
                      {
                          flexDirection: 'column',
                          width: '100%',
                          marginTop: 44,
                          paddingTop: 36,
                          borderTop: `2px solid ${C.border}`,
                      },
                      carsBlock,
                  ),
              )
            : [
                  box(
                      {
                          flex: 1,
                          alignItems: 'center',
                          justifyContent: 'center',
                          marginTop: 32,
                          backgroundColor: C.surface,
                          border: `2px solid ${C.border}`,
                          borderRadius: 36,
                          padding: 36,
                      },
                      track,
                  ),
                  box(
                      {
                          marginTop: 32,
                          width: '100%',
                          alignItems: 'flex-start',
                          justifyContent: 'space-between',
                      },
                      datesBlock,
                      carsBlock,
                  ),
              ],
    );
}

function dailyResults({ data, svg }, host) {
    const races = Array.isArray(data.races) ? data.races : [];
    const MAX = 8;
    const shown = races.slice(0, MAX);
    const rest = races.length - shown.length;
    const date = longDate(data.date);
    const dateEn = longDateEn(data.date);
    const rowHeight = Math.min(
        136,
        Math.floor(700 / Math.max(shown.length, 1)),
    );
    const track = trackImage(svg, 210, 130);

    return page(
        'Resultados',
        'Results',
        host,
        null,
        null,
        box(
            {
                alignItems: 'center',
                justifyContent: 'space-between',
                width: '100%',
            },
            box(
                { flexDirection: 'column', flex: 1 },
                eyebrow('Resultados del día', 'Daily results'),
                box(
                    { marginTop: 14 },
                    box(
                        {
                            ...display({
                                fontSize: 64,
                                lineHeight: 1.05,
                                letterSpacing: -1.5,
                                color: C.text,
                            }),
                            width: '100%',
                        },
                        date
                            ? date.charAt(0).toUpperCase() + date.slice(1)
                            : 'Resultados',
                    ),
                ),
                dateEn
                    ? text(
                          { fontSize: 28, color: C.muted, marginTop: 8 },
                          dateEn,
                      )
                    : null,
                data.circuit
                    ? text(
                          {
                              fontSize: 36,
                              fontWeight: 600,
                              color: C.primary,
                              marginTop: 14,
                          },
                          data.circuit,
                      )
                    : null,
            ),
            track,
        ),
        box(
            { flexDirection: 'column', marginTop: 36, width: '100%' },
            ...shown.map((race) =>
                box(
                    {
                        alignItems: 'center',
                        width: '100%',
                        height: rowHeight - 12,
                        marginBottom: 12,
                        padding: '0 28px',
                        backgroundColor: C.surface,
                        border: `2px solid ${C.border}`,
                        borderRadius: 22,
                    },
                    text(
                        {
                            ...mono({
                                fontSize: 34,
                                color: C.primary,
                                width: 120,
                            }),
                        },
                        race.started_at ?? '--:--',
                    ),
                    box(
                        {
                            flexDirection: 'column',
                            flex: 1,
                            minWidth: 0,
                            marginRight: 20,
                        },
                        box(
                            {
                                ...ellipsis,
                                fontFamily: 'Inter',
                                fontWeight: 600,
                                fontSize: 32,
                                color: C.text,
                            },
                            String(race.winner ?? ''),
                        ),
                        race.car
                            ? box(
                                  {
                                      ...ellipsis,
                                      fontFamily: 'Inter',
                                      fontSize: 22,
                                      color: C.muted,
                                      marginTop: 2,
                                  },
                                  String(race.car),
                              )
                            : null,
                    ),
                    box(
                        { flexDirection: 'column', alignItems: 'flex-end' },
                        text(
                            { ...mono({ fontSize: 30, color: C.text }) },
                            formatTime(race.total_ms),
                        ),
                        race.best_lap_ms
                            ? text(
                                  {
                                      ...mono({
                                          fontSize: 22,
                                          color: C.muted,
                                          marginTop: 2,
                                      }),
                                  },
                                  `VR · FL ${formatTime(race.best_lap_ms)}`,
                              )
                            : null,
                    ),
                ),
            ),
            rest > 0
                ? text(
                      { fontSize: 28, color: C.muted, marginTop: 4 },
                      `+${rest} carreras más · more at ${host}`,
                  )
                : null,
        ),
    );
}

function bestLaps({ data, svg }, host) {
    const rows = (Array.isArray(data.rows) ? data.rows : []).slice(0, 5);
    const from = shortDate(data.starts_at);
    const to = shortDate(
        data.ends_at
            ? new Date(new Date(data.ends_at).getTime() - 1000).toISOString()
            : null,
    );
    const circuit = data.circuit ?? '';
    const track = trackImage(svg, 220, 140);

    return page(
        'Mejores vueltas',
        'Best laps',
        host,
        null,
        null,
        box(
            {
                alignItems: 'center',
                justifyContent: 'space-between',
                width: '100%',
            },
            box(
                { flexDirection: 'column', flex: 1 },
                eyebrow(
                    'Mejores vueltas de la semana',
                    'Best laps of the week',
                ),
                box(
                    { marginTop: 14 },
                    circuitTitle(circuit, circuit.length > 22 ? 60 : 76),
                ),
                from && to
                    ? text(
                          { fontSize: 30, color: C.muted, marginTop: 14 },
                          `${from} - ${to}`,
                      )
                    : null,
            ),
            track,
        ),
        box(
            { flexDirection: 'column', marginTop: 40, width: '100%' },
            ...rows.map((row, index) => {
                const first = index === 0;
                const ink = first ? C.onPrimary : C.text;
                const sub = first ? '#2d3a06' : C.muted;
                return box(
                    {
                        alignItems: 'center',
                        width: '100%',
                        height: first ? 176 : 128,
                        marginBottom: 14,
                        padding: '0 32px',
                        backgroundColor: first ? C.primary : C.surface,
                        border: `2px solid ${first ? C.primary : C.border}`,
                        borderRadius: 26,
                    },
                    text(
                        {
                            ...display({
                                fontSize: first ? 64 : 44,
                                color: first ? C.onPrimary : C.primary,
                                width: first ? 90 : 76,
                            }),
                        },
                        row.position,
                    ),
                    box(
                        {
                            flexDirection: 'column',
                            flex: 1,
                            minWidth: 0,
                            marginRight: 20,
                        },
                        box(
                            {
                                ...ellipsis,
                                fontFamily: 'Inter',
                                fontWeight: 600,
                                fontSize: first ? 40 : 32,
                                color: ink,
                            },
                            String(row.driver ?? ''),
                        ),
                        row.car
                            ? box(
                                  {
                                      ...ellipsis,
                                      fontFamily: 'Inter',
                                      fontSize: first ? 26 : 22,
                                      color: sub,
                                      marginTop: 4,
                                  },
                                  String(row.car),
                              )
                            : null,
                    ),
                    text(
                        { ...mono({ fontSize: first ? 46 : 36, color: ink }) },
                        formatTime(row.lap_ms),
                    ),
                );
            }),
        ),
    );
}

const TEMPLATES = {
    week_announcement: announcement,
    daily_results: dailyResults,
    weekly_best_laps: bestLaps,
};

async function readStdin() {
    const chunks = [];
    for await (const chunk of process.stdin) {
        chunks.push(chunk);
    }
    return Buffer.concat(chunks).toString('utf8');
}

async function main() {
    const output = process.argv[2];
    if (!output) {
        throw new Error('Missing output path');
    }
    const payload = JSON.parse(await readStdin());
    const template = TEMPLATES[payload.type];
    if (!template) {
        throw new Error(`Unknown type: ${payload.type}`);
    }
    let host = 'fastgrid.club';
    try {
        host = new URL(payload.url).host || host;
    } catch {}
    const tree = template(
        { data: payload.data ?? {}, svg: payload.svg ?? null },
        host,
    );
    const markup = await satori(tree, { width: WIDTH, height: HEIGHT, fonts });
    const png = new Resvg(markup, { fitTo: { mode: 'width', value: WIDTH } })
        .render()
        .asPng();
    writeFileSync(output, png);
}

main().catch((error) => {
    process.stderr.write(`${error.stack ?? error}\n`);
    process.exit(1);
});
