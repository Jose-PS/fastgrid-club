import { formatNumber } from '@/lib/datetime';

export type DonationPlatform = 'ko_fi' | 'patreon' | 'paypal';

export type DonationMethod = {
    platform: DonationPlatform;
    url: string;
};

export type Donation = {
    methods: DonationMethod[];
    goal_cents: number;
    covered_cents: number;
    percent: number;
    visible: true;
};

export type DonationPeriod = {
    id: number;
    month: string;
    goal_cents: number;
    covered_cents: number;
    percent: number;
    updated_at: string | null;
};

export function formatEuros(cents: number, locale: string): string {
    const whole = cents % 100 === 0;

    return formatNumber(cents / 100, locale, {
        style: 'currency',
        currency: 'EUR',
        useGrouping: 'always',
        minimumFractionDigits: whole ? 0 : 2,
        maximumFractionDigits: 2,
    });
}

const EUROS_PATTERN = /^(\d+)(?:[.,](\d{1,2}))?$/;

export function eurosToCents(input: string): number | null {
    const match = EUROS_PATTERN.exec(input.replace(/\s/g, ''));

    if (match === null) {
        return null;
    }

    const cents =
        Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));

    return Number.isSafeInteger(cents) ? cents : null;
}

export function centsToEurosInput(cents: number): string {
    const whole = Math.floor(cents / 100);
    const rest = cents % 100;

    return rest === 0
        ? String(whole)
        : `${whole},${String(rest).padStart(2, '0')}`;
}

export function clampPercent(percent: number): number {
    return Math.min(100, Math.max(0, percent));
}
