import { useQuery } from '@tanstack/react-query';
import { Alert, Button, CopyOnClick, Spinner } from '@pterodactyl/sdk';
import { fetchDiscovery } from '../api';
import { OIDC_PATHS, endpointsFromDiscovery, oidcEndpoints } from '../helpers';

/** The endpoints an app needs to sign users in. They come from the panel's own discovery document. */
export default function OidcEndpoints() {
    const origin = window.location.origin;
    const discoveryUrl = `${origin}${OIDC_PATHS.discovery}`;
    const discovery = useQuery({
        queryKey: ['ramjet-oauth', 'discovery'],
        queryFn: fetchDiscovery,
        retry: false,
        staleTime: 5 * 60 * 1000,
    });

    // The document is authoritative: its issuer is the panel's configured URL, which can differ from this page's
    // origin behind a proxy. The origin-built values are a fallback, flagged as such.
    const advertised = discovery.isSuccess ? endpointsFromDiscovery(discovery.data, discoveryUrl) : undefined;
    const usingFallback = discovery.isError || (discovery.isSuccess && advertised === undefined);
    const endpoints = advertised ?? oidcEndpoints(origin);

    return (
        <section
            aria-labelledby='oauth-oidc-heading'
            className='rjo:mb-6 rjo:rounded-sm rjo:border rjo:border-border rjo:bg-card rjo:p-4 rjo:sm:p-5'
        >
            <h2 id='oauth-oidc-heading' className='rjo:font-header rjo:text-base rjo:font-semibold rjo:text-foreground'>
                OpenID Connect endpoints
            </h2>
            <p className='rjo:mt-1 rjo:text-sm rjo:leading-relaxed rjo:text-foreground/70'>
                Enter these in the app you are connecting. The issuer must match exactly. The discovery document lists
                every endpoint and the options the panel supports.
            </p>

            {usingFallback && (
                <Alert type='warning' className='rjo:mt-4'>
                    The discovery document could not be loaded, so these addresses are built from the address of this
                    page. Behind a proxy they can differ from the panel's configured URL. Check them against the
                    discovery document before you use them.
                </Alert>
            )}

            {discovery.isPending ? (
                <div className='rjo:mt-4'>
                    <Spinner size='small' />
                </div>
            ) : (
                <dl className='rjo:mt-4 rjo:grid rjo:gap-0'>
                    {endpoints.map((endpoint) => (
                        <div
                            key={endpoint.id}
                            className='rjo:grid rjo:gap-1 rjo:border-t rjo:border-border rjo:py-3 rjo:sm:grid-cols-[11rem_minmax(0,1fr)] rjo:sm:items-center rjo:sm:gap-4'
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
                                    <Button.Text type='button' size='xsmall'>
                                        Copy
                                    </Button.Text>
                                </CopyOnClick>
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}
