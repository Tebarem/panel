<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Http\Requests;

class UpdateOAuthClientRequest extends OAuthClientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->commonRules();
    }
}
