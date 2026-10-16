import { useQuery } from '@tanstack/react-query';
import { ChevronDown, Copy } from 'lucide-react';
import { Alert, CopyOnClick, Spinner } from '@pterodactyl/sdk';
import { fetchDiscovery } from '../api';
import { OIDC_PATHS, endpointsFromDiscovery, oidcEndpoints } from '../helpers';

/**
 * The endpoints an app needs to sign users in, in a collapsed card. They come from the panel's own discovery document,
 * so the issuer is the panel's configured URL, not whatever address this page was opened on.
 */
export default function OidcEndpoints() {
    const origin = window.location.origin;
    const discoveryUrl = `${origin}${OIDC_PATHS.discovery}`;
    const discovery = useQuery({
        queryKey: ['ramjet-oauth', 'discovery'],
        queryFn: fetchDiscovery,
        retry: false,
        staleTime: 5 * 60 * 1000,
    });

    // Origin-built values are a fallback only, and the card says so.
    const advertised = discovery.isSuccess ? endpointsFromDiscovery(discovery.data, discoveryUrl) : undefined;
    const usingFallback = discovery.isError || (discovery.isSuccess && advertised === undefined);
    const endpoints = advertised ?? oidcEndpoints(origin);
    const issuer = endpoints.find((endpoint) => endpoint.id === 'issuer')?.url;

    return (
        <details className='rjo:group rjo:mb-6 rjo:rounded-sm rjo:border rjo:border-border rjo:bg-card'>
            <summary className='rjo:flex rjo:cursor-pointer rjo:list-none rjo:items-center rjo:gap-4 rjo:px-4 rjo:py-3 rjo:focus-visible:outline-2 rjo:focus-visible:outline-offset-2 rjo:focus-visible:outline-primary'>
                <span className='rjo:min-w-0 rjo:flex-1'>
                    <span className='rjo:flex rjo:flex-wrap rjo:items-center rjo:gap-2 rjo:text-sm rjo:font-medium rjo:text-foreground'>
                        OpenID Connect endpoints
                        {usingFallback && (
                            <span className='rjo:rounded-sm rjo:border rjo:border-warning/25 rjo:bg-warning/10 rjo:px-1.5 rjo:py-px rjo:text-xs rjo:font-normal rjo:text-warning'>
                                Address from this page
                            </span>
                        )}
                    </span>
                    <span className='rjo:block rjo:truncate rjo:font-mono rjo:text-xs rjo:text-muted-foreground'>
                        {issuer ?? 'Loading the discovery document…'}
                    </span>
                </span>
                <ChevronDown
                    aria-hidden='true'
                    className='rjo:h-4 rjo:w-4 rjo:shrink-0 rjo:text-muted-foreground rjo:transition-transform rjo:duration-150 rjo:group-open:rotate-180'
                />
            </summary>

            <div className='rjo:border-t rjo:border-border rjo:px-4 rjo:py-3'>
                <p className='rjo:text-sm rjo:leading-relaxed rjo:text-foreground/70'>
                    Enter these in the app you are connecting. The issuer must match exactly. The discovery document
                    lists every endpoint and the options the panel supports.
                </p>

                {usingFallback && (
                    <Alert type='warning' className='rjo:mt-3'>
                        The discovery document could not be loaded, so these addresses are built from the address of
                        this page. Behind a proxy they can differ from the panel's configured URL. Check them against
                        the discovery document before you use them.
                    </Alert>
                )}

                {discovery.isPending ? (
                    <div className='rjo:mt-3'>
                        <Spinner size='small' />
                    </div>
                ) : (
                    <dl className='rjo:mt-3 rjo:divide-y rjo:divide-border'>
                        {endpoints.map((endpoint) => (
                            <div
                                key={endpoint.id}
                                className='rjo:grid rjo:gap-1 rjo:py-2.5 rjo:sm:grid-cols-[10rem_minmax(0,1fr)] rjo:sm:items-center rjo:sm:gap-4'
                            >
                                <dt className='rjo:text-sm rjo:font-medium rjo:text-foreground'>{endpoint.label}</dt>
                                <dd className='rjo:flex rjo:min-w-0 rjo:items-center rjo:gap-2'>
                                    <code
                                        className='rjo:min-w-0 rjo:flex-1 rjo:truncate rjo:font-mono rjo:text-xs rjo:text-foreground/80'
                                        title={endpoint.url}
                                    >
                                        {endpoint.url}
                                    </code>
                                    <CopyOnClick text={endpoint.url}>
                                        <button
                                            type='button'
                                            aria-label={`Copy ${endpoint.label}`}
                                            className='rjo:inline-flex rjo:h-6 rjo:w-6 rjo:shrink-0 rjo:items-center rjo:justify-center rjo:rounded-sm rjo:text-muted-foreground rjo:transition-colors rjo:hover:bg-muted rjo:hover:text-foreground'
                                        >
                                            <Copy aria-hidden='true' className='rjo:h-3.5 rjo:w-3.5' />
                                        </button>
                                    </CopyOnClick>
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
            </div>
        </details>
    );
}
