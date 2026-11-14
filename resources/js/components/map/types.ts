export type MapStatus = 'held' | 'waitlist' | 'open';

export type MapPoint = {
    key: string;
    name: string;
    state: string;
    lat: number;
    lon: number;
    status: MapStatus;
    url: string;
    claim?: string;
    waitlist?: number;
    team?: { name: string; slug: string };
    group?: string;
    groupSlug?: string;
};

export type MapData = {
    title: string;
    points: MapPoint[];
    counts: Record<MapStatus, number>;
    updated: string;
};

export const STATUS_LABEL: Record<MapStatus, string> = { held: 'Filled', waitlist: 'Open, with a waitlist', open: 'Open' };
export const STATUS_COLOR: Record<MapStatus, string> = { held: '#2451F5', waitlist: '#FF7A1A', open: '#94A3B8' };
