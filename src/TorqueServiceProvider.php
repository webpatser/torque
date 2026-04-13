<?php

declare(strict_types=1);

namespace Webpatser\Torque;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Webpatser\Torque\Console\TorqueMonitorCommand;
use Webpatser\Torque\Console\TorquePauseCommand;
use Webpatser\Torque\Console\TorqueWorkerCommand;
use Webpatser\Torque\Console\TorqueStartCommand;
use Webpatser\Torque\Console\TorqueStatusCommand;
use Webpatser\Torque\Console\TorqueStopCommand;
use Webpatser\Torque\Console\TorqueSupervisorCommand;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Queue\StreamConnector;
use Webpatser\Torque\Stream\JobStreamRecorder;

/**
 * Registers the Torque queue driver and artisan commands with Laravel.
 */
final class TorqueServiceProvider extends ServiceProvider
{
    /**
     * Register the Torque configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/torque.php', 'torque');

        $this->app->singleton(\Webpatser\Torque\Metrics\MetricsPublisher::class, function ($app) {
            $config = $app['config']['torque'];

            return new \Webpatser\Torque\Metrics\MetricsPublisher(
                redisUri: $config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
                prefix: $config['redis']['prefix'] ?? 'torque:',
            );
        });

        $this->app->singleton(JobStreamRecorder::class, function ($app) {
            $config = $app['config']['torque'];
            $jobStreams = $config['job_streams'] ?? [];

            return new JobStreamRecorder(
                redisUri: $config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
                prefix: $config['redis']['prefix'] ?? 'torque:',
                ttl: (int) ($jobStreams['ttl'] ?? 300),
                maxEvents: (int) ($jobStreams['max_events'] ?? 1000),
                enabled: (bool) ($jobStreams['enabled'] ?? true),
            );
        });

        $this->app->singleton(DeadLetterHandler::class, function ($app) {
            $config = $app['config']['torque'];

            return new DeadLetterHandler(
                redisUri: $config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
                ttl: $config['dead_letter']['ttl'] ?? 604800,
                prefix: $config['redis']['prefix'] ?? 'torque:',
            );
        });
    }

    /**
     * Bootstrap the Torque queue connector and console commands.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/torque.php' => config_path('torque.php'),
        ], 'torque-config');

        /** @var \Illuminate\Queue\QueueManager $manager */
        $manager = $this->app['queue'];
        $manager->addConnector('torque', fn (): StreamConnector => new StreamConnector());

        $this->loadViewsFrom(__DIR__ . '/Dashboard/resources/views', 'torque');

        $this->publishes([
            __DIR__ . '/Dashboard/resources/views' => resource_path('views/vendor/torque'),
        ], 'torque-views');

        if (config('torque.dashboard.enabled', false) && class_exists(\Livewire\Livewire::class)) {
            TorqueDashboardController::register();

            $this->registerLivewireComponents();
        }

        // Record job lifecycle events to per-job Redis Streams.
        $recorder = $this->app->make(JobStreamRecorder::class);
        Event::listen(JobProcessing::class, [$recorder, 'onProcessing']);
        Event::listen(JobProcessed::class, [$recorder, 'onProcessed']);
        Event::listen(JobFailed::class, [$recorder, 'onFailed']);
        Event::listen(JobExceptionOccurred::class, [$recorder, 'onExceptionOccurred']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                TorqueStartCommand::class,
                TorqueStopCommand::class,
                TorqueStatusCommand::class,
                TorquePauseCommand::class,
                TorqueSupervisorCommand::class,
                TorqueMonitorCommand::class,
                TorqueWorkerCommand::class,
                Console\TorqueTailCommand::class,
            ]);
        }
    }

    /**
     * Register Livewire single-file components for the dashboard.
     */
    private function registerLivewireComponents(): void
    {
        $components = [
            'torque.dashboard' => 'dashboard',
            'torque.metric-cards' => 'metric-cards',
            'torque.workers-table' => 'workers-table',
            'torque.streams-table' => 'streams-table',
            'torque.failed-jobs' => 'failed-jobs',
            'torque.poll-interval' => 'poll-interval',
        ];

        $basePath = __DIR__ . '/Dashboard/resources/views/livewire';

        foreach ($components as $alias => $file) {
            Livewire::addComponent($alias, viewPath: $basePath . '/' . $file . '.wire.php');
        }
    }
}
