<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Unit;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Ramjet\OAuth\Rules\RedirectUri;

class RedirectUriTest extends TestCase
{
    #[TestWith(['https://grafana.example.com/login/generic_oauth'])]
    #[TestWith(['https://example.com:8443/cb?x=1'])]
    #[TestWith(['http://localhost/callback'])]
    #[TestWith(['http://127.0.0.1:8080/callback'])]
    #[TestWith(['http://[::1]/callback'])]
    #[TestWith(['com.example.app:/callback'])]
    public function test_allowed_redirect_uris(string $uri): void
    {
        $this->assertTrue(RedirectUri::isAllowed($uri));
    }

    #[TestWith(['https://example.com/cb#fragment'])]
    #[TestWith(['http://example.com/callback'])]
    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['JavaScript:alert(1)'])]
    #[TestWith(['data:text/html,<script>'])]
    #[TestWith(['file:///etc/passwd'])]
    #[TestWith(['vbscript:msgbox'])]
    #[TestWith(['not a url'])]
    #[TestWith(['https://'])]
    #[TestWith(['https:\\\\evil.example.com'])]
    #[TestWith(["https://example.com/\x00"])]
    #[TestWith(['/relative/path'])]
    public function test_rejected_redirect_uris(string $uri): void
    {
        $this->assertFalse(RedirectUri::isAllowed($uri));
    }

    public function test_validation_rule_reports_failure(): void
    {
        $messages = [];
        (new RedirectUri())->validate('redirect_uris.0', 'javascript:alert(1)', function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertCount(1, $messages);
    }
}
