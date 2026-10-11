import { http } from '@pterodactyl/sdk';

export type EggSourceType = 'github' | 'gitlab' | 'url';

export const SOURCE_TYPES: { value: EggSourceType; label: string }[] = [
    { value: 'github', label: 'GitHub repository' },
    { value: 'gitlab', label: 'GitLab project' },
    { value: 'url', label: 'Single URL' },
];

export interface EggSourceRecord {
    id: number;
    name: string;
    type: EggSourceType;
    base_url: string | null;
    repository: string | null;
    branch: string | null;
    path: string | null;
    tag: string | null;
    docker_registry_id: number | null;
    /** Whether a token is stored. The token itself is never sent to the browser. */
    has_token: boolean;
    eggs_count: number;
    /** True while a sync is queued or running. The screen polls until it is false. */
    syncing: boolean;
    last_synced_at: string | null;
    last_sync_error: string | null;
    /** Per-file results of the last completed sync. */
    last_sync_results: SyncFileResult[];
    created_at: string | null;
    updated_at: string | null;
}

export interface EggSourceInput {
    name: string;
    type: EggSourceType;
    base_url: string | null;
    repository: string | null;
    branch: string | null;
    path: string | null;
    /** Null or empty keeps the stored token. */
    token: string | null;
    clear_token: boolean;
    tag: string | null;
    docker_registry_id: number | null;
}

export type SyncFileStatus = 'created' | 'updated' | 'unchanged' | 'skipped' | 'failed';

export interface SyncFileResult {
    path: string;
    status: SyncFileStatus;
    egg: string | null;
    message: string | null;
}

export interface SyncQueued {
    queued: true;
    source: EggSourceRecord;
}

const BASE = '/api/admin/extensions/ramjet-eggs';

/** The sources list and the sync state share this key, so both refresh together. */
export const sourcesQueryKey = ['ext-ramjet-eggs', 'sources'] as const;

export const fetchSources = async (): Promise<EggSourceRecord[]> =>
    (await http.get<{ data: EggSourceRecord[] }>(`${BASE}/sources`)).data.data;

export const createSource = async (input: EggSourceInput): Promise<EggSourceRecord> =>
    (await http.post<{ data: EggSourceRecord }>(`${BASE}/sources`, input)).data.data;

export const updateSource = async (id: number, input: EggSourceInput): Promise<EggSourceRecord> =>
    (await http.patch<{ data: EggSourceRecord }>(`${BASE}/sources/${id}`, input)).data.data;

export const deleteSource = async (id: number): Promise<void> => {
    await http.delete(`${BASE}/sources/${id}`);
};

/** Queues a sync; the outcome is read back from the source once it has finished. */
export const syncSource = async (id: number): Promise<SyncQueued> =>
    (await http.post<{ data: SyncQueued }>(`${BASE}/sources/${id}/sync`)).data.data;
