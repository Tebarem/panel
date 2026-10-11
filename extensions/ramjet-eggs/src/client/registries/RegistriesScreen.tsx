import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Container, Plus, TriangleAlert } from 'lucide-react';
import { Alert, Button, Dialog, PageContentBlock, Spinner, Table, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { deleteRegistry, fetchRegistries, registriesQueryKey, type DockerRegistry } from './api';
import { RegistryDialog } from './RegistryDialog';

const eggsLabel = (count: number): string => (count === 1 ? '1 egg' : `${count} eggs`);

export default function RegistriesScreen() {
    const queryClient = useQueryClient();
    const { data: registries, error, isFetching, refetch } = useQuery({
        queryKey: registriesQueryKey,
        queryFn: fetchRegistries,
    });

    // `editing` is 'new' for a new registry, the registry being edited, or null when the dialog is closed.
    const [editing, setEditing] = useState<DockerRegistry | 'new' | null>(null);
    const [deleting, setDeleting] = useState<DockerRegistry | null>(null);

    const remove = useMutation({
        mutationFn: (id: number) => deleteRegistry(id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: registriesQueryKey });
            toast.success('Registry deleted.');
            setDeleting(null);
        },
        onError: (cause) => toast.error(httpErrorToHuman(cause)),
    });

    const newButton = (
        <Button type={'button'} onClick={() => setEditing('new')}>
            <span className={'rjeggs:inline-flex rjeggs:items-center rjeggs:gap-2'}>
                <Plus aria-hidden={'true'} className={'rjeggs:size-4'} />
                New registry
            </span>
        </Button>
    );

    return (
        <PageContentBlock title={'Registries'}>
            <header className={'rjeggs:mb-6 rjeggs:flex rjeggs:flex-col rjeggs:gap-3 rjeggs:sm:flex-row rjeggs:sm:items-center rjeggs:sm:justify-between'}>
                <div className={'rjeggs:min-w-0'}>
                    <h1 className={'rjeggs:font-header rjeggs:text-2xl rjeggs:font-semibold rjeggs:text-foreground'}>
                        Registries
                    </h1>
                    <p className={'rjeggs:mt-1 rjeggs:text-sm rjeggs:leading-relaxed rjeggs:text-foreground/70'}>
                        Private image registries. Assign one to an egg on its configuration page.
                    </p>
                </div>
                {registries && registries.length > 0 && <div className={'rjeggs:shrink-0'}>{newButton}</div>}
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

            {!registries ? (
                !error && <Spinner size={'large'} centered />
            ) : registries.length === 0 ? (
                <section className={'rjeggs:rounded-sm rjeggs:border rjeggs:border-dashed rjeggs:border-border rjeggs:bg-card rjeggs:px-6 rjeggs:py-12 rjeggs:text-center'}>
                    <Container aria-hidden={'true'} className={'rjeggs:mx-auto rjeggs:size-8 rjeggs:text-muted-foreground'} />
                    <h2 className={'rjeggs:mt-4 rjeggs:font-header rjeggs:text-lg rjeggs:font-semibold rjeggs:text-foreground'}>
                        No registries yet
                    </h2>
                    <p className={'rjeggs:mx-auto rjeggs:mt-2 rjeggs:max-w-xl rjeggs:text-sm rjeggs:leading-relaxed rjeggs:text-muted-foreground'}>
                        Add the credentials of a private registry, then select it on the eggs whose images it hosts.
                    </p>
                    <div className={'rjeggs:mt-6'}>{newButton}</div>
                </section>
            ) : (
                <Table
                    rows={registries}
                    isFetching={isFetching}
                    keyOf={(row) => String(row.id)}
                    emptyState={'No registries yet.'}
                    columns={[
                        {
                            id: 'name',
                            label: 'Name',
                            cell: (row) => (
                                <div className={'rjeggs:min-w-40'}>
                                    <p className={'rjeggs:font-medium rjeggs:text-foreground'}>{row.name}</p>
                                    <p className={'rjeggs:text-xs rjeggs:text-muted-foreground'}>{row.username}</p>
                                </div>
                            ),
                        },
                        {
                            id: 'registry',
                            label: 'Registry',
                            cell: (row) => (
                                <div className={'rjeggs:flex rjeggs:flex-col rjeggs:gap-1'}>
                                    <code className={'rjeggs:font-mono rjeggs:text-sm rjeggs:text-foreground'}>{row.registry}</code>
                                    {row.warnings.length > 0 && (
                                        <span
                                            title={row.warnings.join(' ')}
                                            className={'rjeggs:inline-flex rjeggs:w-fit rjeggs:items-center rjeggs:gap-1 rjeggs:text-xs rjeggs:text-warning'}
                                        >
                                            <TriangleAlert aria-hidden={'true'} className={'rjeggs:size-3'} />
                                            Matches every image on this host
                                        </span>
                                    )}
                                </div>
                            ),
                        },
                        {
                            id: 'eggs',
                            label: 'Eggs',
                            cell: (row) => <span className={'rjeggs:text-sm rjeggs:text-foreground'}>{eggsLabel(row.egg_count)}</span>,
                        },
                        {
                            id: 'actions',
                            label: <span className={'rjeggs:sr-only'}>Actions</span>,
                            cell: (row) => (
                                <div className={'rjeggs:flex rjeggs:justify-end rjeggs:gap-2'}>
                                    <Button.Text
                                        type={'button'}
                                        size={'xsmall'}
                                        aria-label={`Edit ${row.name}`}
                                        onClick={() => setEditing(row)}
                                    >
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
                <RegistryDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    registry={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}

            <Dialog.Confirm
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                title={'Delete registry'}
                confirm={'Delete registry'}
                pending={remove.isPending}
                onConfirmed={() => deleting && remove.mutate(deleting.id)}
            >
                {deleting && (
                    <p className={'rjeggs:text-sm rjeggs:leading-relaxed rjeggs:text-muted-foreground'}>
                        Delete <strong className={'rjeggs:text-foreground'}>{deleting.name}</strong>? It has its stored
                        password removed.{' '}
                        {deleting.egg_count > 0
                            ? `The ${eggsLabel(deleting.egg_count)} using it will pull images without credentials, and their servers are re-synced.`
                            : 'No egg uses it.'}
                    </p>
                )}
            </Dialog.Confirm>
        </PageContentBlock>
    );
}
