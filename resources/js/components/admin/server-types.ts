export type ServerLive = {
    status: 'online' | 'offline' | 'unavailable';
    players: number | null;
    fetched_at: string | null;
};

export type LastApply = {
    status: 'ok' | 'failed' | 'pending';
    block_key: string | null;
    attempt: number;
    started_at: string;
    finished_at: string | null;
};

export type SyncCounts = {
    created: number;
    updated: number;
    deactivated: number;
};

export type LastCatalogSync = {
    finished_at: string;
    cars: SyncCounts;
    tracks: SyncCounts;
    unpaired: number;
};

export type ServerSummary = {
    live: ServerLive;
    block: string;
    block_key: string;
    planning_paused: boolean;
    pending_config: boolean;
    last_apply: LastApply | null;
    last_catalog_sync: LastCatalogSync | null;
};
