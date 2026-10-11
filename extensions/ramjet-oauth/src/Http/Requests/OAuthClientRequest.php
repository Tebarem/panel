<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Ramjet\OAuth\Rules\RedirectUri;

/**
 * Shared rules for creating and updating an OAuth client. Access is enforced by the admin-api middleware group
 * (root administrators only).
 */
abstract class OAuthClientRequest extends FormRequest
{
    public const MAX_REDIRECT_URIS = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return string[]
     */
    public function redirectUris(): array
    {
        /** @var string[] $uris */
        $uris = $this->validated('redirect_uris');

        return array_values($uris);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        $uris = $this->input('redirect_uris');
        if (is_array($uris)) {
            $cleaned = [];
            foreach ($uris as $uri) {
                if (is_string($uri) && trim($uri) !== '') {
                    $cleaned[] = trim($uri);
                }
            }

            // Duplicates out, the order the administrator entered kept.
            $this->merge(['redirect_uris' => array_values(array_unique($cleaned))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:'.self::MAX_REDIRECT_URIS],
            'redirect_uris.*' => ['required', 'string', 'max:2000', new RedirectUri()],
            'skip_authorization' => ['nullable', 'boolean'],
        ];
    }
}
