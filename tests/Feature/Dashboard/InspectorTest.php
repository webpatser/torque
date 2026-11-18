<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Livewire\Inspector;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Stream\JobStream;

/**
 * The inspector's Retry button: dead-letter entries are keyed by stream id, the
 * inspector by job uuid, so the button must resolve one to the other, only for
 * a failed job, bounded by the job's age, once per state, and say so when a
 * retry does not happen.
 */
const INSPECTOR_TEST_PREFIX = 'torque-test-inspector:';

const INSPECTOR_TEST_UUIDS = ['uuid-dead', 'uuid-alive', 'uuid-done', 'uuid-gone', 'uuid-old', 'uuid-older', 'uuid-newer', 'uuid-other'];

function inspectorKeys(): array
{
    return [
        INSPECTOR_TEST_PREFIX.'dead-letter',
        INSPECTOR_TEST_PREFIX.'default',
        ...array_map(fn (string $uuid): string => INSPECTOR_TEST_PREFIX.'job:'.$uuid, INSPECTOR_TEST_UUIDS),
    ];
}

beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);
    config()->set('torque.redis.prefix', INSPECTOR_TEST_PREFIX);
    app()->forgetInstance(DeadLetterHandler::class);
    app()->forgetInstance(JobStream::class);
    TorqueDashboardController::register();
    Gate::define('viewTorque', fn ($user): bool => true);

    try {
        $redis = torqueRedis();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }

    $redis->execute('DEL', ...inspectorKeys());
});

afterEach(function () {
    rescue(fn () => torqueRedis()->execute('DEL', ...inspectorKeys()), null, false);
});

function inspectorDeadLetter(string $uuid): string
{
    $handler = app(DeadLetterHandler::class);
    $handler->handle('default', json_encode(['uuid' => $uuid, 'displayName' => 'App\Jobs\Broken']), '1680000000000-0', new RuntimeException('boom'));

    return $handler->list(1)[0]['id'];
}

/**
 * Record a job's lifecycle events with explicit millisecond ids.
 *
 * @param  list<array{0: int, 1: string}>  $events
 */
function inspectorEvents(string $uuid, array $events): void
{
    foreach ($events as [$ms, $type]) {
        torqueRedis()->execute('XADD', INSPECTOR_TEST_PREFIX.'job:'.$uuid, $ms.'-0', 'type', $type, 'displayName', 'App\Jobs\Broken', 'queue', 'default');
    }
}

function inspectorNowMs(): int
{
    return (int) (microtime(true) * 1000);
}

it('finds a dead-letter id by job uuid, newest first, and null for an unknown uuid', function () {
    $older = inspectorDeadLetter('uuid-older');
    inspectorDeadLetter('uuid-newer');

    $handler = app(DeadLetterHandler::class);

    expect($handler->findIdByJobUuid('uuid-older'))->toBe($older)
        ->and($handler->findIdByJobUuid('uuid-'))->toBeNull()
        ->and($handler->findIdByJobUuid('missing'))->toBeNull()
        ->and($handler->findIdByJobUuid(''))->toBeNull()
        // Bounded by time: an entry older than the bound is never read.
        ->and($handler->findIdByJobUuid('uuid-older', inspectorNowMs() + 60_000))->toBeNull();
});

it('offers Retry with the dead-letter id of a dead-lettered job and retries it', function () {
    $id = inspectorDeadLetter('uuid-dead');
    inspectorEvents('uuid-dead', [[inspectorNowMs() - 10_000, 'started'], [inspectorNowMs() - 9_000, 'dead_lettered']]);

    $component = Livewire::actingAs(torqueTestUser())->test(Inspector::class, ['uuid' => 'uuid-dead']);

    expect($component->viewData('canRetry'))->toBeTrue()
        ->and($component->viewData('retryId'))->toBe($id)
        ->and($component->viewData('status'))->toBe('failed');

    $component->assertSeeHtml("retry('{$id}')")
        ->call('retry', $id)
        ->assertRedirect(route('torque.dead'))
        ->assertSet('retryError', null);

    expect(app(DeadLetterHandler::class)->count())->toBe(0);
});

it('still offers Retry when opened with the dead-letter id, without a scan', function () {
    $id = inspectorDeadLetter('uuid-dead');

    $component = Livewire::actingAs(torqueTestUser())->test(Inspector::class, ['uuid' => $id]);

    expect($component->viewData('retryId'))->toBe($id)
        ->and(app(DeadLetterHandler::class)->scannedEntries())->toBe(0);
});

it('hides Retry for a job that is not in the dead-letter stream', function () {
    inspectorDeadLetter('uuid-other');
    inspectorEvents('uuid-alive', [[inspectorNowMs() - 10_000, 'started'], [inspectorNowMs() - 9_000, 'failed']]);

    $component = Livewire::actingAs(torqueTestUser())->test(Inspector::class, ['uuid' => 'uuid-alive']);

    expect($component->viewData('canRetry'))->toBeFalse()
        ->and($component->viewData('retryId'))->toBeNull();
});

it('reads no dead-letter entry for a job that did not fail', function () {
    inspectorDeadLetter('uuid-other');
    inspectorEvents('uuid-done', [[inspectorNowMs() - 10_000, 'started'], [inspectorNowMs() - 9_000, 'completed']]);

    Livewire::actingAs(torqueTestUser())->test(Inspector::class, ['uuid' => 'uuid-done'])
        ->call('setTab', 'events');

    expect(app(DeadLetterHandler::class)->scannedEntries())->toBe(0);
});

it('reads only entries newer than the job and scans once across polls', function () {
    // 1200 entries from long before the job, and two from after it started.
    $old = INSPECTOR_TEST_PREFIX.'dead-letter';
    $base = inspectorNowMs() - 3_600_000;

    for ($i = 0; $i < 1200; $i++) {
        torqueRedis()->execute('XADD', $old, ($base + $i).'-0', 'payload', '{"uuid":"uuid-older"}', 'original_queue', 'default');
    }

    inspectorDeadLetter('uuid-other');
    inspectorDeadLetter('uuid-newer');
    inspectorEvents('uuid-old', [[inspectorNowMs() - 20_000, 'started'], [inspectorNowMs() - 10_000, 'failed']]);

    $handler = app(DeadLetterHandler::class);
    $component = Livewire::actingAs(torqueTestUser())->test(Inspector::class, ['uuid' => 'uuid-old']);

    expect($component->viewData('canRetry'))->toBeFalse()
        ->and($handler->scannedEntries())->toBe(2);

    // A poll on the same final state reads nothing: a rescan would now count
    // the extra entry, so the last lookup's count stays at 2.
    inspectorDeadLetter('uuid-other');
    $component->call('setTab', 'events')->call('setTab', 'payload');

    expect($handler->scannedEntries())->toBe(2);

    // A new attempt that ends in the dead-letter stream is picked up.
    $id = inspectorDeadLetter('uuid-old');
    inspectorEvents('uuid-old', [[inspectorNowMs() - 1_000, 'started'], [inspectorNowMs(), 'dead_lettered']]);

    $component->call('setTab', 'events');

    // One page: the four entries newer than the job, none of the 1200 older.
    expect($component->viewData('retryId'))->toBe($id)
        ->and($handler->scannedEntries())->toBe(4);
});

it('refuses a client-side update of the retry error', function () {
    $component = Livewire::actingAs(torqueTestUser())->test(Inspector::class, ['uuid' => 'uuid-gone']);

    expect(fn () => $component->set('retryError', '<b>forged</b>'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('stays on the inspector and says why when a retry fails', function () {
    Livewire::actingAs(torqueTestUser())->test(Inspector::class, ['uuid' => 'uuid-gone'])
        ->call('retry', '1-0')
        ->assertNoRedirect()
        ->assertSet('retryError', 'Dead-letter entry [1-0] not found.')
        ->assertSee('Dead-letter entry [1-0] not found.');
});
