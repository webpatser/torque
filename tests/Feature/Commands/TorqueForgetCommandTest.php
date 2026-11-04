<?php

declare(strict_types=1);

use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webpatser\Torque\Job\DeadLetterHandler;

use function Fledge\Async\Redis\createRedisClient;

/*
|--------------------------------------------------------------------------
| torque:forget
|--------------------------------------------------------------------------
|
| Removes dead-letter entries by id (or all, optionally for one queue) and
| never touches live streams. The dead-letter stream key does not depend on
| cluster mode; the queue filter does (entries may hold the tagged name).
|
| Requires a running Redis instance.
|
*/

beforeEach(function () {
    $this->prefix = 'torque-forget-'.bin2hex(random_bytes(4)).':';
    $this->redis = createRedisClient(config('torque.redis.uri'));
    $this->dead = $this->prefix.'dead-letter';

    config(['torque.redis.prefix' => $this->prefix, 'torque.redis.cluster' => false]);
    app()->forgetInstance(DeadLetterHandler::class);

    $this->addDead = fn (string $id, string $queue) => $this->redis->execute(
        'XADD', $this->dead, $id, 'payload', '{"uuid":"'.$id.'"}', 'original_queue', $queue,
    );
});

afterEach(function () {
    foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('removes one entry by id and leaves the others', function () {
    ($this->addDead)('1700000000001-0', 'default');
    ($this->addDead)('1700000000002-0', 'default');

    expect(Artisan::call('torque:forget', ['id' => '1700000000001-0']))->toBe(0)
        ->and((int) $this->redis->execute('XLEN', $this->dead))->toBe(1)
        ->and($this->redis->execute('XRANGE', $this->dead, '1700000000002-0', '1700000000002-0'))->not->toBeEmpty();
});

it('exits non-zero for an unknown id', function () {
    ($this->addDead)('1700000000001-0', 'default');

    expect(Artisan::call('torque:forget', ['id' => '1700000000009-0']))->not->toBe(0)
        ->and(Artisan::output())->toContain('1700000000009-0')
        ->and((int) $this->redis->execute('XLEN', $this->dead))->toBe(1);
});

it('exits non-zero without an id or --all', function () {
    expect(Artisan::call('torque:forget'))->not->toBe(0);
});

it('empties one queue with --all and keeps the others', function () {
    ($this->addDead)('1700000000001-0', 'emails');
    ($this->addDead)('1700000000002-0', 'default');
    ($this->addDead)('1700000000003-0', 'emails');

    $this->artisan('torque:forget', ['--all' => true, '--queue' => 'emails'])
        ->expectsConfirmation('Remove all dead-letter entries for queue [emails]?', 'yes')
        ->assertExitCode(0);

    expect((int) $this->redis->execute('XLEN', $this->dead))->toBe(1)
        ->and($this->redis->execute('XRANGE', $this->dead, '1700000000002-0', '1700000000002-0'))->not->toBeEmpty();
});

it('empties everything with --all', function () {
    ($this->addDead)('1700000000001-0', 'emails');
    ($this->addDead)('1700000000002-0', 'default');

    $this->artisan('torque:forget', ['--all' => true])
        ->expectsConfirmation('Remove all dead-letter entries?', 'yes')
        ->assertExitCode(0);

    expect((int) $this->redis->execute('XLEN', $this->dead))->toBe(0);
});

it('leaves everything when the confirmation is declined', function () {
    ($this->addDead)('1700000000001-0', 'default');

    $this->artisan('torque:forget', ['--all' => true])
        ->expectsConfirmation('Remove all dead-letter entries?', 'no')
        ->assertExitCode(1);

    expect((int) $this->redis->execute('XLEN', $this->dead))->toBe(1);
});

it('skips the prompt with --force', function () {
    ($this->addDead)('1700000000001-0', 'default');

    $this->artisan('torque:forget', ['--all' => true, '--force' => true])->assertExitCode(0);

    expect((int) $this->redis->execute('XLEN', $this->dead))->toBe(0);
});

it('never touches a live stream entry with the same id', function () {
    ($this->addDead)('1700000000001-0', 'default');
    $this->redis->execute('XADD', $this->prefix.'default', '1700000000001-0', 'payload', 'live');

    Artisan::call('torque:forget', ['id' => '1700000000001-0']);
    expect((int) $this->redis->execute('XLEN', $this->prefix.'default'))->toBe(1);

    ($this->addDead)('1700000000002-0', 'default');
    Artisan::call('torque:forget', ['--all' => true, '--force' => true]);

    expect((int) $this->redis->execute('XLEN', $this->dead))->toBe(0)
        ->and((int) $this->redis->execute('XLEN', $this->prefix.'default'))->toBe(1);
});

it('rejects a job uuid with a message about dead-letter stream ids, before touching Redis', function () {
    ($this->addDead)('1700000000001-0', 'default');

    $uuid = '9d5e0f3a-6b1c-4c1e-8f57-2b7f0a1c3d4e';

    expect(Artisan::call('torque:forget', ['id' => $uuid]))->not->toBe(0)
        ->and(Artisan::output())->toContain('dead-letter stream id')->toContain($uuid)->not->toContain('Cannot reach Redis')
        ->and((int) $this->redis->execute('XLEN', $this->dead))->toBe(1);
});

it('explains in --help what the id is', function () {
    expect(Artisan::call('help', ['command_name' => 'torque:forget']))->toBe(0)
        ->and(Artisan::output())->toContain('queue:failed');
});

/**
 * An in-memory failed_jobs table behind the database-uuids failer, as the
 * worker's JobFailed listener fills it.
 */
function torque_forget_failed_jobs_table(): DatabaseUuidFailedJobProvider
{
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    Schema::dropIfExists('failed_jobs');
    Schema::create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    $failer = new DatabaseUuidFailedJobProvider(app('db'), 'sqlite', 'failed_jobs');
    app()->instance('queue.failer', $failer);

    return $failer;
}

it('removes the matching failed_jobs row by uuid for one id, and no other', function () {
    $failer = torque_forget_failed_jobs_table();
    $failer->log('torque', 'default', '{"uuid":"1700000000001-0"}', new RuntimeException('a'));
    $failer->log('torque', 'default', '{"uuid":"1700000000002-0"}', new RuntimeException('b'));
    ($this->addDead)('1700000000001-0', 'default');
    ($this->addDead)('1700000000002-0', 'default');

    expect(Artisan::call('torque:forget', ['id' => '1700000000001-0']))->toBe(0)
        ->and(Artisan::output())->toContain('1 dead-letter entry, 1 failed_jobs record')
        ->and(DB::table('failed_jobs')->pluck('uuid')->all())->toBe(['1700000000002-0']);
});

it('removes the failed_jobs rows of every batch with --all, and keeps rows of other queues', function () {
    $failer = torque_forget_failed_jobs_table();

    // More than one XRANGE page (500) of dead-letter entries from "emails".
    for ($i = 1; $i <= 520; $i++) {
        $id = sprintf('17000000%05d-0', $i);
        ($this->addDead)($id, 'emails');
        $failer->log('torque', 'emails', '{"uuid":"'.$id.'"}', new RuntimeException('x'));
    }
    ($this->addDead)('1700000999999-0', 'default');
    $failer->log('torque', 'default', '{"uuid":"1700000999999-0"}', new RuntimeException('keep'));

    expect(Artisan::call('torque:forget', ['--all' => true, '--force' => true, '--queue' => 'emails']))->toBe(0)
        ->and(Artisan::output())->toContain('520 dead-letter entries, 520 failed_jobs records')
        ->and(DB::table('failed_jobs')->pluck('uuid')->all())->toBe(['1700000999999-0'])
        ->and((int) $this->redis->execute('XLEN', $this->dead))->toBe(1);
});

it('returns only ids and uuids from forgetAll, never the payload', function () {
    ($this->addDead)('1700000000001-0', 'default');

    $removed = app(DeadLetterHandler::class)->forgetAll();

    expect($removed)->toBe([['id' => '1700000000001-0', 'uuid' => '1700000000001-0']]);
});

it('leaves a plain database failer alone', function () {
    torque_forget_failed_jobs_table();
    app()->instance('queue.failer', new class
    {
        public function forget(): never
        {
            throw new LogicException('must not be called');
        }
    });
    ($this->addDead)('1700000000001-0', 'default');

    expect(Artisan::call('torque:forget', ['id' => '1700000000001-0']))->toBe(0)
        ->and(Artisan::output())->toContain('0 failed_jobs records');
});

it('matches the --queue filter in cluster mode for tagged and plain entries', function () {
    config(['torque.redis.cluster' => true]);
    app()->forgetInstance(DeadLetterHandler::class);

    ($this->addDead)('1700000000001-0', '{emails}');
    ($this->addDead)('1700000000002-0', 'emails');
    ($this->addDead)('1700000000003-0', 'default');

    Artisan::call('torque:forget', ['--all' => true, '--force' => true, '--queue' => '{emails}']);

    expect((int) $this->redis->execute('XLEN', $this->dead))->toBe(1)
        ->and($this->redis->execute('XRANGE', $this->dead, '1700000000003-0', '1700000000003-0'))->not->toBeEmpty();
});
