<?php

declare(strict_types=1);

namespace Webpatser\Torque;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Webpatser\Torque\Console\TorqueBenchCommand;
use Webpatser\Torque\Console\TorqueMonitorCommand;
use Webpatser\Torque\Console\TorquePauseCommand;
use Webpatser\Torque\Console\TorqueReloadCommand;
use Webpatser\Torque\Console\TorqueStartCommand;
use Webpatser\Torque\Console\TorqueStatusCommand;
use Webpatser\Torque\Console\TorqueStopCommand;
use Webpatser\Torque\Console\TorqueSupervisorCommand;
use Webpatser\Torque\Console\TorqueWorkerCommand;
use Webpatser\Torque\Dashboard\Livewire\Dead;
use Webpatser\Torque\Dashboard\Livewire\Feed;
use Webpatser\Torque\Dashboard\Livewire\Inspector;
use Webpatser\Torque\Dashboard\Livewire\Overview;
use Webpatser\Torque\Dashboard\Livewire\Queues;
use Webpatser\Torque\Dashboard\Livewire\Workers;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Queue\StreamConnector;
use Webpatser\Torque\Stream\JobStream;
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
        $this->mergeConfigFrom(__DIR__.'/../config/torque.php', 'torque');

        $this->app->singleton(MetricsPublisher::class, function ($app) {
            $config = $app['config']['torque'];

            return new MetricsPublisher(
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

        $this->app->singleton(JobStream::class, function ($app) {
            $config = $app['config']['torque'];

            return new JobStream(
                redisUri: $config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
                prefix: $config['redis']['prefix'] ?? 'torque:',
            );
        });

        $this->app->singleton(DeadLetterHandler::class, function ($app) {
            $config = $app['config']['torque'];

            return new DeadLetterHandler(
                redisUri: $config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
                ttl: $config['dead_letter']['ttl'] ?? 604800,
                prefix: $config['redis']['prefix'] ?? 'torque:',
                allowedQueues: array_keys($config['streams'] ?? []),
            );
        });

        Torque::registerDevCommands();
    }

    /**
     * Bootstrap the Torque queue connector and console commands.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/torque.php' => config_path('torque.php'),
        ], 'torque-config');

        /** @var QueueManager $manager */
        $manager = $this->app['queue'];
        $manager->addConnector('torque', fn (): StreamConnector => new StreamConnector);

        $this->loadViewsFrom(__DIR__.'/Dashboard/resources/views', 'torque');

        $this->publishes([
            __DIR__.'/Dashboard/resources/views' => resource_path('views/vendor/torque'),
        ], 'torque-views');

        if (config('torque.dashboard.enabled', false)) {
            $this->registerDefaultGate();

            TorqueDashboardController::register();
            $this->registerDashboardComponents();
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
                TorqueReloadCommand::class,
                TorqueSupervisorCommand::class,
                TorqueMonitorCommand::class,
                Console\TorqueFlushCommand::class,
                TorqueWorkerCommand::class,
                Console\TorqueTailCommand::class,
                TorqueBenchCommand::class,
            ]);
        }
    }

    /**
     * Register the dashboard's full-page Livewire components.
     *
     * The routes reference the component classes directly, but explicit
     * registration gives them stable names for `<livewire:torque.*>` usage and
     * keeps resolution predictable. Guarded so the package degrades gracefully
     * if Livewire is somehow absent.
     */
    private function registerDashboardComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('torque.overview', Overview::class);
        Livewire::component('torque.workers', Workers::class);
        Livewire::component('torque.queues', Queues::class);
        Livewire::component('torque.feed', Feed::class);
        Livewire::component('torque.inspector', Inspector::class);
        Livewire::component('torque.dead', Dead::class);
    }

    /**
     * Register a permissive default `viewTorque` gate.
     *
     * Allows access only in the `local` environment when the host application
     * hasn't defined its own gate. Production apps must override this in their
     * own `AuthServiceProvider`/`AppServiceProvider`.
     */
    private function registerDefaultGate(): void
    {
        if (Gate::has('viewTorque')) {
            return;
        }

        Gate::define('viewTorque', static fn ($user = null): bool => app()->environment('local'));
    }
}
