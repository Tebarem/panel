import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import EggFormFields from './EggFormFields';
import { registerRegistries } from './registries';
import { registerSources } from './sources';

export default definePterodactylExtension({
    setup(context) {
        registerRegistries(context);
        registerSources(context);

        // Registered once: the admin egg form takes a single extension per form.
        context.forms.extend('admin.egg', EggFormFields);
    },
});
