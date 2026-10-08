<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but the test database, before RefreshDatabase runs.
     *
     * A cached config (bootstrap/cache/config.php) makes Laravel ignore phpunit.xml's
     * DB_* variables, so RefreshDatabase's migrate:fresh would drop every table in the
     * real development database. This runs in setUpTraits(), i.e. before any trait such
     * as RefreshDatabase touches the database; a check in setUp() after parent::setUp()
     * would be too late.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $expected = $_ENV['DB_DATABASE'] ?? (getenv('DB_DATABASE') ?: null);

        $isCached = $this->app->configurationIsCached();

        if ($isCached || ($expected !== null && $database !== $expected)) {
            throw new RuntimeException(
                "Refusing to run tests: the [{$connection}] connection resolved to database [{$database}], expected [{$expected}]".
                ($isCached ? ' and the config is cached. ' : '. ').
                'Run `php artisan config:clear` (bootstrap/cache/config.php overrides phpunit.xml) and try again.'
            );
        }

        return parent::setUpTraits();
    }
}
