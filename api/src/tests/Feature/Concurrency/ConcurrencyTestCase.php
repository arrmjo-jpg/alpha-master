<?php

namespace Tests\Feature\Concurrency;

use App\Modules\AccessControl\Services\PermissionSynchronizer;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real concurrency on MySQL: a second PHP process runs an actual application
 * action (tests/Support/concurrent-runner.php) while this process runs another.
 * Both commit for real, so these tests truncate instead of rolling back.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionSynchronizer::class)->sync();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        // Leave a clean, seeded schema for the transactional tests that follow.
        $this->artisan('migrate:fresh', ['--seed' => true]);

        parent::tearDown();
    }

    protected function tempPath(string $name): string
    {
        return $this->tempFiles[] = sys_get_temp_dir().'/alphamaster-'.$name.'-'.bin2hex(random_bytes(6));
    }

    /**
     * Starts the runner and blocks until it has paused inside its transaction.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, string>  $env
     */
    protected function startPausedAction(array $job, array $env = []): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-runner.php'), json_encode($job)], base_path(), $env + getenv(), null, 60);
        $process->start();

        $deadline = microtime(true) + 20;
        while (! file_exists($job['marker'])) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('Runner did not reach its pause point: '.$process->getOutput().$process->getErrorOutput());
            }
            usleep(20_000);
        }

        return $process;
    }

    /** @return array<string, mixed> */
    protected function finish(Process $process): array
    {
        $process->wait();
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        return json_decode(end($lines) ?: '{}', true) ?? ['ok' => false, 'error' => 'no output', 'stderr' => $process->getErrorOutput()];
    }
}
