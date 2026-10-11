import type { FormExtension } from '@pterodactyl/sdk';
import { RegistryEggField } from './registries/RegistryEggField';
import RuleFields from './rules/RuleFields';

type EggFormValues = {
    file_hidden: string | null;
    file_denylist: string | null;
    registry_id: number | null;
};

/**
 * Every field the extension draws on the admin egg form. A form takes one form extension per extension,
 * so the file rules and the registry select are drawn together here.
 */
const EggFormFields: FormExtension<EggFormValues, 'admin.egg'> = (props) => (
    <div className={'rjeggs:grid rjeggs:gap-6'}>
        <RuleFields {...props} />
        <RegistryEggField {...props} />
    </div>
);

export default EggFormFields;
