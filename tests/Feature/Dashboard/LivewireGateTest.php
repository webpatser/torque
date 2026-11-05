<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;
use Webpatser\Torque\Dashboard\Livewire\Dead;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Job\DeadLetterHandler;

/**
 * The `viewTorque` gate on `/livewire/update`.
 *
 * Livewire::test disables middleware and never carries a dashboard path in the
 * snapshot, so these send a real POST to the update route with the snapshot the
 * page rendered: exactly what a revoked admin's open browser tab holds.
 */
const GATE_TEST_PREFIX = 'torque-test-gate:';

beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);
    config()->set('torque.redis.prefix', GATE_TEST_PREFIX);
    config()->set('queue.batching.database', config('database.default'));
    app()->forgetInstance(DeadLetterHandler::class);

    TorqueDashboardController::register();
    Gate::define('viewTorque', fn ($user): bool => true);
});

afterEach(function () {
    rescue(function (): void {
        $redis = torqueRedis();
        $redis->execute('DEL', GATE_TEST_PREFIX.'dead-letter');
        $redis->execute('DEL', GATE_TEST_PREFIX.'default');
    }, null, false);
});

function gateSnapshotFrom(string $path): string
{
    $html = (string) test()->actingAs(torqueTestUser())->get($path)->assertOk()->getContent();

    expect(preg_match('/wire:snapshot="([^"]+)"/', $html, $match))->toBe(1);

    return html_entity_decode($match[1], ENT_QUOTES);
}

/**
 * @param  list<mixed>  $params
 * @param  array<string, mixed>  $updates
 */
function gateUpdate(string $snapshot, string $method, array $params = [], array $updates = []): TestResponse
{
    return test()->actingAs(torqueTestUser())
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => [['method' => $method, 'params' => $params, 'metadata' => []]],
            ]],
        ]);
}

function gateRevoke(): void
{
    Gate::define('viewTorque', fn ($user): bool => false);
}

/** Seed one dead-letter entry and return its id, skipping when Redis is down. */
function gateSeedDeadLetter(): string
{
    torqueRedis();

    $handler = app(DeadLetterHandler::class);
    $handler->handle('default', '{"uuid":"gate-test"}', '1680000000000-0', new RuntimeException('boom'));

    return $handler->list(1)[0]['id'];
}

it('answers an update while the gate allows it, so the refusals below mean something', function () {
    $id = gateSeedDeadLetter();
    $snapshot = gateSnapshotFrom('/torque/dead');

    gateUpdate($snapshot, 'purgeSelected', updates: ['selected' => [$id]])->assertOk();

    expect(app(DeadLetterHandler::class)->count())->toBe(0);
});

it('refuses Dead::purgeSelected once the gate is revoked and purges nothing', function () {
    $id = gateSeedDeadLetter();
    $snapshot = gateSnapshotFrom('/torque/dead');

    gateRevoke();
    $status = gateUpdate($snapshot, 'purgeSelected', updates: ['selected' => [$id]])->status();

    expect([$status, app(DeadLetterHandler::class)->count()])->toBe([403, 1]);
});

it('refuses Dead::retryOne once the gate is revoked and leaves the entry dead', function () {
    $id = gateSeedDeadLetter();
    $snapshot = gateSnapshotFrom('/torque/dead');

    gateRevoke();
    $status = gateUpdate($snapshot, 'retryOne', [$id])->status();

    expect([$status, app(DeadLetterHandler::class)->count()])->toBe([403, 1]);
});

it('refuses every update on a screen once the gate is revoked', function (string $path) {
    $snapshot = gateSnapshotFrom($path);

    gateUpdate($snapshot, 'setPollInterval', [5000])->assertOk();

    gateRevoke();

    gateUpdate($snapshot, 'setPollInterval', [5000])->assertForbidden();
})->with([
    'overview' => '/torque',
    'workers' => '/torque/workers',
    'queues' => '/torque/queues',
    'feed' => '/torque/feed',
    'inspector' => '/torque/inspector',
    'jobs' => '/torque/jobs',
    'dead' => '/torque/dead',
    'batches' => '/torque/batches',
]);

it('refuses a dashboard component embedded on a host page, where no dashboard route matches the snapshot', function () {
    Livewire::component('torque.dead', Dead::class);
    Route::middleware('web')->get('/host-page', fn () => Blade::render('<livewire:torque.dead />'));

    $id = gateSeedDeadLetter();
    $snapshot = gateSnapshotFrom('/host-page');

    gateRevoke();
    $status = gateUpdate($snapshot, 'purgeSelected', updates: ['selected' => [$id]])->status();

    expect([$status, app(DeadLetterHandler::class)->count()])->toBe([403, 1]);

    test()->actingAs(torqueTestUser())->get('/host-page')->assertForbidden();
});

it('denies by default when the application never defined the gate', function () {
    $snapshot = gateSnapshotFrom('/torque/feed');

    // What an app without its own gate gets outside local: the package default.
    $gate = app(GateContract::class);
    (new ReflectionProperty($gate, 'abilities'))->setValue($gate, []);
    TorqueDashboardController::register();

    gateUpdate($snapshot, 'setPollInterval', [5000])->assertForbidden();
});

it('guards every Livewire component in the dashboard directory', function () {
    $components = glob(__DIR__.'/../../../src/Dashboard/Livewire/*.php') ?: [];

    expect($components)->not->toBeEmpty();

    foreach ($components as $file) {
        $class = 'Webpatser\\Torque\\Dashboard\\Livewire\\'.basename($file, '.php');

        expect(class_uses_recursive($class))->toHaveKey(WithDashboardChrome::class);
    }
});
