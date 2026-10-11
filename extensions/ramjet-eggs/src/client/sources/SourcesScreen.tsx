import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { DownloadCloud, Plus, RefreshCw, TriangleAlert } from 'lucide-react';
import { Alert, Button, Dialog, PageContentBlock, Spinner, Table, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import {
    SOURCE_TYPES,
    deleteSource,
    fetchSources,
    sourcesQueryKey,
    syncSource,
    type EggSourceRecord,
    type SyncFileStatus,
} from './api';
import { SourceDialog } from './SourceDialog';

/** How often the list is read again while a sync is queued or running. */
const POLL_INTERVAL_MS = 2000;

const STATUS_LABELS: Record<SyncFileStatus, string> = {
    created: 'Created',
    updated: 'Updated',
    unchanged: 'Unchanged',
    skipped: 'Skipped',
    failed: 'Failed',
};

const typeLabel = (type: EggSourceRecord['type']): string => SOURCE_TYPES.find((option) => option.value === type)?.label ?? type;

const location = (source: EggSourceRecord): string => {
    if (source.type === 'url') return source.path ?? '';

    const repository = source.repository ?? '';
    const directory = source.path ? `/${source.path}` : '';

    return `${repository}@${source.branch ?? ''}${directory}`;
};

const lastSync = (source: EggSourceRecord): string => {
    if (!source.last_synced_at) return 'Never';

    return new Date(source.last_synced_at).toLocaleString();
};

export default function SourcesScreen() {
    const queryClient = useQueryClient();
    const { data: sources, error, isFetching, refetch } = useQuery({
        queryKey: sourcesQueryKey,
        queryFn: fetchSources,
        // Poll only while a sync is queued or running; the sync runs in the background.
        refetchInterval: (query) => (query.state.data?.some((source) => source.syncing) ? POLL_INTERVAL_MS : false),
    });

    // `editing` is 'new' for a new source, the source being edited, or null when the dialog is closed.
    const [editing, setEditing] = useState<EggSourceRecord | 'new' | null>(null);
    const [deleting, setDeleting] = useState<EggSourceRecord | null>(null);
    // The source whose sync was started here, and its last sync time before that, to detect when it has finished.
    const [watching, setWatching] = useState<{ id: number; lastSyncedAt: string | null } | null>(null);
    const [results, setResults] = useState<EggSourceRecord | null>(null);

    const sync = useMutation({
        mutationFn: (source: EggSourceRecord) => syncSource(source.id),
        onMutate: (source) => setWatching({ id: source.id, lastSyncedAt: source.last_synced_at }),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: sourcesQueryKey });
            toast.success('Sync started. The results appear here when it finishes.');
        },
        onError: (cause) => {
            setWatching(null);
            toast.error(httpErrorToHuman(cause));
        },
    });

    // When the watched source stops syncing, show its results (or report that the sync did not run).
    useEffect(() => {
        if (watching === null || sources === undefined) return;

        const row = sources.find((source) => source.id === watching.id);
        if (row === undefined) {
            setWatching(null);
        } else if (!row.syncing) {
            setWatching(null);
            if (row.last_synced_at !== watching.lastSyncedAt) {
                setResults(row);
            } else {
                toast.error(row.last_sync_error ?? 'The sync did not run.');
            }
        }
    }, [sources, watching]);

    const remove = useMutation({
        mutationFn: (id: number) => deleteSource(id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: sourcesQueryKey });
            toast.success('Egg source deleted.');
            setDeleting(null);
        },
        onError: (cause) => toast.error(httpErrorToHuman(cause)),
    });

    const newButton = (
        <Button type={'button'} onClick={() => setEditing('new')}>
            <span className={'rjeggs:inline-flex rjeggs:items-center rjeggs:gap-2'}>
                <Plus aria-hidden={'true'} className={'rjeggs:size-4'} />
                New source
            </span>
        </Button>
    );

    return (
        <PageContentBlock title={'Egg Sources'}>
            <header className={'rjeggs:mb-6 rjeggs:flex rjeggs:flex-col rjeggs:gap-3 rjeggs:sm:flex-row rjeggs:sm:items-center rjeggs:sm:justify-between'}>
                <div className={'rjeggs:min-w-0'}>
                    <h1 className={'rjeggs:font-header rjeggs:text-2xl rjeggs:font-semibold rjeggs:text-foreground'}>Egg Sources</h1>
                    <p className={'rjeggs:mt-1 rjeggs:text-sm rjeggs:leading-relaxed rjeggs:text-foreground/70'}>
                        Import eggs from JSON files in a GitHub or GitLab repository, or from one URL. Sources are synced every hour.
                    </p>
                </div>
                {sources && sources.length > 0 && <div className={'rjeggs:shrink-0'}>{newButton}</div>}
            </header>

            {error && (
                <Alert type={'danger'} className={'rjeggs:mb-4'}>
                    <div className={'rjeggs:flex rjeggs:flex-wrap rjeggs:items-center rjeggs:gap-x-4 rjeggs:gap-y-2'}>
                        <span>{httpErrorToHuman(error)}</span>
                        <Button.Text type={'button'} size={'xsmall'} onClick={() => void refetch()}>
                            Try again
                        </Button.Text>
                    </div>
                </Alert>
            )}

            {!sources ? (
                !error && <Spinner size={'large'} centered />
            ) : sources.length === 0 ? (
                <section className={'rjeggs:rounded-sm rjeggs:border rjeggs:border-dashed rjeggs:border-border rjeggs:bg-card rjeggs:px-6 rjeggs:py-12 rjeggs:text-center'}>
                    <DownloadCloud aria-hidden={'true'} className={'rjeggs:mx-auto rjeggs:size-8 rjeggs:text-muted-foreground'} />
                    <h2 className={'rjeggs:mt-4 rjeggs:font-header rjeggs:text-lg rjeggs:font-semibold rjeggs:text-foreground'}>No sources yet</h2>
                    <p className={'rjeggs:mx-auto rjeggs:mt-2 rjeggs:max-w-xl rjeggs:text-sm rjeggs:leading-relaxed rjeggs:text-muted-foreground'}>
                        Add a repository or a single egg file. Its eggs are imported on the next sync.
                    </p>
                    <div className={'rjeggs:mt-6'}>{newButton}</div>
                </section>
            ) : (
                <Table
                    rows={sources}
                    isFetching={isFetching}
                    keyOf={(row) => String(row.id)}
                    emptyState={'No sources yet.'}
                    columns={[
                        {
                            id: 'name',
                            label: 'Name',
                            cell: (row) => (
                                <div className={'rjeggs:min-w-40'}>
                                    <p className={'rjeggs:font-medium rjeggs:text-foreground'}>{row.name}</p>
                                    <p className={'rjeggs:text-xs rjeggs:text-muted-foreground'}>
                                        {typeLabel(row.type)}
                                        {row.has_token ? ' · token stored' : ''}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            id: 'location',
                            label: 'Location',
                            cell: (row) => <code className={'rjeggs:break-all rjeggs:font-mono rjeggs:text-xs rjeggs:text-foreground'}>{location(row)}</code>,
                        },
                        {
                            id: 'eggs',
                            label: 'Eggs',
                            cell: (row) => <span className={'rjeggs:text-sm rjeggs:text-foreground'}>{row.eggs_count}</span>,
                        },
                        {
                            id: 'last_sync',
                            label: 'Last sync',
                            cell: (row) => (
                                <div className={'rjeggs:flex rjeggs:flex-col rjeggs:gap-1'}>
                                    <span className={'rjeggs:text-sm rjeggs:text-foreground'}>{row.syncing ? 'Syncing…' : lastSync(row)}</span>
                                    {row.last_sync_error && (
                                        <span className={'rjeggs:inline-flex rjeggs:max-w-xs rjeggs:items-start rjeggs:gap-1 rjeggs:text-xs rjeggs:text-warning'}>
                                            <TriangleAlert aria-hidden={'true'} className={'rjeggs:mt-0.5 rjeggs:size-3 rjeggs:shrink-0'} />
                                            <span className={'rjeggs:break-words'}>{row.last_sync_error}</span>
                                        </span>
                                    )}
                                </div>
                            ),
                        },
                        {
                            id: 'actions',
                            label: <span className={'rjeggs:sr-only'}>Actions</span>,
                            cell: (row) => (
                                <div className={'rjeggs:flex rjeggs:justify-end rjeggs:gap-2'}>
                                    <Button.Text
                                        type={'button'}
                                        size={'xsmall'}
                                        aria-label={`Sync ${row.name} now`}
                                        disabled={row.syncing || sync.isPending}
                                        onClick={() => sync.mutate(row)}
                                    >
                                        <span className={'rjeggs:inline-flex rjeggs:items-center rjeggs:gap-1'}>
                                            <RefreshCw aria-hidden={'true'} className={`rjeggs:size-3 ${row.syncing ? 'rjeggs:animate-spin' : ''}`} />
                                            Sync now
                                        </span>
                                    </Button.Text>
                                    <Button.Text type={'button'} size={'xsmall'} aria-label={`Edit ${row.name}`} onClick={() => setEditing(row)}>
                                        Edit
                                    </Button.Text>
                                    <Button.Danger
                                        type={'button'}
                                        size={'xsmall'}
                                        isSecondary
                                        aria-label={`Delete ${row.name}`}
                                        onClick={() => setDeleting(row)}
                                    >
                                        Delete
                                    </Button.Danger>
                                </div>
                            ),
                        },
                    ]}
                />
            )}

            {editing !== null && (
                <SourceDialog key={editing === 'new' ? 'new' : editing.id} source={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />
            )}

            <Dialog
                open={results !== null}
                onClose={() => setResults(null)}
                title={results ? `Sync results: ${results.name}` : 'Sync results'}
                description={'Each JSON file in the location is listed with what happened to it.'}
            >
                {results && (
                    <div className={'rjeggs:grid rjeggs:gap-4'}>
                        {results.last_sync_error ? (
                            <Alert type={'danger'}>{results.last_sync_error}</Alert>
                        ) : (
                            <Alert type={'success'}>All files synced.</Alert>
                        )}

                        {results.last_sync_results.length === 0 ? (
                            <p className={'rjeggs:text-sm rjeggs:text-muted-foreground'}>No files were processed.</p>
                        ) : (
                            <div className={'rjeggs:max-h-96 rjeggs:overflow-auto'}>
                                <table className={'rjeggs:w-full rjeggs:text-left rjeggs:text-sm'}>
                                    <thead>
                                        <tr className={'rjeggs:text-xs rjeggs:text-muted-foreground'}>
                                            <th className={'rjeggs:py-2 rjeggs:pr-3 rjeggs:font-medium'}>File</th>
                                            <th className={'rjeggs:py-2 rjeggs:pr-3 rjeggs:font-medium'}>Result</th>
                                            <th className={'rjeggs:py-2 rjeggs:font-medium'}>Egg or message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {results.last_sync_results.map((file) => (
                                            <tr key={file.path} className={'rjeggs:border-t rjeggs:border-border'}>
                                                <td className={'rjeggs:break-all rjeggs:py-2 rjeggs:pr-3 rjeggs:font-mono rjeggs:text-xs rjeggs:text-foreground'}>
                                                    {file.path}
                                                </td>
                                                <td className={'rjeggs:py-2 rjeggs:pr-3 rjeggs:text-foreground'}>{STATUS_LABELS[file.status]}</td>
                                                <td className={'rjeggs:py-2 rjeggs:text-muted-foreground'}>
                                                    {file.status === 'failed' ? file.message : (file.egg ?? file.message ?? '')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
                <Dialog.Footer>
                    <Button.Text type={'button'} onClick={() => setResults(null)}>
                        Close
                    </Button.Text>
                </Dialog.Footer>
            </Dialog>

            <Dialog.Confirm
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                title={'Delete egg source'}
                confirm={'Delete source'}
                pending={remove.isPending}
                onConfirmed={() => deleting && remove.mutate(deleting.id)}
            >
                {deleting && (
                    <p className={'rjeggs:text-sm rjeggs:leading-relaxed rjeggs:text-muted-foreground'}>
                        Delete <strong className={'rjeggs:text-foreground'}>{deleting.name}</strong>? Its stored token is removed.{' '}
                        {deleting.eggs_count > 0
                            ? `The ${deleting.eggs_count} egg(s) imported from it are kept, but they will no longer be synchronized.`
                            : 'No egg was imported from it.'}
                    </p>
                )}
            </Dialog.Confirm>
        </PageContentBlock>
    );
}
