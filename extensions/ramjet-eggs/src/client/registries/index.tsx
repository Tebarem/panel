import type { ExtensionSetupContext } from '@pterodactyl/sdk';

// Feature 4 (Docker registries): the admin screen. The screen id matches the one declared in
// extension.json. The egg form field (RegistryEggField) is not registered here: the egg form's single
// form extension, composed by the client index, draws it.
export function registerRegistries(context: ExtensionSetupContext): void {
    context.screens.register('registries', () => import('./RegistriesScreen'));
}
