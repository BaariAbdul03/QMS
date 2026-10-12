<?php

declare(strict_types=1);

namespace Tests\Support;

use Throwable;

/**
 * A ~100 line test runner. There is no Composer in this project, so PHPUnit is
 * not an option; this keeps the suite dependency-free and readable.
 */
final class TestRunner
{
    private int $passed = 0;

    private int $skipped = 0;

    private int $assertions = 0;

    /** Set by skip(); consumed by test(). */
    private ?string $skipReason = null;

    /** @var list<string> */
    private array $failures = [];

    public function __construct(private readonly string $suite)
    {
        $this->line('');
        $this->line('== ' . $suite . ' ' . str_repeat('=', max(0, 60 - strlen($suite))));
    }

    /**
     * Mark the running test as not applicable. The body should return straight
     * after calling this; asserting something unknowable would be worse than
     * saying so out loud.
     */
    public function skip(string $reason): void
    {
        $this->skipReason = $reason;
    }

    public function test(string $name, callable $body): void
    {
        $this->skipReason = null;

        try {
            $body($this);

            if ($this->skipReason !== null) {
                $this->skipped++;
                $this->line(sprintf('  SKIP  %s', $name));
                $this->line('          ' . $this->skipReason);

                return;
            }

            $this->passed++;
            $this->line(sprintf('  ok    %s', $name));
        } catch (AssertionFailed $e) {
            $this->failures[] = $name . ': ' . $e->getMessage();
            $this->line(sprintf('  FAIL  %s', $name));
            $this->line('          ' . $e->getMessage());
        } catch (Throwable $e) {
            $this->failures[] = $name . ': ' . $e::class . ' — ' . $e->getMessage();
            $this->line(sprintf('  ERROR %s', $name));
            $this->line('          ' . $e::class . ': ' . $e->getMessage());
            $this->line('          at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    // -- assertions --------------------------------------------------------

    public function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertions++;

        if ($value !== true) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected true, got ' . $this->describe($value));
        }
    }

    public function assertFalse(mixed $value, string $message = ''): void
    {
        $this->assertions++;

        if ($value !== false) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected false, got ' . $this->describe($value));
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                '%s expected %s, got %s',
                $message !== '' ? $message . ':' : 'Assertion failed:',
                $this->describe($expected),
                $this->describe($actual),
            ));
        }
    }

    public function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertSame($expected, count($actual), $message !== '' ? $message : 'count mismatch');
    }

    public function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(sprintf(
                '%s expected to find "%s" in "%s"',
                $message !== '' ? $message . ':' : 'Assertion failed:',
                $needle,
                $haystack,
            ));
        }
    }

    /**
     * Run $body and require it to throw $expectedClass. Returns the exception so
     * the caller can inspect statusCode()/details().
     */
    public function assertThrows(string $expectedClass, callable $body, string $message = ''): Throwable
    {
        $this->assertions++;

        try {
            $body();
        } catch (Throwable $e) {
            if (!$e instanceof $expectedClass) {
                throw new AssertionFailed(sprintf(
                    '%s expected %s, got %s: %s',
                    $message !== '' ? $message . ':' : 'Assertion failed:',
                    $expectedClass,
                    $e::class,
                    $e->getMessage(),
                ));
            }

            return $e;
        }

        throw new AssertionFailed(sprintf(
            '%s expected %s but nothing was thrown',
            $message !== '' ? $message . ':' : 'Assertion failed:',
            $expectedClass,
        ));
    }

    // -- reporting ---------------------------------------------------------

    public function summary(): int
    {
        $this->line('');
        $this->line(sprintf(
            '%s: %d passed, %d failed, %d skipped (%d assertions)',
            $this->failures === [] ? 'PASS' : 'FAIL',
            $this->passed,
            count($this->failures),
            $this->skipped,
            $this->assertions,
        ));

        foreach ($this->failures as $failure) {
            $this->line('  - ' . $failure);
        }

        $this->line('');

        return $this->failures === [] ? 0 : 1;
    }

    private function line(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"' . $value . '"',
            is_bool($value)   => $value ? 'true' : 'false',
            $value === null   => 'null',
            is_scalar($value) => (string) $value,
            default           => get_debug_type($value),
        };
    }
}
