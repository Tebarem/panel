import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import { SCREEN_ID } from './helpers';

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register(SCREEN_ID, () => import('./screens/OAuthClientsScreen'));
    },
});
