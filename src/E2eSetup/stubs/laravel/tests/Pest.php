<?php

declare(strict_types=1);

use Tests\TestCase;

// laravel 骨架默认 PHPUnit；使用 Pest 风格需在 require-dev 补 pestphp/pest + pestphp/pest-plugin-laravel
pest()->extend(TestCase::class)->in(__DIR__);
