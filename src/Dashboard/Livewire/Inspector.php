<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\DeadLetterData;
use Webpatser\Torque\Dashboard\Data\JobsData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\PayloadSanitizer;

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

    /** Dead-letter entries may be written this long before the job's first event (clock skew). */
    private const int LOOKUP_MARGIN_MS = 60_000;

    /** A miss is remembered only once the job's last event is this old. */
    private const int LOOKUP_SETTLE_MS = 5_000;

    /** Why the last Retry did not happen, shown above the tabs. */
    #[Locked]
    public ?string $retryError = null;

    /**
     * The last dead-letter lookup, keyed by the job's last event id, so a poll
     * does not scan the dead-letter stream again.
     *
     * @var array{event: string, id: string|null}|null
     */
    #[Locked]
    public ?array $deadLetterLookup = null;

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
     *
     * A retry that fails stays on this screen and says why, as stock
     * `queue:retry` reports a job it cannot find instead of passing silently.
     */
    public function retry(string $id): void
    {
        try {
            app(DeadLetterHandler::class)->retry($id);
        } catch (\Throwable $e) {
            $this->retryError = $e instanceof \RuntimeException ? $e->getMessage() : 'Retry failed.';
            // The entry may be gone (purged elsewhere): look it up again.
            $this->deadLetterLookup = null;

            return;
        }

        $this->retryError = null;
        $this->redirectRoute('torque.dead', navigate: true);
    }

    public function render(): View
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
        // The dead-letter screen and the picker link here with the dead-letter id.
        $failedJob = collect($failedJobs)->firstWhere('id', $this->uuid);

        $status = $detail['job']['status'] ?? $liveJob['status'] ?? ($failedJob ? 'failed' : $this->statusFromEvents($events));

        // Opened by job uuid instead: dead-letter rows are keyed by stream id.
        // A status or id that is not a string means no dead-letter entry.
        $failedId = Narrow::map($failedJob)['id'] ?? null;
        $deadLetterId = is_string($failedId) ? $failedId : (is_string($status) ? $this->deadLetterIdFor($status, $events) : null);
        $failedJob ??= $deadLetterId === null ? null : collect($failedJobs)->firstWhere('id', $deadLetterId);

        $job = $detail['job'] ?? $liveJob ?? $failedJob ?? [
            'id' => $this->uuid, 'ns' => '', 'cls' => Str::substr($this->uuid, 0, 12),
            'name' => '', 'queue' => '', 'maxTries' => 3, 'worker' => null, 'connection' => 'torque',
        ];
        $isLive = in_array($status, ['running', 'progress', 'retrying'], true);

        $exceptionEv = collect($events)->reverse()->first(
            fn ($e) => in_array($e['type'], ['exception', 'failed', 'dead_lettered'], true),
        );
        $exceptionData = Narrow::map(Narrow::map($exceptionEv)['data'] ?? null);
        $failedJobRow = Narrow::map($failedJob);
        $exception = $exceptionData['exception_class'] ?? $failedJobRow['exception'] ?? null;
        $exceptionMsg = $exceptionData['exception_message'] ?? $failedJobRow['message'] ?? '';
        $trace = $exceptionData['trace'] ?? $failedJobRow['trace'] ?? null;

        $startedCount = count(array_filter($events, fn ($e) => $e['type'] === 'started'));
        $last = $events[count($events) - 1] ?? null;
        $attemptVal = $liveJob['attempt'] ?? $job['attempts'] ?? ($startedCount ?: ($job['attempt'] ?? 1));

        return view('torque::dashboard.inspector-detail', [
            'job' => $job,
            'events' => $events,
            'payload' => is_array($detail['payload']) ? PayloadSanitizer::sanitizePayload($detail['payload']) : null,
            'status' => $status,
            'isLive' => $isLive,
            'exception' => $exception,
            'exceptionMsg' => $exceptionMsg,
            'trace' => $trace,
            'attemptVal' => $attemptVal,
            'lastWorker' => Narrow::map(Narrow::map($last)['data'] ?? null)['worker'] ?? null,
            'canRetry' => $deadLetterId !== null,
            'retryId' => $deadLetterId,
            'deadCount' => $chrome['deadCount'],
            'workerCount' => $chrome['workerCount'],
        ]);
    }

    /**
     * The dead-letter id of the inspected job, looked up only for a failed job.
     *
     * The scan starts at the job's first event (less a margin), so a miss reads
     * only the entries newer than that. The answer is kept per last event id:
     * a new attempt or a retry records a new event, which forces a fresh scan.
     * A miss is kept only once the last event has settled, so an entry written
     * just after its event is still found on the next poll.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function deadLetterIdFor(string $status, array $events): ?string
    {
        $last = $events[count($events) - 1] ?? null;

        if ($status !== 'failed' || $last === null || $this->uuid === null) {
            $this->deadLetterLookup = null;

            return null;
        }

        $lastId = Narrow::string($last['id'] ?? null);

        if ($this->deadLetterLookup !== null && $this->deadLetterLookup['event'] === $lastId) {
            return $this->deadLetterLookup['id'];
        }

        $uuid = $this->uuid;
        $since = max(0, Narrow::int($events[0]['ts'] ?? null) - self::LOOKUP_MARGIN_MS);
        $found = rescue(fn (): ?string => app(DeadLetterHandler::class)->findIdByJobUuid($uuid, $since), false, false);

        if ($found === false) {
            return null;
        }

        if ($found !== null || Narrow::int($last['ts'] ?? null) <= (int) (microtime(true) * 1000) - self::LOOKUP_SETTLE_MS) {
            $this->deadLetterLookup = ['event' => $lastId, 'id' => $found];
        }

        return $found;
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
