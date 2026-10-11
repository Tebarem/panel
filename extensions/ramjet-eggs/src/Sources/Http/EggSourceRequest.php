<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources\Http;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Pterodactyl\Rules\TagSlug;
use Ramjet\Eggs\Sources\EggSource;
use Ramjet\Eggs\Sources\EggSourceClient;
use Ramjet\Eggs\Sources\EggSourceData;

/**
 * Rules for creating or updating an egg source. The access token is write-only: it is only validated here and is never
 * returned by the API. Whether a stored token may stay is decided by the controller, which knows the current source.
 */
final class EggSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The admin route group only admits root administrators (see RamjetEggsProvider).
        return true;
    }

    /**
     * @return array<string, list<string|object>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'string', Rule::in(EggSource::TYPES)],
            'base_url' => [
                'nullable',
                'string',
                'max:191',
                'regex:/^https?:\/\/[^\s]+$/i',
                'not_regex:/^[a-z]+:\/\/[^\/]*@/i',
            ],
            'repository' => ['nullable', 'string', 'max:191'],
            'branch' => ['nullable', 'string', 'max:191', 'not_regex:/[\s?#]|\.\./'],
            'path' => ['nullable', 'string', 'max:500'],
            'token' => ['nullable', 'string', 'max:1024'],
            'clear_token' => ['sometimes', 'boolean'],
            'tag' => ['nullable', 'string', 'max:191', new TagSlug()],
            'docker_registry_id' => ['nullable', 'integer', 'exists:ramjet_docker_registries,id'],
        ];
    }

    /**
     * Checks that depend on the source type. They are checked by hand because "nullable" rules would otherwise skip
     * "required" for empty values.
     *
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->string('type')->value();
            $repository = $this->string('repository')->value();
            $branch = $this->string('branch')->value();
            $path = $this->string('path')->value();
            $baseUrl = $this->string('base_url')->value();

            if ($type === EggSource::TYPE_GITHUB || $type === EggSource::TYPE_GITLAB) {
                if ($repository === '') {
                    $validator->errors()->add('repository', 'A repository is required for GitHub and GitLab sources.');
                } elseif ($type === EggSource::TYPE_GITHUB && ! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository)) {
                    $validator->errors()->add('repository', 'GitHub repositories must be written as owner/repo.');
                } elseif ($type === EggSource::TYPE_GITLAB && ! preg_match('/^[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)+$/D', $repository)) {
                    $validator->errors()->add('repository', 'GitLab projects must be written as group/project, optionally with subgroups.');
                } elseif (! EggSourceClient::isSafePath($repository)) {
                    $validator->errors()->add('repository', 'The repository must not contain "." or ".." segments.');
                }

                if ($branch === '') {
                    $validator->errors()->add('branch', 'A branch is required for GitHub and GitLab sources.');
                }
            }

            if ($type !== EggSource::TYPE_URL && $path !== '' && ! EggSourceClient::isSafePath(trim($path, '/'))) {
                $validator->errors()->add('path', 'The directory must not contain empty, "." or ".." segments.');
            }

            if ($type === EggSource::TYPE_URL) {
                if ($path === '' || ! preg_match('/^https?:\/\/[^\s]+$/i', $path)) {
                    $validator->errors()->add('path', 'The URL must be a full http or https address to a single egg JSON file.');
                } elseif (preg_match('/^[a-z]+:\/\/[^\/]*@/i', $path)) {
                    $validator->errors()->add('path', 'The URL must not contain a username or password.');
                } elseif (preg_match('/[?&](private_)?token=|access_token=/i', $path)) {
                    $validator->errors()->add('path', 'Do not put tokens in the URL. Use the access token field instead.');
                }
            }

            // A token entered now is only sent over https. A stored token is checked by the controller.
            $address = $type === EggSource::TYPE_URL ? $path : $baseUrl;
            if ($this->filled('token') && EggSourceData::isPlainHttp($address)) {
                $validator->errors()->add(
                    $type === EggSource::TYPE_URL ? 'path' : 'base_url',
                    'Access tokens can only be used with https:// addresses.',
                );
            }
        });
    }
}
