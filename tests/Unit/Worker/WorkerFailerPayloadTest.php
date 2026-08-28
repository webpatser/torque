<?php

declare(strict_types=1);

use Webpatser\Torque\Worker\WorkerProcess;

it('passes a JSON body to the failed-job provider untouched', function () {
    $json = json_encode(['uuid' => 'abc-123', 'displayName' => 'App\\Jobs\\Scrape', 'attempts' => 3]);

    expect(WorkerProcess::failerPayload($json))->toBe($json);
});

it('re-encodes an igbinary body as JSON so database-uuids can read the uuid', function () {
    $payload = ['uuid' => 'abc-123', 'displayName' => 'App\\Jobs\\Scrape', 'attempts' => 3];
    $raw = igbinary_serialize($payload);

    $stored = WorkerProcess::failerPayload($raw);

    expect(json_validate($stored))->toBeTrue()
        ->and(json_decode($stored, true))->toBe($payload)
        ->and(json_decode($stored, true)['uuid'])->toBe('abc-123');
})->skip(! extension_loaded('igbinary'), 'ext-igbinary not loaded');

it('leaves an empty body alone', function () {
    expect(WorkerProcess::failerPayload(''))->toBe('');
});
