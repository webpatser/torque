<?php

declare(strict_types=1);

namespace Webpatser\Torque\Stream;

/**
 * Adds `$this->emit()` to queue jobs for custom event streaming.
 *
 * Usage:
 *   class ScrapeKvK implements ShouldQueue
 *   {
 *       use Streamable;
 *
 *       public function handle(): void
 *       {
 *           $this->emit('Fetching from API');
 *           $response = Http::post(...);
 *           $this->emit('Processing results', progress: 0.5);
 *       }
 *   }
 */
trait Streamable
{
    /**
     * Emit a custom event to this job's event stream.
     *
     * @param  string  $message  Human-readable status message.
     * @param  float|null  $progress  Optional progress ratio (0.0–1.0).
     * @param  array<string, mixed>  $data  Optional extra key-value data.
     */
    public function emit(string $message, ?float $progress = null, array $data = []): void
    {
        $uuid = $this->resolveStreamUuid();

        if ($uuid === null) {
            return;
        }

        try {
            app(JobStreamRecorder::class)->emitCustom($uuid, $message, $progress, $data);
        } catch (\Throwable) {
            // Never break job execution for stream events.
        }
    }

    /**
     * Resolve the job UUID for the event stream key.
     */
    private function resolveStreamUuid(): ?string
    {
        // Laravel's InteractsWithQueue trait provides $this->job.
        if (isset($this->job) && method_exists($this->job, 'uuid')) {
            $uuid = $this->job->uuid();

            if ($uuid !== '' && $uuid !== null) {
                return $uuid;
            }
        }

        if (isset($this->job) && method_exists($this->job, 'getJobId')) {
            $id = $this->job->getJobId();

            return $id !== '' && $id !== null ? $id : null;
        }

        return null;
    }
}
