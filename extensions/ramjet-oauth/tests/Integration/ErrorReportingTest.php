<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Integration;

use Illuminate\Contracts\Debug\ExceptionHandler;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueOAuthServerException;
use Ramjet\OAuth\Tests\OAuthTestCase;
use RuntimeException;

class ErrorReportingTest extends OAuthTestCase
{
    public function test_client_errors_are_not_reported(): void
    {
        $handler = app(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(new LeagueOAuthServerException('Invalid client.', 4, 'invalid_client', 401)));
        $this->assertFalse($handler->shouldReport(new LeagueOAuthServerException('Invalid grant.', 8, 'invalid_grant', 400)));
    }

    public function test_server_errors_are_still_reported(): void
    {
        $handler = app(ExceptionHandler::class);

        $this->assertTrue($handler->shouldReport(new LeagueOAuthServerException('Server failure.', 0, 'server_error', 500)));
    }

    public function test_other_exceptions_are_reported_as_before(): void
    {
        $this->assertTrue(app(ExceptionHandler::class)->shouldReport(new RuntimeException('Unrelated failure.')));
    }
}
