<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

/**
 * Integration base for the extension: every test runs in a transaction that is rolled back afterwards, so the
 * extension settings and proxy rows one test writes cannot reach the next one.
 */
abstract class ProxyTestCase extends IntegrationTestCase
{
    use DatabaseTransactions;
}
