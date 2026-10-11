import { Label, TextArea, type FormExtension } from '@pterodactyl/sdk';

type RuleValues = {
    file_hidden: string | null;
    file_denylist: string | null;
};

/** The two textareas of the admin egg form: hidden and protected file patterns, one per line. */
const RuleFields: FormExtension<RuleValues, 'admin.egg'> = ({ field, resource }) => {
    const hidden = field('file_hidden');
    const protectedFiles = field('file_denylist');
    const inheritsFrom = resource?.attributes.config.extends ?? null;

    return (
        <div className={'rjeggs:space-y-4'}>
            {inheritsFrom !== null && (
                <p className={'rjeggs:text-sm rjeggs:text-muted-foreground'}>
                    This egg copies its configuration from another egg. The file rules of that egg are used, and the
                    rules saved here apply only once the copy is removed.
                </p>
            )}

            <div className={'rjeggs:space-y-2'}>
                <Label htmlFor={hidden.id}>Hidden files</Label>
                <TextArea
                    id={hidden.id}
                    rows={4}
                    placeholder={'config/secrets.yml\n*.key'}
                    value={hidden.value ?? ''}
                    onChange={(event) => hidden.setValue(event.currentTarget.value)}
                />
                <p className={'rjeggs:text-sm rjeggs:text-muted-foreground'}>
                    Files and folders matching these patterns are not listed in the file manager. One pattern per line.
                </p>
                {hidden.error && <p className={'rjeggs:text-sm rjeggs:text-destructive'}>{hidden.error}</p>}
            </div>

            <div className={'rjeggs:space-y-2'}>
                <Label htmlFor={protectedFiles.id}>Protected files</Label>
                <TextArea
                    id={protectedFiles.id}
                    rows={4}
                    placeholder={'eula.txt\nserver.properties'}
                    value={protectedFiles.value ?? ''}
                    onChange={(event) => protectedFiles.setValue(event.currentTarget.value)}
                />
                <p className={'rjeggs:text-sm rjeggs:text-muted-foreground'}>
                    Files and folders matching these patterns are listed but cannot be modified, moved, deleted or
                    downloaded. One pattern per line.
                </p>
                {protectedFiles.error && <p className={'rjeggs:text-sm rjeggs:text-destructive'}>{protectedFiles.error}</p>}
            </div>
        </div>
    );
};

export default RuleFields;
