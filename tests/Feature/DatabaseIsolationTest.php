<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_phpunit_database_connections_are_isolated(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', config('webpush.database_connection'));
    }
}
