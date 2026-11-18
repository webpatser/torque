<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Livewire\Dead;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Job\DeadLetterHandler;

/**
 * The dead-letter screen's retry and purge actions say when they fail, instead
 * of passing for a success.
 */
const DEAD_TEST_PREFIX = 'torque-test-dead:';

beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);
    config()->set('torque.redis.prefix', DEAD_TEST_PREFIX);
    app()->forgetInstance(DeadLetterHandler::class);
    TorqueDashboardController::register();
    Gate::define('viewTorque', fn ($user): bool => true);

    try {
        $redis = torqueRedis();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }

    $redis->execute('DEL', DEAD_TEST_PREFIX.'dead-letter', DEAD_TEST_PREFIX.'default');
});

afterEach(function () {
    rescue(fn () => torqueRedis()->execute('DEL', DEAD_TEST_PREFIX.'dead-letter', DEAD_TEST_PREFIX.'default'), null, false);
});

function deadTestEntry(string $uuid): string
{
    $handler = app(DeadLetterHandler::class);
    $handler->handle('default', json_encode(['uuid' => $uuid, 'displayName' => 'App\Jobs\Broken']), '1680000000000-0', new RuntimeException('boom'));

    return $handler->list(1)[0]['id'];
}

it('retries an entry and shows no error', function () {
    $id = deadTestEntry('dead-ok');

    Livewire::actingAs(torqueTestUser())->test(Dead::class)
        ->call('retryOne', $id)
        ->assertSet('actionError', null)
        ->assertDontSee('role="alert"', escape: false);

    expect(app(DeadLetterHandler::class)->count())->toBe(0);
});

it('shows the error when a retried entry no longer exists', function () {
    Livewire::actingAs(torqueTestUser())->test(Dead::class)
        ->call('retryOne', '1-0')
        ->assertNoRedirect()
        ->assertSet('actionError', 'Dead-letter entry [1-0] not found.')
        ->assertSee('Dead-letter entry [1-0] not found.');
});

it('shows the error when a purge fails', function () {
    deadTestEntry('dead-a');

    // Not a stream id: Redis refuses the XDEL.
    Livewire::actingAs(torqueTestUser())->test(Dead::class)
        ->call('purgeOne', 'nope')
        ->assertSet('actionError', 'Purge failed.')
        ->assertSee('Purge failed.');
});

it('reports the split when a bulk retry meets a missing entry', function () {
    $first = deadTestEntry('dead-a');
    $second = deadTestEntry('dead-b');

    Livewire::actingAs(torqueTestUser())->test(Dead::class)
        ->set('selected', [$first, '1-0', $second])
        ->call('retrySelected')
        ->assertSet('selected', [])
        ->assertSet('actionError', 'Retried 2 of 3 entries; 1 failed: Dead-letter entry [1-0] not found.');

    expect(app(DeadLetterHandler::class)->count())->toBe(0);
});

it('reports the split when a bulk purge meets a bad id', function () {
    $first = deadTestEntry('dead-a');

    Livewire::actingAs(torqueTestUser())->test(Dead::class)
        ->set('selected', [$first, 'nope'])
        ->call('purgeSelected')
        ->assertSet('actionError', 'Purged 1 of 2 entries; 1 failed: Purge failed.');

    expect(app(DeadLetterHandler::class)->count())->toBe(0);
});

it('refuses a client-side update of the action error', function () {
    $component = Livewire::actingAs(torqueTestUser())->test(Dead::class);

    expect(fn () => $component->set('actionError', 'forged'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
