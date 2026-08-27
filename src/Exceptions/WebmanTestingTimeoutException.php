<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Exceptions;

use RuntimeException;

/**
 * TestCase::webmanWaitFor() 轮询超时
 */
final class WebmanTestingTimeoutException extends RuntimeException
{
}
