import type { ReactNode } from 'react';
import { ShieldAlert } from 'lucide-react';
import { Button, CopyOnClick, Table } from '@pterodactyl/sdk';
import type { OAuthClient } from '../api';
import type { ClientAction } from './useClientActions';

const MAX_LISTED_URIS = 2;

const formatDate = (value: string): string => {
    const time = new Date(value);

    return Number.isNaN(time.getTime())
        ? value
        : time.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
};

type Tone = 'neutral' | 'warning' | 'danger';

const TONE_CLASSES = {
    neutral: 'rjo:border-border rjo:bg-muted rjo:text-foreground',
    warning: 'rjo:border-warning/25 rjo:bg-warning/10 rjo:text-warning',
    danger: 'rjo:border-destructive/25 rjo:bg-destructive/10 rjo:text-destructive',
} satisfies Record<Tone, string>;

function Badge({ tone, children }: { tone: Tone; children: ReactNode }) {
    return (
        <span
            className={`rjo:inline-flex rjo:items-center rjo:gap-1 rjo:whitespace-nowrap rjo:rounded-sm rjo:border rjo:px-2 rjo:py-0.5 rjo:text-xs rjo:font-medium ${TONE_CLASSES[tone]}`}
        >
            {children}
        </span>
    );
}

const Plain = ({ children }: { children: ReactNode }) => (
    <span className='rjo:text-sm rjo:text-muted-foreground'>{children}</span>
);

function RedirectUris({ uris }: { uris: string[] }) {
    if (uris.length === 0) {
        return <Plain>None</Plain>;
    }

    const shown = uris.slice(0, MAX_LISTED_URIS);
    const hidden = uris.length - shown.length;

    return (
        <div className='rjo:grid rjo:min-w-48 rjo:max-w-sm rjo:gap-1'>
            {shown.map((uri) => (
                <code key={uri} className='rjo:block rjo:truncate rjo:font-mono rjo:text-xs' title={uri}>
                    {uri}
                </code>
            ))}
            {hidden > 0 && <span className='rjo:text-xs rjo:text-muted-foreground'>+{hidden} more</span>}
        </div>
    );
}

function RowActions({
    client,
    onAction,
}: {
    client: OAuthClient;
    onAction: (action: ClientAction, client: OAuthClient) => void;
}) {
    return (
        <div className='rjo:flex rjo:flex-wrap rjo:justify-end rjo:gap-2'>
            {client.confidential && (
                <Button.Text
                    type='button'
                    size='xsmall'
                    aria-label={`Create a new secret for ${client.name}`}
                    onClick={() => onAction('regenerate', client)}
                >
                    New secret
                </Button.Text>
            )}
            <Button.Text
                type='button'
                size='xsmall'
                aria-label={`Revoke the tokens of ${client.name}`}
                onClick={() => onAction('revoke', client)}
            >
                Revoke tokens
            </Button.Text>
            <Button.Danger
                type='button'
                size='xsmall'
                isSecondary
                aria-label={`Delete ${client.name}`}
                onClick={() => onAction('delete', client)}
            >
                Delete
            </Button.Danger>
        </div>
    );
}

interface ClientsTableProps {
    clients: OAuthClient[];
    isFetching: boolean;
    onEdit: (client: OAuthClient) => void;
    onAction: (action: ClientAction, client: OAuthClient) => void;
}

export default function ClientsTable({ clients, isFetching, onEdit, onAction }: ClientsTableProps) {
    return (
        <Table
            rows={clients}
            isFetching={isFetching}
            keyOf={(row) => String(row.id)}
            emptyState='No OAuth apps yet.'
            columns={[
                {
                    id: 'name',
                    label: 'Name',
                    cell: (row) => <p className='rjo:font-medium rjo:text-foreground'>{row.name}</p>,
                },
                {
                    id: 'client_id',
                    label: 'Client ID',
                    cell: (row) => (
                        <CopyOnClick text={String(row.id)}>
                            <code className='rjo:font-mono rjo:text-xs rjo:text-foreground'>{row.id}</code>
                        </CopyOnClick>
                    ),
                },
                {
                    id: 'type',
                    label: 'Type',
                    cell: (row) => <Badge tone='neutral'>{row.confidential ? 'Confidential' : 'Public'}</Badge>,
                },
                {
                    id: 'redirect_uris',
                    label: 'Redirect URIs',
                    cell: (row) => <RedirectUris uris={row.redirect_uris} />,
                },
                {
                    id: 'skip_authorization',
                    label: 'Skips consent',
                    cell: (row) =>
                        row.skip_authorization ? (
                            <Badge tone='warning'>
                                <ShieldAlert aria-hidden='true' className='rjo:size-3' />
                                Yes
                            </Badge>
                        ) : (
                            <Plain>No</Plain>
                        ),
                },
                {
                    id: 'revoked',
                    label: 'Revoked',
                    cell: (row) => (row.revoked ? <Badge tone='danger'>Yes</Badge> : <Plain>No</Plain>),
                },
                {
                    id: 'created_at',
                    label: 'Created',
                    cell: (row) => (
                        <span className='rjo:whitespace-nowrap rjo:text-sm rjo:text-muted-foreground'>
                            {formatDate(row.created_at)}
                        </span>
                    ),
                },
                {
                    id: 'actions',
                    label: <span className='rjo:sr-only'>Actions</span>,
                    cell: (row) => (
                        <div className='rjo:flex rjo:flex-col rjo:items-end rjo:gap-2'>
                            <Button.Text
                                type='button'
                                size='xsmall'
                                aria-label={`Edit ${row.name}`}
                                onClick={() => onEdit(row)}
                            >
                                Edit
                            </Button.Text>
                            <RowActions client={row} onAction={onAction} />
                        </div>
                    ),
                },
            ]}
        />
    );
}
