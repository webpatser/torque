<?php

declare(strict_types=1);

use Webpatser\Torque\Job\DeadLetterHandler;

function createPaginationHandler(string $prefix): DeadLetterHandler
{
    return new DeadLetterHandler(
        redisUri: env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
        prefix: $prefix,
    );
}

function cleanupDeadLetterStream(string $prefix): void
{
    $redis = \Fledge\Async\Redis\createRedisClient(
        env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
    );
    $redis->execute('DEL', $prefix . 'dead-letter');
}

it('lists entries before a given cursor ID', function () {
    $prefix = 'torque-dl-page-' . bin2hex(random_bytes(4)) . ':';
    $handler = createPaginationHandler($prefix);

    try {
        // Insert 5 entries.
        for ($i = 1; $i <= 5; $i++) {
            $handler->handle(
                queue: 'default',
                payload: '{"uuid":"page-' . $i . '"}',
                messageId: '1680000000000-' . $i,
                exception: new RuntimeException('Error ' . $i),
            );
        }

        // Get the first page (newest 3).
        $firstPage = $handler->list(count: 3);
        expect($firstPage)->toHaveCount(3);

        // The cursor is the last entry on the first page.
        $cursor = end($firstPage)['id'];

        // Get the second page (entries before the cursor).
        $secondPage = $handler->listBefore($cursor, count: 3);
        expect($secondPage)->toHaveCount(2); // Only 2 remaining.

        // Pages should not overlap.
        $firstPageIds = array_column($firstPage, 'id');
        $secondPageIds = array_column($secondPage, 'id');

        foreach ($secondPageIds as $id) {
            expect($firstPageIds)->not->toContain($id);
        }

        // Second page entries should be older (lower IDs).
        foreach ($secondPageIds as $id) {
            foreach ($firstPageIds as $firstId) {
                expect($id < $firstId)->toBeTrue();
            }
        }

        cleanupDeadLetterStream($prefix);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns empty when cursor is before all entries', function () {
    $prefix = 'torque-dl-page-empty-' . bin2hex(random_bytes(4)) . ':';
    $handler = createPaginationHandler($prefix);

    try {
        $handler->handle(
            queue: 'default',
            payload: '{"uuid":"single-1"}',
            messageId: '1680000000000-0',
            exception: new RuntimeException('Only entry'),
        );

        $entries = $handler->list(count: 10);
        expect($entries)->toHaveCount(1);

        // Use the only entry's ID as cursor (listBefore is exclusive).
        $result = $handler->listBefore($entries[0]['id'], count: 10);
        expect($result)->toBe([]);

        cleanupDeadLetterStream($prefix);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('respects count limit', function () {
    $prefix = 'torque-dl-page-limit-' . bin2hex(random_bytes(4)) . ':';
    $handler = createPaginationHandler($prefix);

    try {
        // Insert 10 entries.
        for ($i = 1; $i <= 10; $i++) {
            $handler->handle(
                queue: 'default',
                payload: '{"uuid":"limit-' . $i . '"}',
                messageId: '1680000000000-' . $i,
                exception: new RuntimeException('Error ' . $i),
            );
        }

        // Get first 3 entries.
        $first = $handler->list(count: 3);
        expect($first)->toHaveCount(3);

        // Get next 2 after cursor.
        $cursor = end($first)['id'];
        $second = $handler->listBefore($cursor, count: 2);
        expect($second)->toHaveCount(2);

        cleanupDeadLetterStream($prefix);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns entries in newest-first order', function () {
    $prefix = 'torque-dl-page-order-' . bin2hex(random_bytes(4)) . ':';
    $handler = createPaginationHandler($prefix);

    try {
        for ($i = 1; $i <= 5; $i++) {
            $handler->handle(
                queue: 'default',
                payload: '{"uuid":"order-' . $i . '"}',
                messageId: '1680000000000-' . $i,
                exception: new RuntimeException('Error ' . $i),
            );
        }

        $first = $handler->list(count: 2);
        $cursor = end($first)['id'];
        $second = $handler->listBefore($cursor, count: 10);

        // Each ID should be less than the previous (newest first).
        for ($i = 1; $i < count($second); $i++) {
            expect($second[$i]['id'] < $second[$i - 1]['id'])->toBeTrue();
        }

        cleanupDeadLetterStream($prefix);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('preserves entry structure in paginated results', function () {
    $prefix = 'torque-dl-page-struct-' . bin2hex(random_bytes(4)) . ':';
    $handler = createPaginationHandler($prefix);

    try {
        $handler->handle(
            queue: 'high-priority',
            payload: '{"uuid":"struct-1","job":"App\\\\Jobs\\\\TestJob"}',
            messageId: '1680000000000-0',
            exception: new RuntimeException('Structure test'),
        );

        $handler->handle(
            queue: 'default',
            payload: '{"uuid":"struct-2"}',
            messageId: '1680000000001-0',
            exception: new \InvalidArgumentException('Second error'),
        );

        // Get latest entry.
        $latest = $handler->list(count: 1);
        $cursor = $latest[0]['id'];

        // Get the older entry via pagination.
        $older = $handler->listBefore($cursor, count: 1);
        expect($older)->toHaveCount(1);

        $entry = $older[0];
        expect($entry)->toHaveKey('id')
            ->toHaveKey('payload')
            ->toHaveKey('original_queue')
            ->toHaveKey('exception_class')
            ->toHaveKey('exception_message')
            ->toHaveKey('exception_trace')
            ->toHaveKey('failed_at');

        expect($entry['original_queue'])->toBe('high-priority');
        expect($entry['exception_class'])->toBe('RuntimeException');
        expect($entry['exception_message'])->toBe('Structure test');

        cleanupDeadLetterStream($prefix);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
