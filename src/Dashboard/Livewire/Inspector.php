<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\DeadLetterData;
use Webpatser\Torque\Dashboard\Data\JobsData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;
use Webpatser\Torque\Job\DeadLetterHandler;

/**
 * Job inspector: a picker when no job is selected, otherwise a per-job detail
 * view tracing the job's Redis event stream with payload / exception / tail tabs.
 */
#[Layout('torque::dashboard.layout')]
final class Inspector extends Component
{
    use WithDashboardChrome;

    public ?string $uuid = null;

    public string $tab = 'payload';

    public function mount(?string $uuid = null): void
    {
        $this->uuid = $uuid;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    /**
     * Retry a dead-lettered job, then jump to the dead-letter screen.
     */
    public function retry(string $id)
    {
        rescue(fn () => app(DeadLetterHandler::class)->retry($id), null, false);

        return $this->redirectRoute('torque.dead', navigate: true);
    }

    public function render()
    {
        $chrome = $this->chrome();

        if ($this->uuid === null) {
            return view('torque::dashboard.inspector-picker', [
                'running' => rescue(fn (): array => app(JobsData::class)->list('active'), [], false),
                'failed' => rescue(fn (): array => app(DeadLetterData::class)->list()['jobs'], [], false),
                'deadCount' => $chrome['deadCount'],
                'workerCount' => $chrome['workerCount'],
            ]);
        }

        $detail = rescue(fn (): array => app(JobsData::class)->show($this->uuid), ['job' => null, 'events' => [], 'payload' => null], false);
        $events = $detail['events'];

        $live = rescue(fn (): array => app(JobsData::class)->list('all'), [], false);
        $failedJobs = rescue(fn (): array => app(DeadLetterData::class)->list()['jobs'], [], false);

        $liveJob = collect($live)->firstWhere('id', $this->uuid);
        $failedJob = collect($failedJobs)->firstWhere('id', $this->uuid);

        $job = $detail['job'] ?? $liveJob ?? $failedJob ?? [
            'id' => $this->uuid, 'ns' => '', 'cls' => Str::substr($this->uuid, 0, 12),
            'name' => '', 'queue' => '', 'maxTries' => 3, 'worker' => null, 'connection' => 'torque',
        ];

        $status = $detail['job']['status'] ?? $liveJob['status'] ?? ($failedJob ? 'failed' : $this->statusFromEvents($events));
        $isLive = in_array($status, ['running', 'progress', 'retrying'], true);

        $exceptionEv = collect($events)->reverse()->first(
            fn ($e) => in_array($e['type'], ['exception', 'failed', 'dead_lettered'], true),
        );
        $exception = $exceptionEv['data']['exception_class'] ?? $failedJob['exception'] ?? null;
        $exceptionMsg = $exceptionEv['data']['exception_message'] ?? $failedJob['message'] ?? '';
        $trace = $exceptionEv['data']['trace'] ?? $failedJob['trace'] ?? null;

        $startedCount = count(array_filter($events, fn ($e) => $e['type'] === 'started'));
        $last = $events[count($events) - 1] ?? null;
        $attemptVal = $liveJob['attempt'] ?? $job['attempts'] ?? ($startedCount ?: ($job['attempt'] ?? 1));

        return view('torque::dashboard.inspector-detail', [
            'job' => $job,
            'events' => $events,
            'payload' => $detail['payload'],
            'status' => $status,
            'isLive' => $isLive,
            'exception' => $exception,
            'exceptionMsg' => $exceptionMsg,
            'trace' => $trace,
            'attemptVal' => $attemptVal,
            'lastWorker' => $last['data']['worker'] ?? null,
            'canRetry' => $status === 'failed' || $failedJob !== null,
            'retryId' => $failedJob['id'] ?? $this->uuid,
            'deadCount' => $chrome['deadCount'],
            'workerCount' => $chrome['workerCount'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function statusFromEvents(array $events): string
    {
        $last = $events[count($events) - 1] ?? null;

        if ($last === null) {
            return 'queued';
        }

        return match ($last['type']) {
            'started', 'progress' => 'running',
            'queued' => 'queued',
            'completed' => 'completed',
            'failed', 'dead_lettered' => 'failed',
            'exception' => 'exception',
            default => 'running',
        };
    }
}
