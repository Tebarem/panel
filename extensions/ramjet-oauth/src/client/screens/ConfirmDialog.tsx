import type { ReactNode } from 'react';
import { Button, Dialog } from '@pterodactyl/sdk';

interface ConfirmDialogProps {
    title: string;
    confirmLabel: string;
    danger: boolean;
    pending: boolean;
    onClose: () => void;
    onConfirm: () => void;
    children: ReactNode;
}

/** A confirmation whose button shows that the request is running, so it cannot be sent twice. */
export default function ConfirmDialog({
    title,
    confirmLabel,
    danger,
    pending,
    onClose,
    onConfirm,
    children,
}: ConfirmDialogProps) {
    const ConfirmButton = danger ? Button.Danger : Button;

    return (
        <Dialog open onClose={onClose} title={title} preventExternalClose={pending}>
            <div className='rjo:grid rjo:gap-3 rjo:text-sm rjo:leading-relaxed rjo:text-muted-foreground'>
                {children}
            </div>
            <Dialog.Footer>
                <Button.Text type='button' disabled={pending} onClick={onClose}>
                    Cancel
                </Button.Text>
                <ConfirmButton type='button' isLoading={pending} disabled={pending} onClick={onConfirm}>
                    {confirmLabel}
                </ConfirmButton>
            </Dialog.Footer>
        </Dialog>
    );
}
