import React from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import styled from 'styled-components/macro';
import Input from '@/components/elements/Input';
import { PROTECTED_FILE_TOOLTIP } from '@/components/server/files/protected';

export const FileActionCheckbox = styled(Input)`
    && {
        ${tw`border-neutral-500 bg-transparent`};

        &:not(:checked) {
            ${tw`hover:border-neutral-300`};
        }

        &:disabled {
            ${tw`cursor-not-allowed`};
        }
    }
`;

export default ({ name, disabled = false }: { name: string; disabled?: boolean }) => {
    const isChecked = ServerContext.useStoreState((state) => state.files.selectedFiles.indexOf(name) >= 0);
    const appendSelectedFile = ServerContext.useStoreActions((actions) => actions.files.appendSelectedFile);
    const removeSelectedFile = ServerContext.useStoreActions((actions) => actions.files.removeSelectedFile);

    return (
        <label
            css={[
                tw`flex-none px-4 py-2 absolute self-center z-30`,
                disabled ? tw`cursor-not-allowed opacity-50` : tw`cursor-pointer`,
            ]}
            title={disabled ? PROTECTED_FILE_TOOLTIP : undefined}
        >
            <FileActionCheckbox
                name={'selectedFiles'}
                value={name}
                checked={isChecked}
                disabled={disabled}
                type={'checkbox'}
                onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
                    if (disabled) return;

                    if (e.currentTarget.checked) {
                        appendSelectedFile(name);
                    } else {
                        removeSelectedFile(name);
                    }
                }}
            />
        </label>
    );
};
