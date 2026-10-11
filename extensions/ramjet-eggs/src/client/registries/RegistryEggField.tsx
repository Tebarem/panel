import { useQuery } from '@tanstack/react-query';
import { Label, Select, httpErrorToHuman, type FormExtension } from '@pterodactyl/sdk';
import { fetchRegistries, registriesQueryKey } from './api';

type RegistryEggValues = {
    registry_id: number | null;
};

/** The egg form's Docker registry select. The value is the registry id, or null for none. */
export const RegistryEggField: FormExtension<RegistryEggValues, 'admin.egg'> = ({ field }) => {
    const registry = field('registry_id');
    const { data: registries, error } = useQuery({ queryKey: registriesQueryKey, queryFn: fetchRegistries });

    const options = [
        { value: 0, label: 'No registry (pull without credentials)' },
        ...(registries ?? []).map((item) => ({ value: item.id, label: `${item.name} (${item.registry})` })),
    ];

    return (
        <div className={'rjeggs:grid rjeggs:gap-2'}>
            <Label htmlFor={registry.id}>Docker registry</Label>
            <Select
                id={registry.id}
                value={registry.value ?? 0}
                options={options}
                hasError={registry.error !== undefined}
                onChange={(value) => registry.setValue(Number(value) === 0 ? null : Number(value))}
            />
            {error ? (
                <p className={'rjeggs:text-xs rjeggs:text-destructive'}>{httpErrorToHuman(error)}</p>
            ) : (
                <p className={'rjeggs:text-xs rjeggs:text-foreground/70'}>
                    Wings uses this registry's credentials when it pulls the image for servers of this egg.
                </p>
            )}
            {registry.error && <p role={'alert'} className={'rjeggs:text-xs rjeggs:text-destructive'}>{registry.error}</p>}
        </div>
    );
};

export default RegistryEggField;
