import type { ReactNode } from 'react';
import { Ban, Copy, KeyRound, MoreHorizontal, Pencil, ShieldAlert, Trash2 } from 'lucide-react';
import { CopyOnClick, DropdownMenu, DropdownMenuItem } from '@pterodactyl/sdk';
import type { OAuthClient } from '../api';
import { absoluteTime, middleTruncate, relativeTime } from '../format';
import type { ClientAction } from './useClientActions';

// The same markup, sizes and colours as the panel's DataTable, so this table sits with the other admin tables.
// Auto layout, as DataTable uses: the name cell is `w-0 min-w-full`, so it takes whatever width the others leave.
const headClass =
    'rjo:h-10 rjo:whitespace-nowrap rjo:px-1.5 rjo:text-left rjo:text-xs rjo:font-semibold rjo:uppercase rjo:tracking-wide rjo:text-muted-foreground rjo:md:px-3';
const cellClass = 'rjo:px-1.5 rjo:py-2.5 rjo:align-middle rjo:text-foreground rjo:md:px-3';

// Widths apply from md up, so on a phone the name and the two short columns share the space.
const TYPE_WIDTH = 'rjo:md:w-28';
const REDIRECT_WIDTH = 'rjo:md:w-64';
const CONSENT_WIDTH = 'rjo:md:w-32';
const CREATED_WIDTH = 'rjo:md:w-36';
const ACTIONS_WIDTH = 'rjo:w-12';

// Redirect URIs and the created date are only in the table at md and up; the edit dialog has both.
const WIDE_ONLY = 'rjo:hidden rjo:md:table-cell';

type Tone = 'neutral' | 'warning' | 'muted';

const TONE_CLASSES = {
    neutral: 'rjo:border-border rjo:bg-muted rjo:text-foreground',
    warning: 'rjo:border-warning/25 rjo:bg-warning/10 rjo:text-warning',
    muted: 'rjo:border-border rjo:text-muted-foreground',
} satisfies Record<Tone, string>;

function Badge({ tone, children }: { tone: Tone; children: ReactNode }) {
    return (
        <span
            className={`rjo:inline-flex rjo:shrink-0 rjo:items-center rjo:gap-1 rjo:whitespace-nowrap rjo:rounded-sm rjo:border rjo:px-1.5 rjo:py-0.5 rjo:text-xs rjo:font-medium ${TONE_CLASSES[tone]}`}
        >
            {children}
        </span>
    );
}

/** A click on the actions or the copy button belongs to that control, not to the row (which opens Edit). */
const stopRowClick = (event: { stopPropagation: () => void }) => event.stopPropagation();

function ClientName({ client }: { client: OAuthClient }) {
    return (
        <div className='rjo:w-0 rjo:min-w-full'>
            <div className='rjo:flex rjo:min-w-0 rjo:items-center rjo:gap-2'>
                {/* No handler of its own: the click reaches the row, which opens Edit, and Enter clicks through. */}
                <button
                    type='button'
                    title={client.name}
                    aria-label={`Edit ${client.name}`}
                    className='rjo:min-w-0 rjo:truncate rjo:text-left rjo:font-medium rjo:text-foreground rjo:transition-colors rjo:hover:text-accent'
                >
                    {client.name}
                </button>
                {client.revoked && <Badge tone='muted'>Revoked</Badge>}
            </div>
            <div className='rjo:mt-0.5 rjo:flex rjo:min-w-0 rjo:items-center rjo:gap-1' onClick={stopRowClick}>
                <code
                    className='rjo:min-w-0 rjo:truncate rjo:font-mono rjo:text-xs rjo:text-muted-foreground'
                    title={client.id}
                >
                    {middleTruncate(client.id)}
                </code>
                <CopyOnClick text={client.id}>
                    <button
                        type='button'
                        aria-label='Copy client ID'
                        className='rjo:inline-flex rjo:h-5 rjo:w-5 rjo:shrink-0 rjo:items-center rjo:justify-center rjo:rounded-sm rjo:text-muted-foreground rjo:transition-colors rjo:hover:bg-muted rjo:hover:text-foreground'
                    >
                        <Copy aria-hidden='true' className='rjo:h-3 rjo:w-3' />
                    </button>
                </CopyOnClick>
            </div>
        </div>
    );
}

function RedirectUris({ uris }: { uris: string[] }) {
    if (uris.length === 0) {
        return <span className='rjo:text-xs rjo:text-muted-foreground'>None</span>;
    }

    const [first] = uris;
    const more = uris.length - 1;

    return (
        <div className='rjo:flex rjo:w-0 rjo:min-w-full rjo:items-center rjo:gap-2' title={uris.join('\n')}>
            <code className='rjo:min-w-0 rjo:flex-1 rjo:truncate rjo:font-mono rjo:text-xs'>{first}</code>
            {more > 0 && <span className='rjo:shrink-0 rjo:text-xs rjo:text-muted-foreground'>+{more} more</span>}
        </div>
    );
}

function RowMenu({
    client,
    onAction,
    onEdit,
}: {
    client: OAuthClient;
    onAction: (action: ClientAction, client: OAuthClient) => void;
    onEdit: (client: OAuthClient) => void;
}) {
    return (
        <div className='rjo:flex rjo:justify-end' onClick={stopRowClick}>
            <DropdownMenu
                triggerClassName='rjo:inline-flex rjo:h-7 rjo:w-7 rjo:items-center rjo:justify-center rjo:rounded-sm rjo:border rjo:border-border rjo:bg-muted rjo:text-muted-foreground rjo:transition-colors rjo:hover:bg-popover rjo:hover:text-foreground rjo:focus-visible:outline-2 rjo:focus-visible:outline-offset-2 rjo:focus-visible:outline-primary'
                triggerContent={
                    <>
                        <MoreHorizontal aria-hidden='true' className='rjo:h-4 rjo:w-4' />
                        <span className='rjo:sr-only'>Actions for {client.name}</span>
                    </>
                }
            >
                <DropdownMenuItem icon={Pencil} onClick={() => onEdit(client)}>
                    Edit
                </DropdownMenuItem>
                {client.confidential && (
                    <DropdownMenuItem icon={KeyRound} onClick={() => onAction('regenerate', client)}>
                        New secret
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem icon={Ban} onClick={() => onAction('revoke', client)}>
                    Revoke tokens
                </DropdownMenuItem>
                <div role='separator' className='rjo:my-1 rjo:h-px rjo:bg-border' />
                <DropdownMenuItem icon={Trash2} danger onClick={() => onAction('delete', client)}>
                    Delete
                </DropdownMenuItem>
            </DropdownMenu>
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
    const now = Date.now();

    return (
        <div
            aria-busy={isFetching}
            className='rjo:overflow-hidden rjo:rounded-sm rjo:border rjo:border-border rjo:bg-card'
        >
            <div className='rjo:overflow-x-auto'>
                <table className='rjo:w-full rjo:border-collapse rjo:text-left rjo:text-sm'>
                    <thead className='rjo:border-b rjo:border-border rjo:bg-muted/70'>
                        <tr>
                            <th scope='col' className={headClass}>
                                Name
                            </th>
                            <th scope='col' className={`${headClass} ${TYPE_WIDTH}`}>
                                Type
                            </th>
                            <th scope='col' className={`${headClass} ${REDIRECT_WIDTH} ${WIDE_ONLY}`}>
                                Redirect URIs
                            </th>
                            <th scope='col' className={`${headClass} ${CONSENT_WIDTH}`}>
                                <span className='rjo:sr-only rjo:md:not-sr-only'>Consent</span>
                            </th>
                            <th scope='col' className={`${headClass} ${CREATED_WIDTH} ${WIDE_ONLY}`}>
                                Created
                            </th>
                            <th scope='col' className={`${headClass} ${ACTIONS_WIDTH}`}>
                                <span className='rjo:sr-only'>Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        className={`rjo:divide-y rjo:divide-border rjo:transition-opacity ${isFetching ? 'rjo:opacity-55' : ''}`}
                    >
                        {clients.map((client) => (
                            <tr
                                key={client.id}
                                onClick={() => onEdit(client)}
                                className='rjo:cursor-pointer rjo:transition-colors rjo:hover:bg-muted/35'
                            >
                                <td className={`${cellClass} rjo:min-w-30`}>
                                    <ClientName client={client} />
                                </td>
                                <td className={cellClass}>
                                    <Badge tone='neutral'>{client.confidential ? 'Confidential' : 'Public'}</Badge>
                                </td>
                                <td className={`${cellClass} ${WIDE_ONLY}`}>
                                    <RedirectUris uris={client.redirect_uris} />
                                </td>
                                <td className={cellClass}>
                                    {client.skip_authorization ? (
                                        <Badge tone='warning'>
                                            <ShieldAlert aria-hidden='true' className='rjo:h-3 rjo:w-3' />
                                            <span className='rjo:sr-only rjo:md:not-sr-only'>Skipped</span>
                                        </Badge>
                                    ) : (
                                        <span className='rjo:whitespace-nowrap rjo:text-xs rjo:text-muted-foreground'>
                                            <span aria-hidden='true' className='rjo:md:hidden'>
                                                –
                                            </span>
                                            <span className='rjo:sr-only rjo:md:not-sr-only'>Required</span>
                                        </span>
                                    )}
                                </td>
                                <td className={`${cellClass} ${WIDE_ONLY}`}>
                                    <time
                                        dateTime={client.created_at}
                                        title={absoluteTime(client.created_at)}
                                        className='rjo:whitespace-nowrap rjo:text-xs rjo:text-muted-foreground'
                                    >
                                        {relativeTime(client.created_at, now)}
                                    </time>
                                </td>
                                <td className={cellClass}>
                                    <RowMenu client={client} onAction={onAction} onEdit={onEdit} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
