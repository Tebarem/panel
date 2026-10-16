import type { ReactNode } from 'react';
import { Dialog } from '@pterodactyl/sdk';

interface ConfirmDialogProps {
    title: string;
    confirmLabel: string;
    /** The icon's tone: 'danger' for deletes and revocations, 'warning' for a new secret. */
    tone: 'danger' | 'warning';
    pending: boolean;
    onClose: () => void;
    onConfirm: () => void;
    children: ReactNode;
}

/** The panel's own confirmation: a red confirm button that disables itself while the request runs. */
export default function ConfirmDialog({
    title,
    confirmLabel,
    tone,
    pending,
    onClose,
    onConfirm,
    children,
}: ConfirmDialogProps) {
    return (
        <Dialog.Confirm
            open
            title={title}
            confirm={confirmLabel}
            pending={pending}
            onClose={onClose}
            onConfirmed={onConfirm}
        >
            <Dialog.Icon type={tone} />
            <div className='rjo:grid rjo:gap-3 rjo:text-sm rjo:leading-relaxed rjo:text-muted-foreground'>
                {children}
            </div>
        </Dialog.Confirm>
    );
}
