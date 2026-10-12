<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Runs N PHP processes truly in parallel.
 *
 * Why processes and not threads/fibers: PHP has no shared-nothing concurrency,
 * and a single PDO connection serialises everything at the client anyway. The
 * only way to actually race two transactions against InnoDB from PHP is two
 * OS processes.
 *
 * Every worker is started first and then blocks on a shared wall-clock
 * barrier, so they all reach the database within a few hundred microseconds of
 * each other instead of trickling in behind process startup cost.
 */
final class ProcessPool
{
    /**
     * @param list<list<string>> $commands argv arrays (PHP_BINARY plus script plus args)
     * @param float              $leadTime head start given to process startup, in seconds
     * @return list<array{stdout: string, stderr: string, exit: int, json: array<string, mixed>|null}>
     */
    public static function runParallel(array $commands, float $leadTime = 2.5): array
    {
        $barrier = microtime(true) + $leadTime;
        $running = [];

        foreach ($commands as $index => $argv) {
            $argv[] = sprintf('%.6F', $barrier);

            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($argv, $descriptors, $pipes);

            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start worker: ' . implode(' ', $argv));
            }

            $running[$index] = ['process' => $process, 'pipes' => $pipes];
        }

        $results = [];

        foreach ($running as $index => $entry) {
            $stdout = (string) stream_get_contents($entry['pipes'][1]);
            $stderr = (string) stream_get_contents($entry['pipes'][2]);

            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);

            $exit = proc_close($entry['process']);

            $decoded = json_decode(trim($stdout), true);

            $results[$index] = [
                'stdout' => trim($stdout),
                'stderr' => trim($stderr),
                'exit'   => $exit,
                'json'   => is_array($decoded) ? $decoded : null,
            ];
        }

        return $results;
    }

    /**
     * Build the argv for a worker script that is started with the same PHP
     * binary (and therefore the same loaded extensions) as the test process.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    public static function worker(string $script, array $arguments = []): array
    {
        return array_merge([PHP_BINARY, $script], $arguments);
    }
}
