export type ProposalCar = {
    id: number;
    displayName: string;
};

export type ProposalTrack = {
    id: number;
    display: string;
    maxPitSlot: number;
};

export type ProposalItem = {
    id: number;
    rank: number;
    track: { display: string; maxPitSlot: number };
    cars: ProposalCar[];
    author: string | null;
    votes: number;
    createdAt: string;
    isMine: boolean;
    canWithdraw: boolean;
};

export type ProposalWeek = {
    id: number;
    startsAt: string;
    endsAt: string;
    votingClosesAt: string;
    zone: 'CET' | 'CEST';
    status: string;
    isOpen: boolean;
};

export type ProposalViewer = {
    state: 'guest' | 'needs_identity' | 'ready';
    vote: { proposalId: number; hidden: boolean } | null;
    proposalLimitReached: boolean;
};

export type ProposalOptions = {
    tracks: ProposalTrack[];
    cars: ProposalCar[];
};

export type CatalogCar = {
    id: number;
    displayName: string;
    brand: string | null;
    pi: number | null;
    type: string | null;
    era: string | null;
    engine: string | null;
    classes: string[];
};

export type CatalogTrack = {
    id: number;
    display: string;
    track: string;
    layout: string;
    country: string | null;
    lengthM: number | null;
    maxPitSlot: number;
};

export type Range<T extends number = number> = { min: T; max: T };

export type Catalog = {
    cars: CatalogCar[];
    tracks: CatalogTrack[];
    ranges: {
        pi: Range;
        lengthM: Range;
        pitSlots: Range;
    };
    facets: {
        brands: string[];
        types: string[];
        eras: string[];
        engines: string[];
        classes: string[];
        countries: string[];
    };
};

export type CatalogViewer = {
    state: ProposalViewer['state'];
    proposalLimitReached: boolean;
};

export type ProposalResultItem = Pick<
    ProposalItem,
    'id' | 'rank' | 'track' | 'cars' | 'votes'
>;

export type LastVote = {
    week: { id: number; startsAt: string; endsAt: string };
    source: 'proposal' | 'fallback' | 'admin';
    winnerId: number | null;
    config: {
        track: { display: string; maxPitSlot: number };
        cars: ProposalCar[];
        maxPlayers: number | null;
    } | null;
    ranking: ProposalResultItem[];
    totalVotes: number;
};
