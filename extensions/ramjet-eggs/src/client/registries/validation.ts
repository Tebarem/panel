// Mirrors DockerRegistry::validationRules() on the panel. The panel stays the authority; these checks
// only report problems while the admin types.

const HOST_PATTERN =
    /^(?:localhost|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?=:))(?::[0-9]{1,5})?(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*$/;

/** Registries are compared and stored trimmed and in lower case. */
export const normalizeRegistry = (value: string): string => value.trim().toLowerCase();

export const registryProblem = (value: string): string | undefined => {
    const registry = normalizeRegistry(value);

    if (registry === '') return 'Enter the registry, such as ghcr.io/myorg.';
    if (registry.length > 191) return 'Keep the registry under 191 characters.';
    if (!HOST_PATTERN.test(registry)) {
        return 'Use a host with an optional port and namespace, without a scheme or trailing slash, such as ghcr.io/myorg.';
    }

    return undefined;
};

/** A registry without a namespace sends its credentials for every image on that host. */
export const isWholeHost = (value: string): boolean => !normalizeRegistry(value).includes('/');
