<?php

namespace Pterodactyl\Http\Requests\Admin;

use Illuminate\Support\Arr;
use Pterodactyl\Models\DockerRegistry;

class DockerRegistryFormRequest extends AdminFormRequest
{
    /**
     * Registry values are compared and stored in lower case.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('registry')) {
            $this->merge(['registry' => strtolower(trim((string) $this->input('registry')))]);
        }
    }

    /**
     * Set up the validation rules to use for these requests. The password is write-only: it is
     * required when creating a registry, and optional when updating one (blank keeps the existing
     * password).
     */
    public function rules(): array
    {
        $rules = Arr::only(DockerRegistry::getRules(), ['name', 'registry', 'username']);

        $rules['password'] = $this->method() === 'POST'
            ? ['required', 'string', 'max:1024']
            : ['nullable', 'string', 'max:1024'];

        return $rules;
    }
}
