<?php

declare(strict_types=1);

namespace Webpatser\Torque\Control;

use Fledge\Async\Redis\RedisClient;
use Webpatser\Torque\Support\Narrow;

/**
 * Remote commands for one master: a LIST per master, written by the CLI and
 * drained by the master's control tick.
 *
 * The issuer `RPUSH`es a JSON command to `{prefix}control:{masterId}` and
 * refreshes the list's TTL to {@see LIST_TTL_SECONDS}; the master `LPOP`s up
 * to {@see RECEIVE_BATCH} per tick. A command is exactly
 * `{id, action, issued_at, expires_at, issuer}` with one of two fixed,
 * argument-free actions. Anything else read from the list (malformed JSON,
 * extra or missing fields, an unknown action, an expired command) comes back
 * as a rejected entry with a reason code the caller logs; validation never
 * throws.
 *
 * The master acks each command it handled in `{prefix}control:ack:{id}` so the
 * issuer can wait for the outcome.
 *
 * @phpstan-type Command array{id: string, action: 'stop'|'drain', issued_at: int, expires_at: int, issuer: string}
 * @phpstan-type Received array{accepted: bool, reason: ?string, id: ?string, command: ?Command}
 * @phpstan-type Ack array{master: string, action: string, accepted: bool, at: int}
 */
final class ControlChannel
{
    public const string ACTION_STOP = 'stop';

    public const string ACTION_DRAIN = 'drain';

    /** @var list<string> */
    public const array ACTIONS = [self::ACTION_STOP, self::ACTION_DRAIN];

    public const int LIST_TTL_SECONDS = 120;

    public const int ACK_TTL_SECONDS = 300;

    public const int RECEIVE_BATCH = 10;

    /** Longest lifetime (`expires_at - issued_at`) a received command may claim. */
    public const int MAX_COMMAND_LIFETIME_SECONDS = 3600;

    public const string REASON_MALFORMED = 'malformed';

    public const string REASON_UNEXPECTED_FIELDS = 'unexpected_fields';

    public const string REASON_MISSING_FIELD = 'missing_field';

    public const string REASON_INVALID_FIELD = 'invalid_field';

    public const string REASON_UNKNOWN_ACTION = 'unknown_action';

    public const string REASON_EXPIRED = 'expired';

    private const array FIELDS = ['id', 'action', 'issued_at', 'expires_at', 'issuer'];

    private const string ID_PATTERN = '/^[0-9a-f]{32}$/D';

    /** Printable ASCII only, so an issuer can never inject into a log line. */
    private const string ISSUER_PATTERN = '/^[\x21-\x7E][\x20-\x7E]{0,127}$/D';

    private const int MAX_RAW_BYTES = 1024;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param  int  $commandTtlSeconds  Lifetime of a sent command (`torque.control.command_ttl_seconds`).
     * @param  (\Closure(): int)|null  $clock  Epoch seconds, `time()` by default.
     */
    public function __construct(
        private readonly RedisClient $redis,
        private readonly string $prefix,
        private readonly int $commandTtlSeconds = 60,
        ?\Closure $clock = null,
    ) {
        if ($commandTtlSeconds < 1 || $commandTtlSeconds > self::MAX_COMMAND_LIFETIME_SECONDS) {
            throw new \InvalidArgumentException('Command TTL must be between 1 and '.self::MAX_COMMAND_LIFETIME_SECONDS." seconds, got [{$commandTtlSeconds}].");
        }

        $this->clock = $clock ?? time(...);
    }

    public function listKey(string $masterId): string
    {
        return $this->prefix.'control:'.$masterId;
    }

    public function ackKey(string $commandId): string
    {
        return $this->prefix.'control:ack:'.$commandId;
    }

    /**
     * Queue a command for one master and return it.
     *
     * @return Command
     *
     * @throws \InvalidArgumentException On an invalid master id, action or issuer.
     */
    public function send(string $masterId, string $action, string $issuer): array
    {
        self::assertMasterId($masterId);

        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown control action [{$action}].");
        }

        if (preg_match(self::ISSUER_PATTERN, $issuer) !== 1) {
            throw new \InvalidArgumentException('Issuer must be 1 to 128 printable ASCII characters.');
        }

        $now = ($this->clock)();

        /** @var 'stop'|'drain' $action */
        $command = [
            'id' => bin2hex(random_bytes(16)),
            'action' => $action,
            'issued_at' => $now,
            'expires_at' => $now + $this->commandTtlSeconds,
            'issuer' => $issuer,
        ];

        $key = $this->listKey($masterId);
        $this->redis->execute('RPUSH', $key, json_encode($command, JSON_THROW_ON_ERROR));
        $this->redis->execute('EXPIRE', $key, (string) self::LIST_TTL_SECONDS);

        return $command;
    }

    /**
     * Pop up to {@see RECEIVE_BATCH} commands for this master, each validated.
     *
     * @return list<Received>
     */
    #[\NoDiscard]
    public function receive(string $masterId): array
    {
        self::assertMasterId($masterId);

        $reply = $this->redis->execute('LPOP', $this->listKey($masterId), (string) self::RECEIVE_BATCH);

        if ($reply === null) {
            return [];
        }

        $raws = is_array($reply) ? Narrow::list($reply) : [$reply];
        $received = [];

        foreach ($raws as $raw) {
            $received[] = $this->validate($raw);
        }

        return $received;
    }

    /**
     * Validate one raw entry read from the list. Never throws.
     *
     * The id is reported for a rejected command whenever it validated, so the
     * caller can still ack the rejection.
     *
     * @return Received
     */
    #[\NoDiscard]
    public function validate(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_RAW_BYTES) {
            return self::rejected(self::REASON_MALFORMED);
        }

        try {
            $decoded = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::rejected(self::REASON_MALFORMED);
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return self::rejected(self::REASON_MALFORMED);
        }

        $id = $decoded['id'] ?? null;
        $id = is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;

        if (array_diff(array_map(strval(...), array_keys($decoded)), self::FIELDS) !== []) {
            return self::rejected(self::REASON_UNEXPECTED_FIELDS, $id);
        }

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $decoded)) {
                return self::rejected(self::REASON_MISSING_FIELD, $id);
            }
        }

        if ($id === null) {
            return self::rejected(self::REASON_INVALID_FIELD);
        }

        $action = $decoded['action'];

        if (! is_string($action)) {
            return self::rejected(self::REASON_INVALID_FIELD, $id);
        }

        if (! in_array($action, self::ACTIONS, true)) {
            return self::rejected(self::REASON_UNKNOWN_ACTION, $id);
        }

        $issuedAt = $decoded['issued_at'];
        $expiresAt = $decoded['expires_at'];
        $issuer = $decoded['issuer'];

        if (
            ! is_int($issuedAt) || ! is_int($expiresAt) || $issuedAt < 1
            || $expiresAt <= $issuedAt || $expiresAt - $issuedAt > self::MAX_COMMAND_LIFETIME_SECONDS
            || ! is_string($issuer) || preg_match(self::ISSUER_PATTERN, $issuer) !== 1
        ) {
            return self::rejected(self::REASON_INVALID_FIELD, $id);
        }

        /** @var 'stop'|'drain' $action */
        $command = [
            'id' => $id,
            'action' => $action,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'issuer' => $issuer,
        ];

        if ($this->isExpired($command)) {
            return ['accepted' => false, 'reason' => self::REASON_EXPIRED, 'id' => $id, 'command' => $command];
        }

        return ['accepted' => true, 'reason' => null, 'id' => $id, 'command' => $command];
    }

    /**
     * @param  Command  $command
     */
    #[\NoDiscard]
    public function isExpired(array $command): bool
    {
        return $command['expires_at'] <= ($this->clock)();
    }

    /**
     * Record the outcome of a command. An action outside the fixed set is
     * stored as `unknown`; an invalid id or master id writes nothing.
     */
    public function ack(string $commandId, string $masterId, ?string $action, bool $accepted): bool
    {
        if (preg_match(self::ID_PATTERN, $commandId) !== 1 || ! MasterRegistry::isValidMasterId($masterId)) {
            return false;
        }

        $payload = [
            'master' => $masterId,
            'action' => in_array($action, self::ACTIONS, true) ? $action : 'unknown',
            'accepted' => $accepted,
            'at' => ($this->clock)(),
        ];

        $this->redis->execute(
            'SET',
            $this->ackKey($commandId),
            json_encode($payload, JSON_THROW_ON_ERROR),
            'EX',
            (string) self::ACK_TTL_SECONDS,
        );

        return true;
    }

    /**
     * The ack for a command, or null when none (or none that validates) exists.
     *
     * @return Ack|null
     */
    #[\NoDiscard]
    public function readAck(string $commandId): ?array
    {
        if (preg_match(self::ID_PATTERN, $commandId) !== 1) {
            return null;
        }

        $raw = $this->redis->execute('GET', $this->ackKey($commandId));

        if (! is_string($raw) || strlen($raw) > self::MAX_RAW_BYTES) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $master = $decoded['master'] ?? null;
        $action = $decoded['action'] ?? null;
        $accepted = $decoded['accepted'] ?? null;
        $at = $decoded['at'] ?? null;

        if (
            ! is_string($master) || ! MasterRegistry::isValidMasterId($master)
            || ! is_string($action) || ! in_array($action, [...self::ACTIONS, 'unknown'], true)
            || ! is_bool($accepted) || ! is_int($at)
        ) {
            return null;
        }

        return ['master' => $master, 'action' => $action, 'accepted' => $accepted, 'at' => $at];
    }

    /**
     * @return Received
     */
    private static function rejected(string $reason, ?string $id = null): array
    {
        return ['accepted' => false, 'reason' => $reason, 'id' => $id, 'command' => null];
    }

    private static function assertMasterId(string $masterId): void
    {
        if (! MasterRegistry::isValidMasterId($masterId)) {
            throw new \InvalidArgumentException("Invalid master id [{$masterId}].");
        }
    }
}
