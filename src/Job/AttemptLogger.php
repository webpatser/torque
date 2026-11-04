<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Logger;
use Psr\Log\LoggerInterface;

/**
 * A log channel whose context belongs to the attempt writing to it, in a worker
 * running concurrent jobs in one process.
 *
 * The channel itself stays one shared object: its Monolog logger and the handlers
 * and sockets behind it are services for all attempts. Only the array that
 * withContext() merges into stays out of it. An attempt starts from a copy of what
 * the channel held outside any attempt, plus what the attempt has shared so far.
 *
 * tests/Unit/Job/AttemptServicesTest.php fails when the framework adds instance
 * state this class does not account for.
 */
final class AttemptLogger extends Logger
{
    /** Properties of the framework's logger that live on the attempt's scope. */
    public const array ROUTED = ['context'];

    /** Properties of the framework's logger that all attempts share. */
    public const array SHARED = ['logger', 'dispatcher'];

    public function __construct(private readonly string $channel, LoggerInterface $logger, ?Dispatcher $dispatcher = null)
    {
        parent::__construct($logger, $dispatcher);

        // @phpstan-ignore unset.possiblyHookedProperty (this class is final and the framework declares no hooks; the guard test covers a change)
        unset($this->context);
    }

    /**
     * Take over a channel the framework just built, its context as the ambient
     * scope's. The framework gave it the shared context of whoever asked first;
     * that part may be an attempt's own, so only the ambient scope's stays.
     *
     * @param  array<string, mixed>  $callersSharedContext
     */
    public static function adopt(Logger $logger, string $channel, array $callersSharedContext): self
    {
        $ambient = AttemptScope::ambient();

        $ambient->logContext[$channel] = array_merge(
            array_diff_key($logger->context, $callersSharedContext),
            $ambient->logSharedContext ?? [],
        );

        return new self($channel, $logger->getLogger(), $logger->getEventDispatcher());
    }

    public function &__get(string $name): mixed
    {
        $scope = AttemptScope::current();

        if (! isset($scope->logContext[$this->channel])) {
            $ambient = AttemptScope::ambient();

            $scope->logContext[$this->channel] = $scope === $ambient
                ? []
                : array_merge($ambient->logContext[$this->channel] ?? [], $scope->logSharedContext ?? []);
        }

        return $scope->logContext[$this->channel];
    }

    public function __set(string $name, mixed $value): void
    {
        AttemptScope::current()->logContext[$this->channel] = $value;
    }
}
