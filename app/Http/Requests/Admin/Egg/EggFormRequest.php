<?php

namespace Pterodactyl\Http\Requests\Admin\Egg;

use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class EggFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'docker_images' => ['required', 'string', 'regex:/^[\w#\.\/\- ]*\|?~?[\w\.\/\-:@ ]*$/im'],
            'force_outgoing_ip' => 'sometimes|boolean',
            // Submitted as newline-separated textareas, converted to arrays in validated().
            'file_denylist' => 'sometimes|nullable|string|max:65535',
            'file_hidden' => 'sometimes|nullable|string|max:65535',
            'docker_registry_id' => 'sometimes|nullable|numeric|exists:docker_registries,id',
            'features' => 'sometimes|array',
            'startup' => 'required|string',
            'config_from' => 'sometimes|bail|nullable|numeric',
            'config_stop' => 'required_without:config_from|nullable|string|max:191',
            'config_startup' => 'required_without:config_from|nullable|json',
            'config_logs' => 'required_without:config_from|nullable|json',
            'config_files' => 'required_without:config_from|nullable|json',
        ];

        if ($this->method() === 'POST') {
            $rules['nest_id'] = 'required|numeric|exists:nests,id';
        }

        return $rules;
    }

    public function withValidator($validator)
    {
        $validator->sometimes('config_from', 'exists:eggs,id', function () {
            return (int) $this->input('config_from') !== 0;
        });
    }

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();

        foreach (['file_denylist', 'file_hidden'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = self::parsePatterns($data[$field]);
            }
        }

        // An empty select means "no registry", which is stored as NULL rather than 0 or "".
        if (array_key_exists('docker_registry_id', $data)) {
            $data['docker_registry_id'] = $data['docker_registry_id'] ?: null;
        }

        return array_merge($data, [
            'force_outgoing_ip' => array_get($data, 'force_outgoing_ip', false),
            'features' => array_get($data, 'features', []),
        ]);
    }

    /**
     * Converts a newline separated list of file patterns into an array of unique, non-empty
     * patterns with surrounding whitespace removed.
     *
     * @return string[]
     */
    public static function parsePatterns(?string $input): array
    {
        $lines = preg_split('/\R/', (string) $input) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $lines), fn ($line) => $line !== '')));
    }
}
