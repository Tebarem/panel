<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The create endpoint takes no parameters that shape the server. The only accepted field is an optional confirm
 * flag, which must be true when sent.
 */
final class CreateProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'confirm' => ['sometimes', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unexpected = array_values(array_diff(array_keys($this->all()), ['confirm']));
            if ($unexpected !== []) {
                $validator->errors()->add('payload', 'Only the optional confirm flag may be sent. Remove: '.implode(', ', $unexpected).'.');
            }
        });
    }
}
