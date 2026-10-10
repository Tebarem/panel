<?php

namespace Pterodactyl\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\EggSource;
use Illuminate\Contracts\Validation\Validator;

class EggSourceFormRequest extends AdminFormRequest
{
    /**
     * Rules for creating or updating an egg source. The access token is write-only: it is only
     * validated here, and is never returned to the form.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'type' => ['required', 'string', Rule::in(EggSource::TYPES)],
            'base_url' => [
                'nullable',
                'string',
                'max:191',
                'regex:/^https?:\/\/[^\s]+$/i',
                'not_regex:/^[a-z]+:\/\/[^\/]*@/i',
            ],
            'repository' => 'nullable|string|max:191',
            'branch' => ['nullable', 'string', 'max:191', 'not_regex:/[\s?#]|\.\./'],
            'path' => 'nullable|string|max:500',
            'token' => 'nullable|string|max:1024',
            'clear_token' => 'sometimes|boolean',
            'nest_id' => 'required|integer|exists:nests,id',
            'docker_registry_id' => 'nullable|integer|exists:docker_registries,id',
        ];
    }

    /**
     * Checks that depend on the source type and on whether a token will be stored. These are checked
     * manually because "nullable" rules would otherwise skip the "required" check for empty values.
     */
    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('type');
            $repository = (string) $this->input('repository');

            if (in_array($type, [EggSource::TYPE_GITHUB, EggSource::TYPE_GITLAB], true)) {
                if (blank($repository)) {
                    $validator->errors()->add('repository', 'A repository is required for GitHub and GitLab sources.');
                } elseif ($type === EggSource::TYPE_GITHUB && !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository)) {
                    $validator->errors()->add('repository', 'GitHub repositories must be written as owner/repo.');
                } elseif ($type === EggSource::TYPE_GITLAB && !preg_match('/^[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)+$/D', $repository)) {
                    $validator->errors()->add('repository', 'GitLab projects must be written as group/project, optionally with subgroups.');
                }

                if (blank($this->input('branch'))) {
                    $validator->errors()->add('branch', 'A branch is required for GitHub and GitLab sources.');
                }
            }

            if ($type === EggSource::TYPE_URL) {
                $url = (string) $this->input('path');
                if (blank($url) || !preg_match('/^https?:\/\/[^\s]+$/i', $url)) {
                    $validator->errors()->add('path', 'The URL must be a full http or https address to a single egg JSON file.');
                } elseif (preg_match('/^[a-z]+:\/\/[^\/]*@/i', $url)) {
                    $validator->errors()->add('path', 'The URL must not contain a username or password.');
                } elseif (preg_match('/[?&](private_)?token=|access_token=/i', $url)) {
                    $validator->errors()->add('path', 'Do not put tokens in the URL. Use the access token field instead.');
                }
            }

            // Tokens are only sent over HTTPS. Plain HTTP is allowed only when no token is stored.
            $insecure = ($type === EggSource::TYPE_URL)
                ? preg_match('/^http:\/\//i', (string) $this->input('path'))
                : preg_match('/^http:\/\//i', (string) $this->input('base_url'));

            if ($insecure && $this->tokenWillBeStored()) {
                $validator->errors()->add(
                    $type === EggSource::TYPE_URL ? 'path' : 'base_url',
                    'Access tokens can only be used with https:// addresses.',
                );
            }
        });
    }

    /**
     * Returns true if the source will have an access token once this request is saved.
     */
    protected function tokenWillBeStored(): bool
    {
        if (filled($this->input('token'))) {
            return true;
        }

        /** @var EggSource|null $source */
        $source = $this->route('source');

        return !is_null($source) && $source->hasToken() && !$this->boolean('clear_token');
    }
}
