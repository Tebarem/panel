import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { KeyRound, Plus } from 'lucide-react';
import {
    Alert,
    Button,
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    PageContentBlock,
    Spinner,
    httpErrorToHuman,
} from '@pterodactyl/sdk';
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
    tone: 'danger' | 'warning';
    body: ReactNode;
}

const CONFIRM = {
    regenerate: {
        title: (name) => `New secret for ${name}`,
        label: 'Create new secret',
        tone: 'warning',
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
        tone: 'danger',
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
        tone: 'danger',
        body: <p>This deletes the app and all of its tokens. Apps that use it stop working and cannot be restored.</p>,
    },
} satisfies Record<ClientAction, ConfirmCopy>;

/** The panel's "create" button: a plus icon and a sentence-case label, as on the other admin pages. */
function NewAppButton({ onClick }: { onClick: () => void }) {
    return (
        <Button
            type='button'
            onClick={onClick}
            className='rjo:inline-flex rjo:h-9 rjo:shrink-0 rjo:items-center rjo:justify-center rjo:gap-2 rjo:px-3 rjo:font-medium rjo:tracking-normal rjo:normal-case'
        >
            <span className='rjo:inline-flex rjo:items-center rjo:gap-2'>
                <Plus aria-hidden='true' className='rjo:h-4 rjo:w-4' />
                New app
            </span>
        </Button>
    );
}

/** The empty state of an admin list, with the same structure and spacing as the panel's AdminListEmpty. */
function EmptyApps() {
    return (
        <div className='rjo:rounded-sm rjo:border rjo:border-border rjo:bg-card'>
            <Empty className='rjo:gap-4 rjo:md:p-8'>
                <EmptyHeader>
                    <EmptyMedia variant='icon'>
                        <KeyRound />
                    </EmptyMedia>
                    <EmptyTitle>No OAuth apps yet</EmptyTitle>
                    <EmptyDescription>
                        Create an app to let it sign users in with this panel. Give it the redirect URIs it uses after
                        sign-in.
                    </EmptyDescription>
                </EmptyHeader>
                <EmptyContent />
            </Empty>
        </div>
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

    return (
        <PageContentBlock title='OAuth Apps'>
            <header className='rjo:mb-6 rjo:flex rjo:flex-col rjo:gap-3 rjo:sm:flex-row rjo:sm:items-center rjo:sm:justify-between'>
                <div className='rjo:min-w-0'>
                    <h1 className='rjo:font-header rjo:text-2xl rjo:font-semibold rjo:tracking-normal rjo:text-foreground'>
                        OAuth Apps
                    </h1>
                    <p className='rjo:mt-1 rjo:text-sm rjo:leading-relaxed rjo:text-foreground/70'>
                        Apps that sign users in with this panel. Each app has its own client ID.
                    </p>
                </div>
                <NewAppButton onClick={() => setEditing('new')} />
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

            {clients && clients.length === 0 && <EmptyApps />}

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
                    key={editing === 'new' ? 'new' : editing.id}
                    client={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                    onIssued={(secret, clientName) => setIssued({ clientName, reason: 'created', secret })}
                />
            )}

            {confirming && (
                <ConfirmDialog
                    title={CONFIRM[confirming.action].title(confirming.client.name)}
                    confirmLabel={CONFIRM[confirming.action].label}
                    tone={CONFIRM[confirming.action].tone}
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
