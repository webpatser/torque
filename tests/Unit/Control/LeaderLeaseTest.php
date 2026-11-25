<?php

declare(strict_types=1);

use Webpatser\Torque\Control\LeaderLease;

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-leader-'.bin2hex(random_bytes(4)).':';
    $this->key = $this->prefix.'leader';
    $this->lease = new LeaderLease($this->redis, $this->prefix);
    $this->a = 'web-1-100-0000000a';
    $this->b = 'web-2-200-0000000b';

    // Let the current holder's lease lapse without sleeping for its TTL.
    $this->expire = function (): void {
        $this->redis->execute('PEXPIRE', $this->key, '1');
        usleep(20_000);
    };
});

afterEach(function () {
    $this->redis->execute('DEL', $this->key);
});

it('lets only one of two contenders acquire', function () {
    expect($this->lease->acquire($this->a))->toBeTrue()
        ->and($this->lease->acquire($this->b))->toBeFalse()
        ->and($this->lease->leader())->toBe($this->a)
        ->and($this->lease->isLeader($this->a))->toBeTrue()
        ->and($this->lease->isLeader($this->b))->toBeFalse();

    $ttl = $this->redis->execute('TTL', $this->key);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(LeaderLease::TTL_SECONDS);
});

it('renews only for the holder', function () {
    $this->lease->acquire($this->a);
    $this->redis->execute('PEXPIRE', $this->key, '2000');

    expect($this->lease->renew($this->b))->toBeFalse()
        ->and($this->redis->execute('PTTL', $this->key))->toBeLessThanOrEqual(2000)
        ->and($this->lease->renew($this->a))->toBeTrue()
        ->and($this->redis->execute('PTTL', $this->key))->toBeGreaterThan(2000);
});

it('releases only for the holder', function () {
    $this->lease->acquire($this->a);

    expect($this->lease->release($this->b))->toBeFalse()
        ->and($this->lease->leader())->toBe($this->a)
        ->and($this->lease->release($this->a))->toBeTrue()
        ->and($this->lease->leader())->toBeNull();
});

it('lets another master take over after the lease expired', function () {
    $this->lease->acquire($this->a);
    ($this->expire)();

    expect($this->lease->leader())->toBeNull()
        ->and($this->lease->acquire($this->b))->toBeTrue()
        ->and($this->lease->renew($this->a))->toBeFalse()
        ->and($this->lease->release($this->a))->toBeFalse()
        ->and($this->lease->leader())->toBe($this->b);
});

it('maintains by renewing when held and acquiring when free', function () {
    expect($this->lease->maintain($this->a))->toBeTrue()
        ->and($this->lease->maintain($this->b))->toBeFalse()
        ->and($this->lease->maintain($this->a))->toBeTrue();

    ($this->expire)();

    expect($this->lease->maintain($this->b))->toBeTrue()
        ->and($this->lease->leader())->toBe($this->b);
});

it('uses the ttl argument', function () {
    $lease = new LeaderLease($this->redis, $this->prefix, 3);
    $lease->acquire($this->a);

    expect($this->redis->execute('TTL', $this->key))->toBeLessThanOrEqual(3)
        ->and($lease->renew($this->a))->toBeTrue()
        ->and($this->redis->execute('PTTL', $this->key))->toBeLessThanOrEqual(3000);
});

it('reads a value that is not a master id as no leader', function () {
    $this->redis->execute('SET', $this->key, "evil\nvalue");

    expect($this->lease->leader())->toBeNull()
        ->and($this->lease->acquire($this->a))->toBeFalse();
});

it('refuses an invalid master id', function () {
    $this->lease->acquire('nope');
})->throws(InvalidArgumentException::class);
