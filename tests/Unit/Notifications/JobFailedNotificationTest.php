<?php

declare(strict_types=1);

use Illuminate\Notifications\Messages\MailMessage;
use Webpatser\Torque\Notifications\JobFailedNotification;

it('returns a MailMessage with the correct subject', function () {
    $notification = new JobFailedNotification(
        jobName: 'App\\Jobs\\SendInvoice',
        queue: 'billing',
        exceptionMessage: 'Connection refused',
        failedAt: '2026-04-06T12:00:00+00:00',
    );

    $mail = $notification->toMail((object) []);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe('Torque: Job Failed on billing');
});

it('includes job name and queue in the mail body', function () {
    $notification = new JobFailedNotification(
        jobName: 'App\\Jobs\\ProcessPayment',
        queue: 'payments',
        exceptionMessage: 'Timeout exceeded',
        failedAt: '2026-04-06T14:30:00+00:00',
    );

    $mail = $notification->toMail((object) []);

    // MailMessage stores lines in introLines.
    $body = implode(' ', $mail->introLines);

    expect($body)->toContain('App\\Jobs\\ProcessPayment')
        ->and($body)->toContain('payments');
});

it('returns all expected keys from toArray', function () {
    $notification = new JobFailedNotification(
        jobName: 'App\\Jobs\\SyncData',
        queue: 'default',
        exceptionMessage: 'Some error',
        failedAt: '2026-04-06T10:00:00+00:00',
    );

    $array = $notification->toArray((object) []);

    expect($array)->toHaveKeys([
        'job_name',
        'queue',
        'exception_message',
        'failed_at',
    ])
        ->and($array['job_name'])->toBe('App\\Jobs\\SyncData')
        ->and($array['queue'])->toBe('default')
        ->and($array['exception_message'])->toBe('Some error')
        ->and($array['failed_at'])->toBe('2026-04-06T10:00:00+00:00');
});

it('redacts password from exception message in mail', function () {
    $notification = new JobFailedNotification(
        jobName: 'App\\Jobs\\DbSync',
        queue: 'default',
        exceptionMessage: 'SQLSTATE: access denied password="s3cretP@ss" for user',
        failedAt: '2026-04-06T10:00:00+00:00',
    );

    $mail = $notification->toMail((object) []);
    $body = implode(' ', $mail->introLines);

    expect($body)->not->toContain('s3cretP@ss')
        ->and($body)->toContain('password=***');
});

it('redacts API key from exception message in mail', function () {
    $notification = new JobFailedNotification(
        jobName: 'App\\Jobs\\CallApi',
        queue: 'default',
        exceptionMessage: 'Request failed with api_key=sk-live-abc123xyz in headers',
        failedAt: '2026-04-06T10:00:00+00:00',
    );

    $mail = $notification->toMail((object) []);
    $body = implode(' ', $mail->introLines);

    expect($body)->not->toContain('sk-live-abc123xyz')
        ->and($body)->toContain('api_key=***');
});

it('redacts URI credentials from exception message in mail', function () {
    $notification = new JobFailedNotification(
        jobName: 'App\\Jobs\\CacheWarm',
        queue: 'default',
        exceptionMessage: 'Cannot connect to redis://admin:superSecret@redis.internal:6379',
        failedAt: '2026-04-06T10:00:00+00:00',
    );

    $mail = $notification->toMail((object) []);
    $body = implode(' ', $mail->introLines);

    expect($body)->not->toContain('superSecret')
        ->and($body)->not->toContain('admin:superSecret')
        ->and($body)->toContain('://***:***@');
});

it('uses mail as the delivery channel', function () {
    $notification = new JobFailedNotification(
        jobName: 'App\\Jobs\\Test',
        queue: 'default',
        exceptionMessage: 'error',
        failedAt: '2026-04-06T10:00:00+00:00',
    );

    expect($notification->via((object) []))->toBe(['mail']);
});
