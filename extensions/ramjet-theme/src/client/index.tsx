// The SDK's Vite preset extracts the stylesheet to dist/ and makes the bundle wait for it,
// so the theme is in place before setup() runs. A theme needs no slots or screens.
import './theme.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';

export default definePterodactylExtension({
    setup() {},
});
