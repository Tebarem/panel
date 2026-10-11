import { useState } from 'react';
import { Alert, Button, Checkbox, CopyOnClick, Dialog, Label } from '@pterodactyl/sdk';

/**
 * A secret that exists only while its dialog is open. The screen drops its copy when the dialog closes. Nothing here
 * is cached, logged or put in a toast.
 */
export interface IssuedSecret {
    clientName: string;
    reason: 'created' | 'regenerated';
    secret: string;
}

interface SecretDialogProps {
    issued: IssuedSecret;
    onClose: () => void;
}

const headingFor = (reason: IssuedSecret['reason']): string =>
    reason === 'created' ? 'OAuth app created' : 'New client secret';

export default function SecretDialog({ issued, onClose }: SecretDialogProps) {
    const [stored, setStored] = useState(false);

    return (
        <Dialog
            open
            onClose={onClose}
            title={`${headingFor(issued.reason)}: ${issued.clientName}`}
            preventExternalClose
            hideCloseIcon
        >
            <div className='rjo:grid rjo:gap-4'>
                <Alert type='warning'>
                    This secret is shown only once. You cannot view it again after you close this dialog. If you lose
                    it, regenerate it, which makes the old secret stop working.
                </Alert>

                <div className='rjo:grid rjo:gap-2'>
                    <Label>Client secret</Label>
                    <div className='rjo:flex rjo:flex-wrap rjo:items-center rjo:gap-2'>
                        <code className='rjo:min-w-0 rjo:flex-1 rjo:break-all rjo:rounded-sm rjo:border rjo:border-border rjo:bg-muted rjo:px-3 rjo:py-2 rjo:font-mono rjo:text-sm rjo:text-foreground rjo:select-all'>
                            {issued.secret}
                        </code>
                        <CopyOnClick text={issued.secret}>
                            <Button.Text type='button' size='small'>
                                Copy
                            </Button.Text>
                        </CopyOnClick>
                    </div>
                </div>

                <label className='rjo:flex rjo:cursor-pointer rjo:items-center rjo:gap-3 rjo:text-sm'>
                    <Checkbox checked={stored} onChange={setStored} />
                    <span className='rjo:text-foreground'>I have copied the secret somewhere safe.</span>
                </label>
            </div>

            <Dialog.Footer>
                <Button type='button' disabled={!stored} onClick={onClose}>
                    Done
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
}
