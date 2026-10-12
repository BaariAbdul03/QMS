<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Thrown by TestRunner assertions; caught by the runner and reported. */
final class AssertionFailed extends RuntimeException
{
}
