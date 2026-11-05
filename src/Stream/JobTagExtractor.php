<?php

declare(strict_types=1);

namespace Webpatser\Torque\Stream;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out a job's tags at push time, on the real job object, for the payload.
 *
 * Later readers take them from the decoded payload: nothing here unserializes a
 * command, so a worker never runs a job's hooks to learn its tags.
 */
final class JobTagExtractor
{
    public const PAYLOAD_KEY = 'torqueTags';

    public const MAX_TAGS = 50;

    public const MAX_LENGTH = 191;

    /**
     * Job classes already warned about, so a busy dispatcher logs each once.
     *
     * @var array<class-string, true>
     */
    private static array $warned = [];

    /**
     * An explicit `tags()` wins; otherwise the public properties holding a model
     * or a collection of models.
     *
     * Tags must not cost a dispatch, so a failure warns and yields no tags, with
     * one exception: a query error inside a transaction is rethrown. On Postgres
     * that error has aborted the transaction, so swallowing it would turn the
     * caller's COMMIT into a silent ROLLBACK while the job is already on the stream.
     *
     * @return list<string>
     *
     * @throws QueryException when a `tags()` query fails inside a transaction.
     */
    public static function fromJob(object $job): array
    {
        if ($job instanceof CallQueuedClosure) {
            return [];
        }

        try {
            if (method_exists($job, 'tags')) {
                $tags = $job->tags();

                return is_array($tags) ? self::clean($tags) : [];
            }

            return self::clean(self::fromModels($job));
        } catch (\Throwable $e) {
            if ($e instanceof QueryException && self::inTransaction($e)) {
                throw $e;
            }

            self::warnOnce($job::class, $e);

            return [];
        }
    }

    /**
     * A connection the manager cannot resolve, such as one built by hand, counts as
     * not in a transaction: the check must not be what costs the dispatch.
     */
    private static function inTransaction(QueryException $e): bool
    {
        try {
            return DB::connection($e->connectionName)->transactionLevel() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The tags a push stored in a decoded payload. A job queued before tags moved
     * to the payload, or one without any, has none.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    public static function fromPayload(array $payload): array
    {
        $tags = $payload[self::PAYLOAD_KEY] ?? [];

        return is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
    }

    /**
     * get_object_vars from out here sees initialized public properties only, and
     * reads them without running user code. getKey() on a model that has its key
     * needs no query.
     *
     * @return list<string>
     */
    private static function fromModels(object $job): array
    {
        $tags = [];

        foreach (get_object_vars($job) as $value) {
            $models = $value instanceof Collection ? $value->all() : [$value];

            foreach ($models as $model) {
                if (! $model instanceof Model) {
                    continue;
                }

                $key = $model->getKey();

                if (is_int($key) || is_string($key) || $key instanceof \Stringable) {
                    $tags[] = $model::class.':'.$key;
                }

                if (count($tags) >= self::MAX_TAGS) {
                    return $tags;
                }
            }
        }

        return $tags;
    }

    /**
     * Invalid UTF-8, such as a binary primary key, is dropped because a tag that
     * json_encode refuses would make the whole payload fail.
     *
     * @param  array<array-key, mixed>  $tags
     * @return list<string>
     */
    private static function clean(array $tags): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            if (! is_string($tag) && ! $tag instanceof \Stringable && ! is_int($tag)) {
                continue;
            }

            $tag = (string) $tag;

            if ($tag === '' || strlen($tag) > self::MAX_LENGTH || ! mb_check_encoding($tag, 'UTF-8')) {
                continue;
            }

            $clean[$tag] = $tag;

            if (count($clean) >= self::MAX_TAGS) {
                break;
            }
        }

        return array_values($clean);
    }

    /**
     * @param  class-string  $class
     */
    private static function warnOnce(string $class, \Throwable $e): void
    {
        if (isset(self::$warned[$class])) {
            return;
        }

        self::$warned[$class] = true;

        try {
            logger()->warning("Torque pushed {$class} without tags: ".$e->getMessage());
        } catch (\Throwable) {
            // Logging must not break the dispatch either.
        }
    }
}
