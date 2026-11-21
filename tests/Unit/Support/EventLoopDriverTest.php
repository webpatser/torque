<?php

declare(strict_types=1);

use Fledge\Loop;
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;
use Webpatser\Torque\Support\EventLoopDriver;

it('reads auto when the key is unset or empty', function (array $config) {
    expect(EventLoopDriver::configured($config))->toBe('auto');
})->with([
    'unset' => [[]],
    'null' => [['event_loop' => null]],
    'empty' => [['event_loop' => '  ']],
]);

it('resolves the configured name to the driver this host runs', function () {
    expect(EventLoopDriver::resolve(['event_loop' => 'select']))->toBe('select')
        ->and(EventLoopDriver::resolve(['event_loop' => ' SELECT ']))->toBe('select')
        ->and(EventLoopDriver::resolve(['event_loop' => 'auto']))->toBe(PollDriver::isSupported() ? 'poll' : 'select');
});

it('refuses a name that is not a driver', function (string $name) {
    EventLoopDriver::resolve(['event_loop' => $name]);
})->throws(InvalidArgumentException::class)->with(['ev', 'uv', 'epoll']);

it('names the drivers', function () {
    expect(EventLoopDriver::nameOf(new StreamSelectDriver))->toBe('select');

    if (PollDriver::isSupported()) {
        expect(EventLoopDriver::nameOf(new PollDriver))->toBe('poll');
    }
});

it('keeps a loop that already has callbacks, and warns only when it is not the configured driver', function () {
    // A registered callback makes Loop::install() refuse, so nothing is swapped here.
    $callback = Loop::repeat(60, static function (): void {});
    $active = EventLoopDriver::nameOf(Loop::getDriver());
    $other = $active === 'select' ? (PollDriver::isSupported() ? 'poll' : null) : 'select';
    $warnings = [];
    $warn = function (string $warning) use (&$warnings): void {
        $warnings[] = $warning;
    };

    try {
        expect(EventLoopDriver::install(['event_loop' => $active], $warn))->toBe($active)
            ->and($warnings)->toBe([]);

        if ($other !== null) {
            expect(EventLoopDriver::install(['event_loop' => $other], $warn))->toBe($active)
                ->and($warnings)->toHaveCount(1)
                ->and($warnings[0])->toContain("event_loop={$other} not applied, this process keeps the {$active} driver");
        }

        expect(Loop::getIdentifiers())->toContain($callback);
    } finally {
        Loop::cancel($callback);
    }
});

it('lets the process environment win over the configured name', function () {
    expect(EventLoopDriver::configured(['event_loop' => 'poll'], 'select'))->toBe('select')
        ->and(EventLoopDriver::configured(['event_loop' => 'select'], null))->toBe('select')
        // A stale cached value the environment rescues, and a bad environment that is not rescued.
        ->and(EventLoopDriver::resolve(['event_loop' => 'uv'], 'select'))->toBe('select')
        ->and(fn () => EventLoopDriver::resolve(['event_loop' => 'select'], 'uv'))
        ->toThrow(InvalidArgumentException::class, 'expected one of: auto, poll, select');
});

it('names the setting that decided', function () {
    expect(EventLoopDriver::source('select'))->toBe('FLEDGE_EVENT_LOOP from the process environment')
        ->and(EventLoopDriver::source(null))->toBe('torque.event_loop');
});

it('reads FLEDGE_EVENT_LOOP from the process environment, null when unset or empty', function () {
    $original = getenv('FLEDGE_EVENT_LOOP');

    try {
        putenv('FLEDGE_EVENT_LOOP= select ');
        expect(EventLoopDriver::environment())->toBe('select');

        putenv('FLEDGE_EVENT_LOOP=');
        expect(EventLoopDriver::environment())->toBeNull();

        putenv('FLEDGE_EVENT_LOOP');
        expect(EventLoopDriver::environment())->toBeNull();
    } finally {
        putenv($original === false ? 'FLEDGE_EVENT_LOOP' : "FLEDGE_EVENT_LOOP={$original}");
    }
});
