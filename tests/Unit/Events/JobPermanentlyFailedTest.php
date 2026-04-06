<?php

declare(strict_types=1);

use Webpatser\Torque\Events\JobPermanentlyFailed;

it('holds all failure metadata', function () {
    $event = new JobPermanentlyFailed(
        jobName: 'App\\Jobs\\SendEmail',
        queue: 'emails',
        payload: '{"uuid":"test-1"}',
        exceptionClass: 'RuntimeException',
        exceptionMessage: 'SMTP timeout',
        failedAt: '2026-04-06T12:00:00+00:00',
    );

    expect($event->jobName)->toBe('App\\Jobs\\SendEmail');
    expect($event->queue)->toBe('emails');
    expect($event->payload)->toBe('{"uuid":"test-1"}');
    expect($event->exceptionClass)->toBe('RuntimeException');
    expect($event->exceptionMessage)->toBe('SMTP timeout');
    expect($event->failedAt)->toBe('2026-04-06T12:00:00+00:00');
});
