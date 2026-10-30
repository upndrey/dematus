<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function authenticateStatically(): static
    {
        return $this->withSession([
            config('static-auth.session_key') => true,
        ]);
    }
}
