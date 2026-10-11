<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Registries\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Ramjet\Eggs\Registries\DockerRegistry;
use Ramjet\Eggs\Registries\RegistryData;

/**
 * Create and update requests for a Docker registry. The password is write-only: required when a
 * registry is created, and optional when one is updated (blank keeps the stored password).
 */
final class DockerRegistryRequest extends FormRequest
{
    /** Routes sit behind the root administrator middleware of the admin API. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = DockerRegistry::validationRules();

        return [
            'name' => $rules['name'],
            'registry' => $rules['registry'],
            'username' => $rules['username'],
            'password' => $this->isMethod('POST')
                ? ['required', 'string', 'max:1024']
                : ['nullable', 'string', 'max:1024'],
        ];
    }

    /** The validated input as the registry manager takes it; a blank password on update is null. */
    public function registryData(): RegistryData
    {
        return new RegistryData(
            name: $this->string('name')->toString(),
            registry: $this->string('registry')->toString(),
            username: $this->string('username')->toString(),
            password: $this->filled('password') ? $this->string('password')->toString() : null,
        );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->isMethod('POST') || $this->filled('password')) {
                return;
            }

            $registry = DockerRegistry::query()->whereKey($this->route('id'))->first();
            if ($registry !== null && $this->string('registry')->toString() !== $registry->registry) {
                // The stored password is only ever sent to the host it was entered for.
                $validator->errors()->add('password', 'Re-enter the password or token when changing the registry.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // Registries are compared and stored trimmed and in lower case.
        $this->merge(['registry' => $this->string('registry')->trim()->lower()->toString()]);
    }
}
