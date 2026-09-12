<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/global_helpers.php';

// Modules\SplmWaitlist\Services\Support\LaravelOptionStore calls
// FreeScout's real \Option facade directly (no constructor injection
// point), so the in-memory fake in Tests/Support needs to be reachable
// at that exact global class name.
class_alias(\Tests\Support\FakeOption::class, 'Option');
