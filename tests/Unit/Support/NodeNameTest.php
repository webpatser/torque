<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Webpatser\Torque\Support\NodeName;

/*
 * The node name: --node, then torque.node, then the hostname; validated so
 * torque:start can refuse, and fixed once per process.
 */

afterEach(function () {
    NodeName::resetForTesting();
});

it('ships a null node and the remote control defaults', function () {
    $config = require dirname(__DIR__, 3).'/config/torque.php';

    expect($config['node'])->toBeNull()
        ->and($config['nodes'])->toBe([])
        ->and($config['control'])->toBe(['enabled' => true, 'command_ttl_seconds' => 60]);
});

it('prefers the option over config and hostname', function () {
    expect(NodeName::resolve('cli-node', ['node' => 'config-node']))->toBe('cli-node');
});

it('falls back to the config when the option is blank', function (?string $option) {
    expect(NodeName::resolve($option, ['node' => 'config-node']))->toBe('config-node');
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['  '],
]);

it('falls back to the hostname when neither is set', function (mixed $node) {
    expect(NodeName::resolve(null, ['node' => $node]))->toBe(NodeName::fromHostname(NodeName::hostname()));
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('trims surrounding whitespace', function () {
    expect(NodeName::resolve(' web-1 ', []))->toBe('web-1');
});

it('accepts valid names', function (string $name) {
    expect(NodeName::isValid($name))->toBeTrue()
        ->and(NodeName::validate($name))->toBe($name);
})->with([
    'plain' => ['web1'],
    'all allowed characters' => ['Batch-1.eu_west'],
    '48 characters' => [str_repeat('a', 48)],
]);

it('refuses invalid names', function (string $name) {
    expect(NodeName::isValid($name))->toBeFalse()
        ->and(fn () => NodeName::resolve($name, []))
        ->toThrow(InvalidArgumentException::class, 'Invalid Torque node name');
})->with([
    'space inside' => ['web 1'],
    '49 characters' => [str_repeat('a', 49)],
    'colon' => ['web:1'],
    'slash' => ['web/1'],
    'non ascii' => ['wéb'],
]);

it('refuses a trailing newline', function () {
    // resolve() trims, so check the validators directly.
    expect(NodeName::isValid("web-1\n"))->toBeFalse()
        ->and(fn () => NodeName::validate("web-1\n"))->toThrow(InvalidArgumentException::class)
        ->and(NodeName::isValid(NodeName::fromHostname("web-1\n")))->toBeTrue();
});

it('refuses an invalid name from the config', function () {
    expect(fn () => NodeName::resolve(null, ['node' => 'bad name']))
        ->toThrow(InvalidArgumentException::class, 'TORQUE_NODE');
});

it('returns the name set at boot as current', function () {
    NodeName::setCurrent('worker-node');

    expect(NodeName::current())->toBe('worker-node')
        ->and(NodeName::hasCurrent())->toBeTrue();
});

it('allows setting the same name twice', function () {
    NodeName::setCurrent('node-a');

    expect(NodeName::setCurrent('node-a'))->toBe('node-a');
});

it('refuses changing the current name once set', function () {
    NodeName::setCurrent('node-a');

    expect(fn () => NodeName::setCurrent('node-b'))->toThrow(LogicException::class, 'already set');
});

it('refuses an invalid current name', function () {
    expect(fn () => NodeName::setCurrent('bad name'))->toThrow(InvalidArgumentException::class);
});

it('resolves current from the hostname when nothing is set', function () {
    expect(NodeName::hasCurrent())->toBeFalse()
        ->and(NodeName::current())->toBe(NodeName::fromHostname(NodeName::hostname()))
        ->and(NodeName::isValid(NodeName::current()))->toBeTrue();
});

it('keeps a valid hostname unchanged', function (string $hostname) {
    expect(NodeName::fromHostname($hostname))->toBe($hostname);
})->with([
    'short' => ['web-1'],
    'fqdn' => ['web-1.eu-west.example.com'],
    '48 characters' => [str_repeat('h', 48)],
]);

it('replaces characters a node name may not hold with dashes', function () {
    expect(NodeName::fromHostname('web 1:prod/eu'))->toBe('web-1-prod-eu')
        ->and(NodeName::fromHostname('wéb'))->toBe('w--b');
});

it('falls back to unknown for an empty hostname', function () {
    expect(NodeName::fromHostname(''))->toBe(NodeName::UNKNOWN);
});

it('shortens a long hostname to exactly 48 valid characters', function (string $hostname) {
    $name = NodeName::fromHostname($hostname);

    expect(strlen($name))->toBe(48)
        ->and(NodeName::isValid($name))->toBeTrue()
        ->and($name)->toStartWith(substr(str_replace(' ', '-', $hostname), 0, 39).'-')
        ->and(substr($name, 40))->toBe(substr(hash('sha256', $hostname), 0, 8))
        ->and(NodeName::fromHostname($hostname))->toBe($name);
})->with([
    'long pod name' => ['worker-deployment-7f9c8d6b5-abcde.default.svc.cluster.local'],
    'long with spaces' => [str_repeat('a b ', 20)],
]);

it('keeps long hostnames with the same first 48 characters apart', function () {
    $prefix = str_repeat('p', 48);

    expect(NodeName::fromHostname($prefix.'-one'))->not->toBe(NodeName::fromHostname($prefix.'-two'));
});

it('agrees between resolve and current', function (?string $node) {
    $container = new Container;
    $container->instance('config', new Repository(['torque' => ['node' => $node]]));
    Container::setInstance($container);

    try {
        expect(NodeName::current())->toBe(NodeName::resolve());
    } finally {
        Container::setInstance(null);
    }
})->with([
    'hostname fallback' => [null],
    'blank config' => [''],
    'configured name' => ['batch-1'],
]);

it('agrees between resolve and current outside an application', function () {
    expect(NodeName::current())->toBe(NodeName::resolve());
});

it('still refuses an invalid explicit name while current stays valid', function () {
    $container = new Container;
    $container->instance('config', new Repository(['torque' => ['node' => str_repeat('x', 60)]]));
    Container::setInstance($container);

    try {
        expect(fn () => NodeName::resolve())->toThrow(InvalidArgumentException::class, 'Invalid Torque node name')
            ->and(fn () => NodeName::resolve('bad name'))->toThrow(InvalidArgumentException::class)
            ->and(NodeName::isValid(NodeName::current()))->toBeTrue();
    } finally {
        Container::setInstance(null);
    }
});

it('forgets the current name on reset', function () {
    NodeName::setCurrent('node-a');
    NodeName::resetForTesting();

    expect(NodeName::hasCurrent())->toBeFalse()
        ->and(NodeName::setCurrent('node-b'))->toBe('node-b');
});
