import { usePage } from '@inertiajs/react';
import * as spanish from '@/routes';
import * as english from '@/routes/en';
import * as spanishProposals from '@/routes/proposals';
import * as englishProposals from '@/routes/en/proposals';

type PublicRoutes = {
    home: typeof spanish.home;
    calendar: typeof spanish.calendar;
    proposals: typeof spanish.proposals;
    proposalsCreate: typeof spanishProposals.create;
    donate: typeof spanish.donate;
};

const ROUTES: Record<string, PublicRoutes> = {
    es: {
        home: spanish.home,
        calendar: spanish.calendar,
        proposals: spanish.proposals,
        proposalsCreate: spanishProposals.create,
        donate: spanish.donate,
    },
    en: {
        home: english.home,
        calendar: english.calendar,
        proposals: english.proposals,
        proposalsCreate: englishProposals.create,
        donate: english.donate,
    },
};

export function usePublicRoutes(): PublicRoutes {
    const { locale } = usePage<{ locale: string }>().props;

    return ROUTES[locale] ?? ROUTES.es;
}
