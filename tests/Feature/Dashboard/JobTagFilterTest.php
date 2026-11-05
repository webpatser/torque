<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Data\JobsData;
use Webpatser\Torque\Dashboard\Livewire\Feed;
use Webpatser\Torque\Stream\JobStreamRecorder;

beforeEach(function () {
    Gate::define('viewTorque', fn ($user): bool => true);

    $this->recorder = new JobStreamRecorder(redisUri: torqueRedisUri(), prefix: 'torque-test:');
    $token = bin2hex(random_bytes(4));

    // a: finished, tagged alpha. b: finished, tagged beta. c: untagged.
    // d: still running, last event is progress.
    [$this->a, $this->b, $this->c, $this->d] = ["tagfilter-a-$token", "tagfilter-b-$token", "tagfilter-c-$token", "tagfilter-d-$token"];

    foreach ([[$this->a, ['alpha', 'shared']], [$this->b, ['beta', 'shared']]] as [$uuid, $tags]) {
        $this->recorder->record($uuid, 'queued', ['queue' => 'default', 'displayName' => 'App\\Jobs\\Tagged', 'tags' => json_encode($tags)]);
        $this->recorder->record($uuid, 'started', ['tags' => json_encode($tags)]);
        $this->recorder->record($uuid, 'completed', ['tags' => json_encode($tags)], terminal: true);
    }

    $this->recorder->record($this->c, 'queued', ['queue' => 'default', 'displayName' => 'App\\Jobs\\Plain']);
    $this->recorder->record($this->c, 'completed', [], terminal: true);

    $this->recorder->record($this->d, 'started', ['queue' => 'default', 'displayName' => 'App\\Jobs\\Tagged', 'tags' => json_encode(['alpha'])]);
    $this->recorder->record($this->d, 'progress', ['message' => 'half', 'progress' => '0.5']);
});

afterEach(function () {
    $redis = torqueRedis();

    foreach ([$this->a, $this->b, $this->c, $this->d] as $uuid) {
        $redis->execute('DEL', 'torque-test:job:'.$uuid);
        $redis->execute('ZREM', 'torque-test:jobs:recent', $uuid);
        $redis->execute('ZREM', 'torque-test:jobs:active', $uuid);
    }
});

it('filters the jobs list by tag', function () {
    $ids = fn (?string $tag): array => array_values(array_intersect(
        array_column(app(JobsData::class)->list('all', null, $tag), 'id'),
        [$this->a, $this->b, $this->c, $this->d],
    ));

    expect($ids('alpha'))->toEqualCanonicalizing([$this->a, $this->d])
        ->and($ids('beta'))->toBe([$this->b])
        ->and($ids('shared'))->toEqualCanonicalizing([$this->a, $this->b])
        ->and($ids('nope'))->toBe([])
        ->and($ids(null))->toEqualCanonicalizing([$this->a, $this->b, $this->c, $this->d]);
});

it('reads a job\'s tags without walking its whole stream', function () {
    // Tags only on an event in the middle: reachable by the old deep read alone.
    $uuid = 'tagfilter-deep-'.bin2hex(random_bytes(4));
    $this->recorder->record($uuid, 'queued', ['queue' => 'default', 'displayName' => 'App\\Jobs\\Tagged']);
    $this->recorder->record($uuid, 'started', ['tags' => json_encode(['deep'])]);
    $this->recorder->record($uuid, 'progress', ['message' => 'half']);

    try {
        expect(app(JobsData::class)->list('all', null, 'deep'))->toBe([]);
    } finally {
        $redis = torqueRedis();
        $redis->execute('DEL', 'torque-test:job:'.$uuid);
        $redis->execute('ZREM', 'torque-test:jobs:recent', $uuid);
        $redis->execute('ZREM', 'torque-test:jobs:active', $uuid);
    }
});

it('trims the bound input and renders only the matching rows, with their tags', function () {
    Livewire::actingAs(torqueTestUser())->test(Feed::class)
        ->assertSet('tag', '')
        ->set('tag', '  beta ')
        ->assertSet('tag', 'beta')
        ->assertSee($this->b)
        ->assertSee('shared')
        ->assertDontSee($this->a)
        ->assertDontSee($this->c)
        ->assertDontSee($this->d);
});

it('says the tag filter searches the latest 100 jobs', function () {
    Livewire::actingAs(torqueTestUser())->test(Feed::class)->assertSee('latest 100');
});
