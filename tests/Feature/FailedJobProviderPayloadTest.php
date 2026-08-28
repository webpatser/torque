<?php

declare(strict_types=1);

use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webpatser\Torque\Worker\WorkerProcess;

/*
|--------------------------------------------------------------------------
| The failed-job provider receives JSON on igbinary sites
|--------------------------------------------------------------------------
| Laravel's database-uuids provider reads json_decode($payload)['uuid'],
| which is null['uuid'] for an igbinary body. The worker's JobFailed
| listener therefore hands it WorkerProcess::failerPayload() instead of
| the raw body. This test drives the real provider on an in-memory
| sqlite failed_jobs table with both bodies.
*/

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    Schema::create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    $this->failer = new DatabaseUuidFailedJobProvider(app('db'), 'sqlite', 'failed_jobs');
});

it('stores an igbinary job in failed_jobs once the payload is re-encoded', function () {
    $raw = igbinary_serialize(['uuid' => 'job-uuid-1', 'displayName' => 'App\\Jobs\\Scrape', 'attempts' => 3]);

    $this->failer->log('torque', 'scrpr', WorkerProcess::failerPayload($raw), new RuntimeException('KvK down'));

    $row = DB::table('failed_jobs')->first();

    expect($row->uuid)->toBe('job-uuid-1')
        ->and(json_decode($row->payload, true)['displayName'])->toBe('App\\Jobs\\Scrape');
})->skip(! extension_loaded('igbinary'), 'ext-igbinary not loaded');

it('documents why the raw igbinary body cannot be logged directly', function () {
    $raw = igbinary_serialize(['uuid' => 'job-uuid-2']);

    expect(fn () => $this->failer->log('torque', 'scrpr', $raw, new RuntimeException('KvK down')))
        ->toThrow(ErrorException::class, 'Trying to access array offset on null');
})->skip(! extension_loaded('igbinary'), 'ext-igbinary not loaded');

it('stores a JSON job unchanged', function () {
    $json = json_encode(['uuid' => 'job-uuid-3', 'displayName' => 'App\\Jobs\\Scrape']);

    $this->failer->log('torque', 'scrpr', WorkerProcess::failerPayload($json), new RuntimeException('KvK down'));

    expect(DB::table('failed_jobs')->where('uuid', 'job-uuid-3')->exists())->toBeTrue();
});
