import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import ProxyCard from './ProxyCard';

export default definePterodactylExtension({
    setup({ slots }) {
        // Above the server list, under the dashboard heading. Renders nothing while the feature is disabled.
        slots.register('dashboard.before', ProxyCard);
    },
});
