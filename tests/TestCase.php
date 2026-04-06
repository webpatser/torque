<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Webpatser\Torque\TorqueServiceProvider;

abstract class TestCase extends BaseTestCase
{
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [
            TorqueServiceProvider::class,
        ];
    }

    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.connections.torque', [
            'driver' => 'torque',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 100,
            'prefix' => 'torque-test:',
            'redis_uri' => env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
            'consumer_group' => 'torque-test',
        ]);

        $app['config']->set('queue.default', 'torque');
    }
}
