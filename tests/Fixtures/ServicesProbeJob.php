<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * Queued job for the attempt isolation tests on logging, auth and Cache::memo.
 * It writes to each service, waits at a rendezvous until the other job has too,
 * then logs a real record and reads everything back.
 * See tests/Integration/AttemptIsolationTest.php.
 */
final class ServicesProbeJob implements ShouldQueue
{
    use Queueable;

    /**
     * Marks reached so far in this worker process.
     *
     * @var array<string, true>
     */
    public static array $marks = [];

    /**
     * @param  string  $tenant  This job's own tenant, written to every service.
     * @param  list<string>  $waitFor  Tenants whose jobs must have written theirs before this job goes on.
     */
    public function __construct(
        public string $tenant,
        public array $waitFor = [],
    ) {}

    public function handle(): void
    {
        $this->log('start', ['user' => Auth::id()]);

        Log::withContext(['from' => $this->tenant]);
        Log::shareContext(['shared_from' => $this->tenant]);
        Log::channel('other')->withContext(['other_from' => $this->tenant]);

        Auth::setUser(new GenericUser(['id' => $this->tenant]));

        Cache::put('memo-key', $this->tenant);
        (void) Cache::memo()->get('memo-key');

        self::$marks[$this->tenant] = true;
        $this->log('written');

        $this->waitForOthers();

        Log::info('probe-line', ['job' => $this->tenant]);
        Log::channel('other')->info('probe-other-line', ['job' => $this->tenant]);

        $this->log('end', [
            'user' => Auth::id(),
            'guard_user' => app(Guard::class)->id(),
            'resolver_user' => Auth::userResolver()()?->getAuthIdentifier(),
            'async_user' => async(static function (): mixed {
                delay(0.01);

                return Auth::id();
            })->await(),
            'memo' => Cache::memo()->get('memo-key'),
            'log_manager' => Log::getFacadeRoot()::class,
            'auth_manager' => Auth::getFacadeRoot()::class,
        ]);
    }

    private function waitForOthers(): void
    {
        $deadline = microtime(true) + 5;

        foreach ($this->waitFor as $tenant) {
            while (! isset(self::$marks[$tenant])) {
                if (microtime(true) >= $deadline) {
                    $this->log('rendezvous-timeout', ['waiting_for' => $tenant]);

                    return;
                }

                delay(0.01);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $type, array $extra = []): void
    {
        file_put_contents(
            config('attempt_isolation.log'),
            json_encode(['type' => $type, 'job' => $this->tenant, ...$extra], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND,
        );
    }
}
