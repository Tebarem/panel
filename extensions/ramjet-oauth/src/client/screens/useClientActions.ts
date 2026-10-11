import { useMutation, useQueryClient } from '@tanstack/react-query';
import { httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { deleteClient, regenerateClientSecret, revokeClientTokens, type OAuthClient } from '../api';
import { CLIENTS_QUERY_KEY } from '../helpers';
import type { IssuedSecret } from './SecretDialog';

export type ClientAction = 'regenerate' | 'revoke' | 'delete';

interface ClientActionOptions {
    /** Receives a new secret. It goes only to the one-time dialog, never into a mutation result or the query cache. */
    onIssued: (issued: IssuedSecret) => void;
    /** Runs after an action succeeds, so its confirmation can close. */
    onDone: () => void;
}

/** The three actions that change a client from its row. None of them returns a secret to the cache. */
export function useClientActions({ onIssued, onDone }: ClientActionOptions) {
    const queryClient = useQueryClient();
    const refresh = () => queryClient.invalidateQueries({ queryKey: CLIENTS_QUERY_KEY });
    const onError = (cause: unknown) => toast.error(httpErrorToHuman(cause));

    const regenerate = useMutation({
        mutationFn: async (client: OAuthClient) => {
            const secret = await regenerateClientSecret(client.id);

            onIssued({ clientName: client.name, reason: 'regenerated', secret });
        },
        onSuccess: async () => {
            await refresh();
            onDone();
        },
        onError,
    });

    const revoke = useMutation({
        mutationFn: (client: OAuthClient) => revokeClientTokens(client.id),
        onSuccess: async () => {
            await refresh();
            toast.success('Tokens revoked.');
            onDone();
        },
        onError,
    });

    const remove = useMutation({
        mutationFn: (client: OAuthClient) => deleteClient(client.id),
        onSuccess: async () => {
            await refresh();
            toast.success('OAuth app deleted.');
            onDone();
        },
        onError,
    });

    const run = (action: ClientAction, client: OAuthClient) => {
        if (action === 'regenerate') {
            regenerate.mutate(client);
        } else if (action === 'revoke') {
            revoke.mutate(client);
        } else {
            remove.mutate(client);
        }
    };

    return {
        run,
        pending: regenerate.isPending || revoke.isPending || remove.isPending,
    };
}
