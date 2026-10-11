import type { ExtensionSetupContext } from '@pterodactyl/sdk';

// Feature 5 (egg sources): the admin screen that syncs eggs from GitHub, GitLab or a single URL.
// The screen id matches the one declared in extension.json.
export function registerSources(context: ExtensionSetupContext): void {
    context.screens.register('sources', () => import('./SourcesScreen'));
}
