import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, Form, httpErrorToHuman, toast, useAppForm } from '@pterodactyl/sdk';
import { fetchRegistries, registriesQueryKey } from '../registries/api';
import {
    SOURCE_TYPES,
    createSource,
    sourcesQueryKey,
    updateSource,
    type EggSourceInput,
    type EggSourceRecord,
    type EggSourceType,
} from './api';

interface FormState {
    name: string;
    type: EggSourceType;
    base_url: string;
    repository: string;
    branch: string;
    path: string;
    token: string;
    clear_token: boolean;
    tag: string;
    docker_registry_id: string;
}

type FieldName = keyof FormState;

interface SubmitErrors {
    form: string;
    fields: Partial<Record<FieldName, string>>;
}

interface Props {
    /** The source being edited, or null to create one. */
    source: EggSourceRecord | null;
    onClose: () => void;
}

const FIELD_NAMES: readonly FieldName[] = [
    'name',
    'type',
    'base_url',
    'repository',
    'branch',
    'path',
    'token',
    'clear_token',
    'tag',
    'docker_registry_id',
];

const blankToNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

const toForm = (source: EggSourceRecord | null): FormState => ({
    name: source?.name ?? '',
    type: source?.type ?? 'github',
    base_url: source?.base_url ?? '',
    repository: source?.repository ?? '',
    branch: source?.branch ?? 'main',
    path: source?.path ?? '',
    // The stored token is never sent to the browser, so the field always starts empty.
    token: '',
    clear_token: false,
    tag: source?.tag ?? '',
    docker_registry_id: source?.docker_registry_id ? String(source.docker_registry_id) : '',
});

/** The provider, API base URL and, for single-file sources, the URL host: a stored token belongs to exactly these. */
const destinationOf = (type: EggSourceType, baseUrl: string, path: string): string => {
    // The origin (scheme, host and port) is what a token is bound to: another port is another destination.
    let host = '';
    if (type === 'url') {
        try {
            host = new URL(path.trim()).origin.toLowerCase();
        } catch {
            host = '';
        }
    }

    return JSON.stringify([type, baseUrl.trim().replace(/\/+$/, ''), host]);
};

/** Checks the form before it is sent, so the admin sees what to fix without a round trip. */
const clientErrors = (values: FormState, original: EggSourceRecord | null): Partial<Record<FieldName, string>> => {
    const errors: Partial<Record<FieldName, string>> = {};
    const repository = values.repository.trim();

    if (values.name.trim() === '') errors.name = 'Give the source a name.';

    if (values.type === 'github') {
        if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repository)) errors.repository = 'Write the repository as owner/repo.';
    }

    if (values.type === 'gitlab') {
        if (!/^[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+)+$/.test(repository)) {
            errors.repository = 'Write the project as group/project, optionally with subgroups.';
        }
    }

    if (values.type !== 'url') {
        if (values.branch.trim() === '' || /[\s?#]|\.\./.test(values.branch)) {
            errors.branch = 'Enter a branch without spaces, "?", "#" or "..".';
        }
    }

    if (values.type === 'url') {
        const url = values.path.trim();
        if (!/^https?:\/\/\S+$/i.test(url)) {
            errors.path = 'The URL must be a full http or https address to one egg JSON file.';
        } else if (/^[a-z]+:\/\/[^/]*@/i.test(url)) {
            errors.path = 'The URL must not contain a user name or password.';
        } else if (/[?&](private_)?token=|access_token=/i.test(url)) {
            errors.path = 'Do not put tokens in the URL. Use the access token field instead.';
        }
    }

    const address = values.type === 'url' ? values.path.trim() : values.base_url.trim();
    const willHaveToken = values.token.trim() !== '' || (original?.has_token === true && !values.clear_token);
    if (willHaveToken && /^http:\/\//i.test(address)) {
        errors[values.type === 'url' ? 'path' : 'base_url'] = 'Access tokens can only be used with https:// addresses.';
    }

    // A stored token is only sent to the place it was entered for, so a new place needs it again.
    if (
        original !== null &&
        original.has_token &&
        !values.clear_token &&
        values.token.trim() === '' &&
        destinationOf(values.type, values.base_url, values.path) !== destinationOf(original.type, original.base_url ?? '', original.path ?? '')
    ) {
        errors.token = 'Re-enter the access token (or tick remove) when changing the source location.';
    }

    return errors;
};

/** The first message per field of a 422 response, keyed by the field it is about. */
const validationMessages = (cause: unknown): Partial<Record<FieldName, string>> => {
    const response = (cause as { response?: { status?: number; data?: { errors?: unknown } } } | null)?.response;
    const list = response?.status === 422 && Array.isArray(response.data?.errors) ? (response.data.errors as unknown[]) : [];
    const fields: Partial<Record<FieldName, string>> = {};

    for (const item of list) {
        const detail = (item as { detail?: unknown }).detail;
        const source = (item as { meta?: { source_field?: unknown } }).meta?.source_field;
        const name = FIELD_NAMES.find((candidate) => candidate === source);
        if (name && typeof detail === 'string' && fields[name] === undefined) fields[name] = detail;
    }

    return fields;
};

const serverErrors = (cause: unknown): SubmitErrors => {
    const fields = validationMessages(cause);

    return {
        form: Object.keys(fields).length > 0 ? 'Some fields need another look.' : httpErrorToHuman(cause),
        fields,
    };
};

const toInput = (value: FormState): EggSourceInput => {
    const isUrl = value.type === 'url';

    return {
        name: value.name.trim(),
        type: value.type,
        base_url: isUrl ? null : (blankToNull(value.base_url)?.replace(/\/+$/, '') ?? null),
        repository: isUrl ? null : blankToNull(value.repository),
        branch: isUrl ? null : blankToNull(value.branch),
        path: blankToNull(value.path),
        token: blankToNull(value.token),
        clear_token: value.clear_token,
        tag: blankToNull(value.tag),
        docker_registry_id: value.docker_registry_id === '' ? null : Number(value.docker_registry_id),
    };
};

export function SourceDialog({ source, onClose }: Props) {
    const queryClient = useQueryClient();
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const isNew = source === null;
    const formId = `ramjet-eggs-source-${source?.id ?? 'new'}`;

    // The registries screen's query, so creating or editing a registry refreshes this list too.
    const registries = useQuery({ queryKey: registriesQueryKey, queryFn: fetchRegistries });

    const form = useAppForm({
        defaultValues: toForm(source),
        validators: {
            onSubmitAsync: async ({ value }) => {
                setFormError(null);

                const fields = clientErrors(value, source);
                if (Object.keys(fields).length > 0) {
                    return { form: 'Some fields need another look.', fields };
                }

                setSaving(true);
                try {
                    if (source === null) {
                        await createSource(toInput(value));
                    } else {
                        await updateSource(source.id, toInput(value));
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
            await queryClient.invalidateQueries({ queryKey: sourcesQueryKey });
            toast.success(isNew ? 'Egg source created.' : 'Egg source saved.');
            onClose();
        },
    });

    const registryOptions = [
        { value: '', label: 'No registry' },
        ...(registries.data ?? []).map((registry) => ({ value: String(registry.id), label: `${registry.name} (${registry.registry})` })),
    ];

    return (
        <Dialog
            open
            onClose={onClose}
            preventExternalClose={saving}
            title={isNew ? 'New egg source' : 'Edit egg source'}
            description={
                'Eggs are imported from JSON files at this location and kept up to date every hour. Files already imported are updated in place.'
            }
        >
            <Form form={form} id={formId} className={'rjeggs:m-0 rjeggs:grid rjeggs:gap-4'}>
                {formError && <Alert type={'danger'}>{formError}</Alert>}

                <form.AppField name={'name'}>
                    {(field) => <field.TextField type={'text'} label={'Name'} maxLength={191} autoComplete={'off'} autoFocus />}
                </form.AppField>

                <form.AppField name={'type'}>
                    {(field) => (
                        <field.SelectField
                            label={'Source'}
                            options={SOURCE_TYPES.map((option) => ({ value: option.value, label: option.label }))}
                            onChange={(value) => field.handleChange(String(value) as EggSourceType)}
                        />
                    )}
                </form.AppField>

                <form.Subscribe selector={(state) => state.values.type}>
                    {(type) =>
                        type === 'url' ? (
                            <form.AppField name={'path'}>
                                {(field) => (
                                    <field.TextField
                                        type={'url'}
                                        label={'URL of the egg file'}
                                        description={'A full https:// address to one egg JSON file.'}
                                        autoComplete={'off'}
                                        spellCheck={false}
                                    />
                                )}
                            </form.AppField>
                        ) : (
                            <>
                                <form.AppField name={'base_url'}>
                                    {(field) => (
                                        <field.TextField
                                            type={'url'}
                                            label={'API base URL (optional)'}
                                            placeholder={type === 'gitlab' ? 'https://gitlab.example.com' : 'https://api.github.com'}
                                            description={'Only for self-hosted GitLab or GitHub Enterprise. Use https:// when a token is stored.'}
                                            autoComplete={'off'}
                                        />
                                    )}
                                </form.AppField>

                                <form.AppField name={'repository'}>
                                    {(field) => (
                                        <field.TextField
                                            type={'text'}
                                            label={type === 'gitlab' ? 'Project path' : 'Repository'}
                                            placeholder={type === 'gitlab' ? 'group/subgroup/project' : 'owner/repo'}
                                            autoComplete={'off'}
                                            spellCheck={false}
                                        />
                                    )}
                                </form.AppField>

                                <form.AppField name={'branch'}>
                                    {(field) => <field.TextField type={'text'} label={'Branch'} autoComplete={'off'} spellCheck={false} />}
                                </form.AppField>

                                <form.AppField name={'path'}>
                                    {(field) => (
                                        <field.TextField
                                            type={'text'}
                                            label={'Directory (optional)'}
                                            placeholder={'eggs/minecraft'}
                                            description={'Every *.json file below this directory is considered. Leave empty for the whole repository.'}
                                            autoComplete={'off'}
                                            spellCheck={false}
                                        />
                                    )}
                                </form.AppField>
                            </>
                        )
                    }
                </form.Subscribe>

                <form.AppField name={'token'}>
                    {(field) => (
                        <field.TextField
                            type={'password'}
                            label={'Access token (optional)'}
                            description={
                                source?.has_token
                                    ? 'A token is stored. Leave blank to keep it, or enter a new one.'
                                    : 'For private repositories: a read-only token. Stored encrypted and never shown again.'
                            }
                            autoComplete={'new-password'}
                        />
                    )}
                </form.AppField>

                {source?.has_token && <form.AppField name={'clear_token'}>{(field) => <field.SwitchField label={'Remove the stored token'} />}</form.AppField>}

                <form.AppField name={'tag'}>
                    {(field) => (
                        <field.TextField
                            type={'text'}
                            label={'Tag for imported eggs (optional)'}
                            placeholder={'minecraft'}
                            description={'Attached to every egg this source imports. The tag is created when it does not exist.'}
                            maxLength={191}
                            autoComplete={'off'}
                        />
                    )}
                </form.AppField>

                <form.AppField name={'docker_registry_id'}>
                    {(field) => (
                        <field.SelectField
                            label={'Docker registry'}
                            description={'Assigned to every egg this source imports.'}
                            options={registryOptions}
                            onChange={(value) => field.handleChange(String(value))}
                        />
                    )}
                </form.AppField>
            </Form>

            <Dialog.Footer>
                <Button.Text type={'button'} disabled={saving} onClick={onClose}>
                    Cancel
                </Button.Text>
                <form.AppForm>
                    <form.SubmitButton form={formId}>{isNew ? 'Create source' : 'Save changes'}</form.SubmitButton>
                </form.AppForm>
            </Dialog.Footer>
        </Dialog>
    );
}
