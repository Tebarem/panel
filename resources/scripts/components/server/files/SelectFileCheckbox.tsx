import { useServerDirectory, useServerStore } from '@/state/server';
import Checkbox from '@/components/ui/Checkbox';
import { PROTECTED_FILE_TOOLTIP } from '@/components/server/files/protectedFiles';

export default function SelectFileCheckbox({ name, disabled = false }: { name: string; disabled?: boolean }) {
    const directory = useServerDirectory();
    const isChecked = useServerStore(
        (state) => state.files.selectedDirectory === directory && state.files.selectedFiles.includes(name)
    );
    const appendSelectedFile = useServerStore((state) => state.files.appendSelectedFile);
    const removeSelectedFile = useServerStore((state) => state.files.removeSelectedFile);

    return (
        <div
            className='flex-none px-4 py-2 absolute self-center z-30'
            title={disabled ? PROTECTED_FILE_TOOLTIP : undefined}
        >
            <Checkbox
                name='selectedFiles'
                value={name}
                aria-label={`Select ${name}`}
                checked={isChecked}
                disabled={disabled}
                onChange={(checked) => {
                    if (disabled) {
                        return;
                    }

                    if (checked) {
                        appendSelectedFile({ directory, name });
                    } else {
                        removeSelectedFile({ directory, name });
                    }
                }}
            />
        </div>
    );
}
