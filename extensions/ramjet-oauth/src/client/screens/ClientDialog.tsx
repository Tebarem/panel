import { useId, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Checkbox, Dialog, Form, Label, httpErrorToHuman, toast, useAppForm } from '@pterodactyl/sdk';
import { createClient, updateClient, type ClientCreate, type ClientUpdate, type OAuthClient } from '../api';
import {
    CLIENTS_QUERY_KEY,
    firstError,
    formatRedirectUris,
    parseRedirectUris,
    readServerErrors,
    validateName,
    validateRedirectUris,
} from '../helpers';

interface FormState {
    name: string;
    redirect_uris: string;
    confidential: boolean;
    skip_authorization: boolean;
}

const emptyForm: FormState = { name: '', redirect_uris: '', confidential: true, skip_authorization: false };

const toForm = (client: OAuthClient): FormState => ({
    name: client.name,
    redirect_uris: formatRedirectUris(client.redirect_uris),
    confidential: client.confidential,
    skip_authorization: client.skip_authorization,
});

const toCreate = (f: FormState): ClientCreate => ({
    name: f.name.trim(),
    redirect_uris: parseRedirectUris(f.redirect_uris),
    confidential: f.confidential,
    skip_authorization: f.skip_authorization,
});

const toUpdate = (f: FormState): ClientUpdate => ({
    name: f.name.trim(),
    redirect_uris: parseRedirectUris(f.redirect_uris),
    skip_authorization: f.skip_authorization,
});

const FieldError = ({ children }: { children: ReactNode }) => (
    <p role='alert' className='rjo:mt-1 rjo:text-xs rjo:leading-relaxed rjo:text-destructive'>
        {children}
    </p>
);

const Note = ({ children }: { children: ReactNode }) => (
    <span className='rjo:text-xs rjo:leading-relaxed rjo:text-foreground/70'>{children}</span>
);

interface ClientDialogProps {
    /** null creates a client; otherwise that client is edited. */
    client: OAuthClient | null;
    onClose: () => void;
    /** Receives a new confidential client's secret. The screen shows it once and keeps it nowhere else. */
    onIssued: (secret: string, clientName: string) => void;
}

export default function ClientDialog({ client, onClose, onIssued }: ClientDialogProps) {
    const queryClient = useQueryClient();
    const formId = useId();
    const isEdit = client !== null;
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const form = useAppForm({
        defaultValues: client ? toForm(client) : emptyForm,
        validators: {
            // Saving happens here so that what the server rejects lands under the field it is about.
            onSubmitAsync: async ({ value }) => {
                setFormError(null);
                setSaving(true);

                try {
                    if (client === null) {
                        const secret = await createClient(toCreate(value));

                        if (secret !== null) {
                            onIssued(secret, value.name.trim());
                        }
                    } else {
                        await updateClient(client.id, toUpdate(value));
                    }

                    return;
                } catch (cause) {
                    const errors = readServerErrors(cause, httpErrorToHuman(cause));

                    setFormError(errors.form);

                    return { form: errors.form ?? 'Some fields need another look.', fields: errors.fields };
                } finally {
                    setSaving(false);
                }
            },
        },
        onSubmit: async () => {
            await queryClient.invalidateQueries({ queryKey: CLIENTS_QUERY_KEY });
            toast.success(isEdit ? 'OAuth app saved.' : 'OAuth app created.');
            onClose();
        },
    });

    return (
        <Dialog
            open
            onClose={onClose}
            preventExternalClose={saving}
            title={isEdit ? 'Edit OAuth app' : 'New OAuth app'}
        >
            <Form form={form} id={formId} className='rjo:m-0'>
                <div className='rjo:grid rjo:gap-5'>
                    {formError && <Alert type='danger'>{formError}</Alert>}

                    <form.AppField name='name' validators={{ onChange: ({ value }) => validateName(value) }}>
                        {(field) => (
                            <field.TextField
                                type='text'
                                label='Name'
                                placeholder='Billing portal'
                                maxLength={191}
                                autoComplete='off'
                                autoFocus={!isEdit}
                            />
                        )}
                    </form.AppField>

                    <form.AppField
                        name='redirect_uris'
                        validators={{ onBlur: ({ value }) => validateRedirectUris(value) }}
                    >
                        {(field) => (
                            <field.TextAreaField
                                label='Redirect URIs'
                                placeholder='https://app.example.com/oauth/callback'
                                rows={4}
                                spellCheck={false}
                                description='One per line. After approval, users are sent back to one of these exact addresses.'
                            />
                        )}
                    </form.AppField>

                    {client === null ? (
                        <form.AppField name='confidential'>
                            {(field) => (
                                <div className='rjo:grid rjo:gap-1'>
                                    <label className='rjo:flex rjo:cursor-pointer rjo:items-start rjo:gap-3'>
                                        <Checkbox
                                            checked={field.state.value}
                                            onChange={(checked) => field.handleChange(checked)}
                                        />
                                        <span className='rjo:grid rjo:gap-0.5 rjo:text-sm'>
                                            <span className='rjo:font-medium rjo:text-foreground'>
                                                Confidential client
                                            </span>
                                            <Note>
                                                The app can keep a secret, such as a server-side app. The secret is
                                                shown once after creation. Turn this off for browser or mobile apps,
                                                which get no secret.
                                            </Note>
                                        </span>
                                    </label>
                                    {firstError(field.state.meta.errors) && (
                                        <FieldError>{firstError(field.state.meta.errors)}</FieldError>
                                    )}
                                </div>
                            )}
                        </form.AppField>
                    ) : (
                        <div className='rjo:grid rjo:gap-1 rjo:text-sm'>
                            <Label>Client type</Label>
                            <p className='rjo:text-foreground'>
                                {client.confidential ? 'Confidential (has a secret)' : 'Public (no secret)'}
                            </p>
                            <Note>The client type is set when the app is created and cannot be changed.</Note>
                        </div>
                    )}

                    <form.AppField name='skip_authorization'>
                        {(field) => (
                            <div className='rjo:grid rjo:gap-2'>
                                <label className='rjo:flex rjo:cursor-pointer rjo:items-start rjo:gap-3'>
                                    <Checkbox
                                        checked={field.state.value}
                                        onChange={(checked) => field.handleChange(checked)}
                                    />
                                    <span className='rjo:grid rjo:gap-0.5 rjo:text-sm'>
                                        <span className='rjo:font-medium rjo:text-foreground'>
                                            Skip the consent screen
                                        </span>
                                        <Note>Users are not asked to approve this app before it gets access.</Note>
                                    </span>
                                </label>
                                {field.state.value && (
                                    <Alert type='warning'>
                                        Only skip consent for first-party apps that you build and run yourself. Users
                                        who sign in with a skipped app are not shown what it is asking for.
                                    </Alert>
                                )}
                                {firstError(field.state.meta.errors) && (
                                    <FieldError>{firstError(field.state.meta.errors)}</FieldError>
                                )}
                            </div>
                        )}
                    </form.AppField>
                </div>
            </Form>

            <Dialog.Footer>
                <Button.Text type='button' disabled={saving} onClick={onClose}>
                    Cancel
                </Button.Text>
                <form.AppForm>
                    <form.SubmitButton form={formId}>{isEdit ? 'Save changes' : 'Create app'}</form.SubmitButton>
                </form.AppForm>
            </Dialog.Footer>
        </Dialog>
    );
}
