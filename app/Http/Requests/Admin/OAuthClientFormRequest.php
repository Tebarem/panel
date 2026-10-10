<?php

namespace Pterodactyl\Http\Requests\Admin;

class OAuthClientFormRequest extends AdminFormRequest
{
    /**
     * Schemes that are never acceptable as a redirect target.
     */
    private const BLOCKED_SCHEMES = ['javascript', 'data', 'file', 'vbscript'];

    /**
     * Hosts that may use plain HTTP (loopback, for development and native apps).
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Set up the validation rules to use for these requests.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'redirect_uris' => 'required|string|max:4000',
            'confidential' => 'nullable|boolean',
            'skip_authorization' => 'nullable|boolean',
        ];
    }

    /**
     * @return string[] redirect URIs from the textarea, one per line
     */
    public function redirectUris(): array
    {
        $lines = preg_split('/\R/', (string) $this->input('redirect_uris')) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $lines), fn (string $line) => $line !== '')));
    }

    /**
     * Untyped parameter on purpose: it stays compatible with FormRequest::withValidator() whatever its signature.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $uris = $this->redirectUris();

            if (count($uris) === 0) {
                $validator->errors()->add('redirect_uris', 'At least one redirect URI is required.');

                return;
            }

            foreach ($uris as $uri) {
                if (!$this->isValidRedirectUri($uri)) {
                    $validator->errors()->add('redirect_uris', "The redirect URI \"{$uri}\" is not allowed. Use https:// URLs (http:// is only allowed for localhost), or a custom scheme for native apps. Fragments are not allowed.");

                    return;
                }
            }
        });
    }

    private function isValidRedirectUri(string $uri): bool
    {
        // No whitespace, control characters or backslashes.
        if (preg_match('/[^\x21-\x7E]/', $uri) === 1 || str_contains($uri, '\\')) {
            return false;
        }

        // OAuth redirect URIs must not contain a fragment.
        if (str_contains($uri, '#')) {
            return false;
        }

        // Must be an absolute URI with a scheme.
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $uri, $matches) !== 1) {
            return false;
        }

        $scheme = strtolower($matches[1]);
        if (in_array($scheme, self::BLOCKED_SCHEMES, true)) {
            return false;
        }

        if ($scheme === 'https') {
            return !empty(parse_url($uri, PHP_URL_HOST));
        }

        if ($scheme === 'http') {
            $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

            return in_array($host, self::LOOPBACK_HOSTS, true);
        }

        // Custom schemes (for example "com.example.app:/callback") are used by native applications.
        return true;
    }
}
