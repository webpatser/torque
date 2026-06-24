<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Dashboard\Http\JobPresenter;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Queue\StreamQueue;

/**
 * Dead-letter read-model: list permanently failed jobs in the dashboard shape.
 *
 * Retry and purge are mutations and stay on {@see DeadLetterHandler}; the
 * Livewire Dead component calls those directly.
 */
final class DeadLetterData
{
    public function __construct(private readonly DeadLetterHandler $handler) {}

    /**
     * List dead-letter entries (newest first), cursor-paginated via `$before`.
     *
     * @return array{count: int, jobs: list<array<string, mixed>>}
     */
    public function list(?string $before = null, int $limit = 50): array
    {
        $entries = is_string($before) && $before !== ''
            ? $this->handler->listBefore($before, $limit)
            : $this->handler->list($limit);

        $jobs = array_map(static function (array $entry): array {
            ['ns' => $ns, 'cls' => $cls, 'name' => $name] = JobPresenter::splitName(
                self::displayName($entry['payload']),
            );

            return [
                'id' => $entry['id'],
                'ns' => $ns,
                'cls' => $cls,
                'name' => $name,
                'queue' => $entry['original_queue'],
                'exception' => $entry['exception_class'],
                'message' => $entry['exception_message'],
                'trace' => $entry['exception_trace'],
                'attempts' => null,
                'failedAt' => self::toMs($entry['failed_at']),
                'worker' => null,
            ];
        }, $entries);

        return ['count' => $this->handler->count(), 'jobs' => $jobs];
    }

    /**
     * Best-effort extraction of the job class from a raw payload.
     */
    private static function displayName(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        try {
            return (string) (StreamQueue::decodePayload($payload)['displayName'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Convert an ISO-8601 timestamp to a millisecond epoch.
     */
    private static function toMs(string $iso): int
    {
        $ts = strtotime($iso);

        return $ts !== false ? $ts * 1000 : 0;
    }
}
