import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, Form, httpErrorToHuman, toast, useAppForm } from '@pterodactyl/sdk';
import {
    createRegistry,
    registriesQueryKey,
    updateRegistry,
    type DockerRegistry,
    type RegistryInput,
} from './api';
import { isWholeHost, normalizeRegistry, registryProblem } from './validation';

interface FormState {
    name: string;
    registry: string;
    username: string;
    password: string;
}

type FieldName = keyof FormState;

interface SubmitErrors {
    form: string;
    fields: Partial<Record<FieldName, string>>;
}

interface Props {
    /** The registry being edited, or null to create one. */
    registry: DockerRegistry | null;
    onClose: () => void;
}

const toForm = (registry: DockerRegistry | null): FormState => ({
    name: registry?.name ?? '',
    registry: registry?.registry ?? '',
    username: registry?.username ?? '',
    // The stored password is never sent to the browser, so the field always starts empty.
    password: '',
});

/** Checks the form before it is sent, so the admin sees what to fix without a round trip. */
const clientErrors = (values: FormState, original: DockerRegistry | null): Partial<Record<FieldName, string>> => {
    const errors: Partial<Record<FieldName, string>> = {};
    const hostProblem = registryProblem(values.registry);

    if (values.name.trim() === '') errors.name = 'Give the registry a name.';
    if (hostProblem) errors.registry = hostProblem;
    if (values.username.trim() === '') errors.username = 'Enter the username the registry expects.';

    if (original === null && values.password === '') {
        errors.password = 'Enter the password or access token.';
    }

    // The stored password only goes to the host it was entered for, so a new host needs it again.
    if (original !== null && normalizeRegistry(values.registry) !== original.registry && values.password === '') {
        errors.password = 'Re-enter the password or token when changing the registry.';
    }

    return errors;
};

const FIELD_NAMES: readonly FieldName[] = ['name', 'registry', 'username', 'password'];

/** The first message per field of a 422 response, keyed by the field it is about. */
const validationMessages = (cause: unknown): Partial<Record<FieldName, string>> => {
    const errors = (cause as { response?: { status?: number; data?: { errors?: unknown } } } | null)?.response;
    const list = errors?.status === 422 && Array.isArray(errors.data?.errors) ? (errors.data.errors as unknown[]) : [];
    const fields: Partial<Record<FieldName, string>> = {};

    for (const item of list) {
        const detail = (item as { detail?: unknown }).detail;
        const source = (item as { meta?: { source_field?: unknown } }).meta?.source_field;
        const name = FIELD_NAMES.find((candidate) => candidate === source);
        if (name && typeof detail === 'string' && fields[name] === undefined) fields[name] = detail;
    }

    return fields;
};

/** Field messages for a failed save, or a message for everything else. */
const serverErrors = (cause: unknown): SubmitErrors => {
    const fields = validationMessages(cause);

    return {
        form: Object.keys(fields).length > 0 ? 'Some fields need another look.' : httpErrorToHuman(cause),
        fields,
    };
};

export function RegistryDialog({ registry, onClose }: Props) {
    const queryClient = useQueryClient();
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const formId = `ramjet-eggs-registry-${registry?.id ?? 'new'}`;
    const isNew = registry === null;

    const form = useAppForm({
        defaultValues: toForm(registry),
        validators: {
            onSubmitAsync: async ({ value }) => {
                setFormError(null);

                const fields = clientErrors(value, registry);
                if (Object.keys(fields).length > 0) {
                    return { form: 'Some fields need another look.', fields };
                }

                const input: RegistryInput = {
                    name: value.name.trim(),
                    registry: normalizeRegistry(value.registry),
                    username: value.username.trim(),
                    password: value.password === '' ? null : value.password,
                };

                setSaving(true);
                try {
                    if (registry === null) {
                        await createRegistry(input);
                    } else {
                        await updateRegistry(registry.id, input);
                    }

                    return undefined;
                } catch (cause) {
                    const errors = serverErrors(cause);
                    setFormError(errors.form);

                    return errors;
                } finally {
                    setSaving(false);
                }
            },
        },
        onSubmit: async () => {
            await queryClient.invalidateQueries({ queryKey: registriesQueryKey });
            toast.success(isNew ? 'Registry created.' : 'Registry saved.');
            onClose();
        },
    });

    return (
        <Dialog
            open
            onClose={onClose}
            preventExternalClose={saving}
            title={isNew ? 'New Docker registry' : 'Edit Docker registry'}
            description={
                'Credentials Wings uses to pull the images of the eggs assigned to this registry. The password is encrypted and never shown again.'
            }
        >
            <Form form={form} id={formId} className={'rjeggs:m-0 rjeggs:grid rjeggs:gap-4'}>
                {formError && <Alert type={'danger'}>{formError}</Alert>}

                <form.AppField name={'name'}>
                    {(field) => (
                        <field.TextField
                            type={'text'}
                            label={'Name'}
                            placeholder={'GitHub Container Registry'}
                            maxLength={191}
                            autoComplete={'off'}
                            autoFocus
                        />
                    )}
                </form.AppField>

                <form.AppField name={'registry'}>
                    {(field) => (
                        <field.TextField
                            type={'text'}
                            label={'Registry'}
                            placeholder={'ghcr.io/myorg'}
                            description={'A host, optionally with a namespace. No scheme and no trailing slash.'}
                            autoComplete={'off'}
                            autoCapitalize={'none'}
                            spellCheck={false}
                        />
                    )}
                </form.AppField>

                <form.Subscribe selector={(state) => state.values.registry}>
                    {(value) => {
                        const changed = !isNew && normalizeRegistry(value) !== registry.registry;
                        const whole = value.trim() !== '' && isWholeHost(value);

                        return (
                            <>
                                {whole && (
                                    <Alert type={'warning'}>
                                        This registry matches every image on the host, so its credentials are sent for all of
                                        them. Add a namespace such as {normalizeRegistry(value)}/myorg to limit them.
                                    </Alert>
                                )}
                                {changed && (
                                    <Alert type={'warning'}>
                                        The registry changed. Enter the password or token again: the stored one is not sent to
                                        a new host.
                                    </Alert>
                                )}
                            </>
                        );
                    }}
                </form.Subscribe>

                <form.AppField name={'username'}>
                    {(field) => <field.TextField type={'text'} label={'Username'} autoComplete={'off'} />}
                </form.AppField>

                <form.AppField name={'password'}>
                    {(field) => (
                        <field.TextField
                            type={'password'}
                            label={'Password or access token'}
                            description={
                                isNew
                                    ? 'Stored encrypted with the panel key.'
                                    : 'Leave blank to keep the stored password.'
                            }
                            autoComplete={'new-password'}
                        />
                    )}
                </form.AppField>
            </Form>

            <Dialog.Footer>
                <Button.Text type={'button'} disabled={saving} onClick={onClose}>
                    Cancel
                </Button.Text>
                <form.AppForm>
                    <form.SubmitButton form={formId}>{isNew ? 'Create registry' : 'Save changes'}</form.SubmitButton>
                </form.AppForm>
            </Dialog.Footer>
        </Dialog>
    );
}
