import { http } from '@pterodactyl/sdk';

export interface ProxyServer {
    /** The short identifier the server page is addressed by. */
    identifier: string;
    name: string;
    uuidShort: string;
}

export interface ProxyStatus {
    enabled: boolean;
    has_proxy: boolean;
    server: ProxyServer | null;
    can_create: boolean;
    /** Why the customer cannot create a proxy right now, when they cannot. */
    reason?: string;
}

const BASE = '/api/client/extensions/ramjet-proxy-provisioning';

export const proxyStatusQueryKey = ['ext-ramjet-proxy-provisioning', 'status'] as const;

/** The operation id the panel's dashboard server list is cached under. */
const DASHBOARD_SERVERS_OPERATION = 'clientListClientServers';

export const fetchProxyStatus = async (): Promise<ProxyStatus> => (await http.get<ProxyStatus>(`${BASE}/status`)).data;

/** The panel picks the egg, node, resources and port; the only field sent is the confirm flag. */
export const createProxy = async (): Promise<ProxyServer> =>
    (await http.post<{ server: ProxyServer }>(`${BASE}/create`, { confirm: true })).data.server;

/** Matches the dashboard's server list queries, whichever page or filter they were loaded with. */
export const isDashboardServersQuery = ({ queryKey }: { queryKey: readonly unknown[] }): boolean => {
    const [key] = queryKey;

    return typeof key === 'object' && key !== null && '_id' in key && key._id === DASHBOARD_SERVERS_OPERATION;
};
