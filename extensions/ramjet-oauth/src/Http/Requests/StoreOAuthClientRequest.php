<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Http\Requests;

class StoreOAuthClientRequest extends OAuthClientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->commonRules(), [
            'confidential' => ['nullable', 'boolean'],
        ]);
    }

    public function isConfidential(): bool
    {
        return $this->boolean('confidential');
    }
}
