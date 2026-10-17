import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, httpErrorToHuman, PanelLink, Spinner, toast } from '@pterodactyl/sdk';
import { createProxy, fetchProxyStatus, isDashboardServersQuery, proxyStatusQueryKey } from './api';

const DESCRIPTION = 'A lightweight Ramjet Proxy (Velocity-compatible) for your servers';

const CARD = 'rjproxy:flex rjproxy:flex-col rjproxy:gap-3 rjproxy:rounded-sm rjproxy:border rjproxy:bg-card rjproxy:p-4 rjproxy:sm:flex-row rjproxy:sm:items-center rjproxy:sm:justify-between';

/**
 * The dashboard card for the customer's one Ramjet Proxy. It sits above the server list. The feature being off hides
 * it entirely; otherwise it offers the create action, or links to the proxy the customer already has.
 */
export default function ProxyCard() {
    const queryClient = useQueryClient();
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);

    const status = useQuery({ queryKey: proxyStatusQueryKey, queryFn: fetchProxyStatus });

    const create = useMutation({
        mutationFn: createProxy,
        onMutate: () => setFailure(null),
        onSuccess: async (server) => {
            setConfirmOpen(false);
            toast.success(`${server.name} is being set up.`);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: proxyStatusQueryKey }),
                queryClient.invalidateQueries({ predicate: isDashboardServersQuery }),
            ]);
        },
        onError: (error) => {
            setConfirmOpen(false);
            setFailure(httpErrorToHuman(error));
            void queryClient.invalidateQueries({ queryKey: proxyStatusQueryKey });
        },
    });

    if (status.isPending) {
        return (
            <section className={CARD} aria-busy='true' aria-label='Loading your Ramjet Proxy'>
                <div className='rjproxy:h-10 rjproxy:w-full rjproxy:animate-pulse rjproxy:rounded-sm rjproxy:bg-muted' />
            </section>
        );
    }

    if (status.isError) {
        return (
            <section className={CARD}>
                <Alert type='danger'>{httpErrorToHuman(status.error)}</Alert>
                <Button size='small' isSecondary onClick={() => void status.refetch()}>
                    Try again
                </Button>
            </section>
        );
    }

    const { enabled, server, can_create: canCreate, reason } = status.data;
    if (!enabled) {
        return null;
    }

    const creating = create.isPending;

    return (
        <>
            <section className={CARD} aria-labelledby='rjproxy-card-title'>
                <div className='rjproxy:grid rjproxy:gap-1'>
                    <h3 id='rjproxy-card-title' className='rjproxy:text-sm rjproxy:font-medium rjproxy:text-foreground'>
                        Ramjet Proxy
                    </h3>
                    {server ? (
                        <p className='rjproxy:text-sm rjproxy:text-muted-foreground'>
                            Your proxy:{' '}
                            <PanelLink
                                destination={{ to: '/server/$id', params: { id: server.identifier } }}
                                className='rjproxy:font-medium rjproxy:text-foreground rjproxy:underline'
                            >
                                {server.name}
                            </PanelLink>
                        </p>
                    ) : (
                        <>
                            <p className='rjproxy:text-sm rjproxy:text-muted-foreground'>{DESCRIPTION}</p>
                            {!canCreate && reason && (
                                <p className='rjproxy:text-sm rjproxy:text-muted-foreground'>{reason}</p>
                            )}
                        </>
                    )}
                    {creating && (
                        <p className='rjproxy:flex rjproxy:items-center rjproxy:gap-2 rjproxy:text-sm rjproxy:text-muted-foreground'>
                            <Spinner size='small' />
                            Creating your proxy…
                        </p>
                    )}
                    {failure && <Alert type='danger'>{failure}</Alert>}
                </div>

                {!server && (
                    <Button
                        type='button'
                        disabled={!canCreate || creating}
                        isLoading={creating}
                        onClick={() => setConfirmOpen(true)}
                    >
                        Create proxy
                    </Button>
                )}
            </section>

            {confirmOpen && (
                <Dialog.Confirm
                    open
                    title='Create your Ramjet Proxy?'
                    confirm='Create proxy'
                    pending={creating}
                    onClose={() => setConfirmOpen(false)}
                    onConfirmed={() => create.mutate()}
                >
                    <Dialog.Icon type='info' />
                    <p className='rjproxy:text-sm rjproxy:leading-relaxed rjproxy:text-muted-foreground'>
                        This creates one Ramjet Proxy server on your account with the panel&apos;s standard settings. You can
                        have one Ramjet Proxy, and it starts automatically once it is installed.
                    </p>
                </Dialog.Confirm>
            )}
        </>
    );
}
