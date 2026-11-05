<?php

declare(strict_types=1);

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Data\BatchesData;
use Webpatser\Torque\Dashboard\Livewire\Batches;
use Webpatser\Torque\Dashboard\TorqueDashboardController;

/**
 * The batches screen reads Laravel's own BatchRepository, so these tests run on
 * the default sqlite connection with a real `job_batches` table (no Redis).
 */
beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);
    TorqueDashboardController::register();

    // The skeleton points batching at its own sqlite connection; use the one
    // the test creates its table on.
    config()->set('queue.batching.database', config('database.default'));
    Gate::define('viewTorque', fn ($user): bool => true);
});

function createBatchesTable(): void
{
    Schema::create('job_batches', function ($table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
}

/** Ids sort ascending with $n, so a higher $n is a newer batch. */
function seedBatch(int $n, int $total = 10, int $pending = 0, array $failedIds = [], ?int $finishedAt = null): void
{
    DB::table('job_batches')->insert([
        'id' => sprintf('batch-%03d', $n),
        'name' => sprintf('import-%03d', $n),
        'total_jobs' => $total,
        'pending_jobs' => $pending,
        'failed_jobs' => count($failedIds),
        'failed_job_ids' => json_encode($failedIds),
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => 1_700_000_000 + $n,
        'finished_at' => $finishedAt,
    ]);
}

it('lists batches newest first with progress, pending, failed and total', function () {
    createBatchesTable();
    seedBatch(1);
    seedBatch(2, total: 10, pending: 4, failedIds: ['f-1']);
    seedBatch(3, total: 8, pending: 0, finishedAt: 1_700_000_100);

    Livewire::actingAs(torqueTestUser())->test(Batches::class)
        ->assertSeeInOrder(['import-003', 'import-002', 'import-001'])
        ->assertSee('60%')
        ->assertSee('batch-002');
});

it('exposes counts and times through the data class', function () {
    createBatchesTable();
    seedBatch(2, total: 10, pending: 4, failedIds: ['f-1', 'f-2']);

    $page = app(BatchesData::class)->list(null, 10);

    expect($page['available'])->toBeTrue()
        ->and($page['batches'])->toHaveCount(1)
        ->and($page['batches'][0])->toMatchArray([
            'id' => 'batch-002',
            'name' => 'import-002',
            'progress' => 60,
            'pending' => 4,
            'failed' => 2,
            'total' => 10,
        ])
        ->and($page['batches'][0])->toHaveKeys(['createdAt', 'finishedAt'])
        ->and($page['next'])->toBeNull();
});

it('paginates with a cursor', function () {
    createBatchesTable();
    foreach (range(1, 17) as $n) {
        seedBatch($n);
    }

    $first = app(BatchesData::class)->list(null, 10);
    expect($first['batches'])->toHaveCount(10)
        ->and($first['batches'][0]['id'])->toBe('batch-017')
        ->and($first['next'])->toBe('batch-008');

    $second = app(BatchesData::class)->list($first['next'], 10);
    expect($second['batches'])->toHaveCount(7)
        ->and($second['batches'][0]['id'])->toBe('batch-007')
        ->and($second['next'])->toBeNull();

    // The page itself shows 15 per page.
    Livewire::actingAs(torqueTestUser())->test(Batches::class)
        ->assertSee('import-017')
        ->assertDontSee('import-001')
        ->assertSee('?before=batch-003', false)
        ->set('before', 'batch-003')
        ->assertSee('import-001')
        ->assertDontSee('import-017')
        ->set('before', null)
        ->assertSee('import-012');
});

it('shows one batch with its failed job ids', function () {
    createBatchesTable();
    seedBatch(5, total: 10, pending: 2, failedIds: ['failed-aaa', 'failed-bbb']);

    Livewire::actingAs(torqueTestUser())->test(Batches::class)
        ->assertSee('?batch=batch-005', false)
        ->set('selected', 'batch-005')
        ->assertSee('failed-aaa')
        ->assertSee('failed-bbb')
        ->set('selected', null)
        ->assertDontSee('failed-aaa');
});

it('shows an empty state with the artisan command when the table is missing', function () {
    expect(Schema::hasTable('job_batches'))->toBeFalse();

    expect(app(BatchesData::class)->list(null, 10))->toMatchArray(['available' => false, 'batches' => []]);

    Livewire::actingAs(torqueTestUser())->test(Batches::class)
        ->assertOk()
        ->assertSee('make:queue-batches-table');

    $this->actingAs(torqueTestUser())->get('/torque/batches')->assertOk()->assertSee('make:queue-batches-table');
});

it('treats a missing table as not set up and reports nothing', function () {
    Exceptions::fake();

    expect(app(BatchesData::class)->list(null, 10))->toMatchArray(['available' => false, 'error' => false])
        ->and(app(BatchesData::class)->find('batch-001'))->toBeNull();

    Exceptions::assertNothingReported();
});

it('reports any other query failure and shows a generic error, not the setup state', function () {
    // A real failure on a real connection: the file exists but is no database.
    // A missing column would not do: sqlite reads an unknown "id" as a string.
    $file = tempnam(sys_get_temp_dir(), 'torque-batches-');
    file_put_contents($file, str_repeat('this is not a database ', 200));
    $this->beforeApplicationDestroyed(fn () => @unlink($file));

    config()->set('database.connections.torque_broken', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']);
    config()->set('queue.batching.database', 'torque_broken');
    app()->forgetInstance(BatchRepository::class);
    Exceptions::fake();

    expect(app(BatchesData::class)->list(null, 10))->toMatchArray(['available' => false, 'error' => true, 'batches' => []]);
    Exceptions::assertReported(QueryException::class);

    expect(app(BatchesData::class)->find('batch-001'))->toBe(['unavailable' => true]);

    Livewire::actingAs(torqueTestUser())->test(Batches::class)
        ->assertOk()
        ->assertSee('batches could not be read')
        ->assertDontSee('make:queue-batches-table');
});

/** A real failure on a real connection: the file exists but is no database. */
function breakBatchesConnection(): string
{
    $file = tempnam(sys_get_temp_dir(), 'torque-batches-');
    file_put_contents($file, str_repeat('this is not a database ', 200));

    config()->set('database.connections.torque_broken', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']);
    config()->set('queue.batching.database', 'torque_broken');
    app()->forgetInstance(BatchRepository::class);

    return $file;
}

it('reports an outage once a minute, not on every poll of every tab', function () {
    $file = breakBatchesConnection();
    $this->beforeApplicationDestroyed(fn () => @unlink($file));
    config()->set('cache.default', 'array');
    Exceptions::fake();

    foreach (range(1, 3) as $poll) {
        expect(app(BatchesData::class)->list(null, 10)['error'])->toBeTrue();
    }

    expect(app(BatchesData::class)->find('batch-001'))->toBe(['unavailable' => true]);

    Exceptions::assertReportedCount(1);
});

it('reports every time when the cache is down with the database', function () {
    $file = breakBatchesConnection();
    $this->beforeApplicationDestroyed(fn () => @unlink($file));
    config()->set('cache.stores.torque_broken', ['driver' => 'database', 'connection' => 'torque_broken', 'table' => 'cache']);
    config()->set('cache.default', 'torque_broken');
    Exceptions::fake();

    (void) app(BatchesData::class)->list(null, 10);
    (void) app(BatchesData::class)->list(null, 10);

    Exceptions::assertReportedCount(2);
});

it('says the batch could not be read instead of not found when the detail lookup fails', function () {
    createBatchesTable();
    seedBatch(1);

    // Laravel's own repository, with only the single-batch lookup failing: the
    // list still reads, which no real failure on sqlite separates reliably.
    app()->instance(BatchRepository::class, new class(app(BatchFactory::class), DB::connection(), 'job_batches') extends DatabaseBatchRepository
    {
        public function find(string $batchId)
        {
            throw new RuntimeException('lookup failed');
        }
    });
    Exceptions::fake();

    Livewire::actingAs(torqueTestUser())->test(Batches::class)
        ->set('selected', 'batch-001')
        ->assertSee('batch batch-001 could not be read')
        ->assertDontSee('batch batch-001 not found');

    Exceptions::assertReported(RuntimeException::class);
});

it('caps the failed job ids at 100 and states how many are hidden', function () {
    createBatchesTable();
    seedBatch(5, total: 200, pending: 0, failedIds: array_map(
        fn (int $n): string => sprintf('failed-%04d', $n),
        range(1, 150),
    ));

    Livewire::actingAs(torqueTestUser())->test(Batches::class)
        ->set('selected', 'batch-005')
        ->assertSee('failed-0100')
        ->assertDontSee('failed-0101')
        ->assertSee('50 more failed job ids not shown');
});

it('does not mention hidden ids at or under the cap', function () {
    createBatchesTable();
    seedBatch(5, total: 200, pending: 0, failedIds: array_map(
        fn (int $n): string => sprintf('failed-%04d', $n),
        range(1, 100),
    ));

    Livewire::actingAs(torqueTestUser())->test(Batches::class)
        ->set('selected', 'batch-005')
        ->assertSee('failed-0100')
        ->assertDontSee('not shown');
});

it('shows a plain empty list when the table exists but has no batches', function () {
    createBatchesTable();

    Livewire::actingAs(torqueTestUser())->test(Batches::class)->assertOk()->assertDontSee('make:queue-batches-table');
});

it('is behind the viewTorque gate', function () {
    createBatchesTable();
    Gate::define('viewTorque', fn ($user): bool => false);

    $this->actingAs(torqueTestUser())->get('/torque/batches')->assertForbidden();
});

it('serves the batches page and lists it in the nav', function () {
    createBatchesTable();
    seedBatch(1);

    $this->actingAs(torqueTestUser())
        ->get('/torque/batches')
        ->assertOk()
        ->assertSee('import-001')
        ->assertSee(route('torque.batches'));
});
