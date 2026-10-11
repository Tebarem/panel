import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { KeyRound, Plus } from 'lucide-react';
import { Alert, Button, PageContentBlock, Spinner, httpErrorToHuman } from '@pterodactyl/sdk';
import { fetchClients, type OAuthClient } from '../api';
import { CLIENTS_QUERY_KEY } from '../helpers';
import ClientDialog from './ClientDialog';
import ClientsTable from './ClientsTable';
import ConfirmDialog from './ConfirmDialog';
import OidcEndpoints from './OidcEndpoints';
import SecretDialog, { type IssuedSecret } from './SecretDialog';
import { useClientActions, type ClientAction } from './useClientActions';

interface Confirming {
    action: ClientAction;
    client: OAuthClient;
}

interface ConfirmCopy {
    title: (name: string) => string;
    label: string;
    danger: boolean;
    body: ReactNode;
}

const CONFIRM = {
    regenerate: {
        title: (name) => `New secret for ${name}`,
        label: 'Create new secret',
        danger: false,
        body: (
            <p>
                The current secret stops working right away, and every token issued to this app is revoked. The new
                secret is shown once, in the next dialog, so copy it there.
            </p>
        ),
    },
    revoke: {
        title: (name) => `Revoke tokens of ${name}`,
        label: 'Revoke tokens',
        danger: true,
        body: (
            <p>
                Every access and refresh token issued to this app is revoked. Anyone signed in through it has to sign in
                again.
            </p>
        ),
    },
    delete: {
        title: (name) => `Delete ${name}`,
        label: 'Delete app',
        danger: true,
        body: <p>This deletes the app and all of its tokens. Apps that use it stop working and cannot be restored.</p>,
    },
} satisfies Record<ClientAction, ConfirmCopy>;

function EmptyState({ onCreate }: { onCreate: ReactNode }) {
    return (
        <section className='rjo:rounded-sm rjo:border rjo:border-dashed rjo:border-border rjo:bg-card rjo:px-6 rjo:py-12 rjo:text-center rjo:sm:px-10'>
            <KeyRound aria-hidden='true' className='rjo:mx-auto rjo:size-8 rjo:text-muted-foreground' />
            <h2 className='rjo:mt-4 rjo:font-header rjo:text-lg rjo:font-semibold rjo:text-foreground'>
                No OAuth apps yet
            </h2>
            <p className='rjo:mx-auto rjo:mt-2 rjo:max-w-xl rjo:text-sm rjo:leading-relaxed rjo:text-muted-foreground'>
                Create an app to let it sign users in with this panel. Give it the redirect URIs it uses after sign-in.
            </p>
            <div className='rjo:mt-6'>{onCreate}</div>
        </section>
    );
}

export default function OAuthClientsScreen() {
    const {
        data: clients,
        isFetching,
        error,
        refetch,
    } = useQuery({
        queryKey: CLIENTS_QUERY_KEY,
        queryFn: fetchClients,
    });
    // 'new' creates a client; a client edits it. Neither dialog holds a secret.
    const [editing, setEditing] = useState<OAuthClient | 'new' | null>(null);
    const [confirming, setConfirming] = useState<Confirming | null>(null);
    // The one copy of a freshly issued secret. Closing its dialog drops it.
    const [issued, setIssued] = useState<IssuedSecret | null>(null);
    const actions = useClientActions({ onIssued: setIssued, onDone: () => setConfirming(null) });

    const createButton = (
        <Button type='button' onClick={() => setEditing('new')}>
            <span className='rjo:inline-flex rjo:items-center rjo:gap-2'>
                <Plus aria-hidden='true' className='rjo:size-4' />
                New OAuth app
            </span>
        </Button>
    );

    return (
        <PageContentBlock title='OAuth Apps'>
            <header className='rjo:mb-6 rjo:flex rjo:flex-col rjo:gap-3 rjo:sm:flex-row rjo:sm:items-center rjo:sm:justify-between'>
                <div className='rjo:min-w-0'>
                    <h1 className='rjo:font-header rjo:text-2xl rjo:font-semibold rjo:text-foreground'>OAuth Apps</h1>
                    <p className='rjo:mt-1 rjo:text-sm rjo:leading-relaxed rjo:text-foreground/70'>
                        Apps that sign users in with this panel. Each one has its own client ID.
                    </p>
                </div>
                {clients && clients.length > 0 && <div className='rjo:shrink-0'>{createButton}</div>}
            </header>

            <OidcEndpoints />

            {error && (
                <Alert type='danger' className='rjo:mb-4'>
                    <div className='rjo:flex rjo:flex-wrap rjo:items-center rjo:gap-x-4 rjo:gap-y-2'>
                        <span>{httpErrorToHuman(error)}</span>
                        <Button.Text type='button' size='xsmall' onClick={() => void refetch()}>
                            Try again
                        </Button.Text>
                    </div>
                </Alert>
            )}

            {!clients && !error && <Spinner size='large' centered />}

            {clients && clients.length === 0 && <EmptyState onCreate={createButton} />}

            {clients && clients.length > 0 && (
                <ClientsTable
                    clients={clients}
                    isFetching={isFetching}
                    onEdit={(client) => setEditing(client)}
                    onAction={(action, client) => setConfirming({ action, client })}
                />
            )}

            {editing !== null && (
                <ClientDialog
                    key={editing === 'new' ? 'new' : String(editing.id)}
                    client={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                    onIssued={(secret, clientName) => setIssued({ clientName, reason: 'created', secret })}
                />
            )}

            {confirming && (
                <ConfirmDialog
                    title={CONFIRM[confirming.action].title(confirming.client.name)}
                    confirmLabel={CONFIRM[confirming.action].label}
                    danger={CONFIRM[confirming.action].danger}
                    pending={actions.pending}
                    onClose={() => setConfirming(null)}
                    onConfirm={() => actions.run(confirming.action, confirming.client)}
                >
                    {CONFIRM[confirming.action].body}
                </ConfirmDialog>
            )}

            {issued && <SecretDialog issued={issued} onClose={() => setIssued(null)} />}
        </PageContentBlock>
    );
}
