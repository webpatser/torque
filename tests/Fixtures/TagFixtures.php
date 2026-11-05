<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Webpatser\Torque\Contracts\Silenced;

/*
 * Fixtures for the job tag and Silenced tests. One file so the real-worker
 * fixture can require it too. Models are never saved: only their keys matter.
 */

class TagUser extends Model
{
    protected $table = 'tag_users';

    protected $guarded = [];
}

class TagPost extends Model
{
    protected $table = 'tag_posts';

    protected $guarded = [];
}

/** @param  array<string, mixed>  $attributes */
function tag_user(int $id, array $attributes = []): TagUser
{
    $user = (new TagUser)->forceFill(['id' => $id] + $attributes);
    $user->exists = true;

    return $user;
}

function tag_posts(int $count): EloquentCollection
{
    $posts = [];

    for ($i = 1; $i <= $count; $i++) {
        $post = (new TagPost)->forceFill(['id' => $i]);
        $post->exists = true;
        $posts[] = $post;
    }

    return new EloquentCollection($posts);
}

/** Models held in public typed properties, no SerializesModels. */
final class TagsPublicModelJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public TagUser $user, public EloquentCollection $posts) {}

    public function handle(): void {}
}

/** Models through SerializesModels, one protected. */
final class TagsSerializesJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(protected TagUser $owner, public EloquentCollection $posts, public int $n = 1) {}

    public function handle(): void {}
}

final class TagsExplicitJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly TagUser $user) {}

    /** @return list<string> */
    public function tags(): array
    {
        return ['user:'.$this->user->getKey(), 'explicit', str_repeat('x', 500)];
    }

    public function handle(): void {}
}

final class TagsThrowingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $marker = '') {}

    /** @return list<string> */
    public function tags(): array
    {
        throw new RuntimeException('tags() blew up');
    }

    public function handle(): void
    {
        if ($this->marker !== '') {
            file_put_contents($this->marker, "ran\n", FILE_APPEND);
        }
    }
}

final class TagsSilencedJob implements ShouldQueue, Silenced
{
    use Queueable;

    public function handle(): void {}
}

final class TagsPlainJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $marker = '') {}

    public function handle(): void
    {
        if ($this->marker !== '') {
            file_put_contents($this->marker, "ran\n", FILE_APPEND);
        }
    }
}

/** tags() reads a real attribute, which only the real object at push time has. */
final class TagsTenantJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public TagUser $user, public string $marker = '') {}

    /** @return list<string> */
    public function tags(): array
    {
        return ['tenant:'.$this->user->tenant_id];
    }

    public function handle(): void
    {
        if ($this->marker !== '') {
            file_put_contents($this->marker, "ran\n", FILE_APPEND);
        }
    }
}

/** Every shape auto-tagging has to tell apart. */
final class TagsMixedPropertiesJob implements ShouldQueue
{
    use Queueable;

    /** @var mixed */
    public $untyped;

    public string $text = 'not a model';

    public TagUser $neverSet;

    public function __construct(TagUser $untyped, public TagUser $keyless, protected TagUser $hidden)
    {
        $this->untyped = $untyped;
    }

    public function handle(): void {}
}

final class TagsInvalidUtf8Job implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function tags(): array
    {
        return ["\xff\xfe", 'valid'];
    }

    public function handle(): void {}
}

final class TagsLogOnceJob implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function tags(): array
    {
        throw new RuntimeException('tags() blew up');
    }

    public function handle(): void {}
}

final class TagsEncryptedJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public TagUser $user) {}

    /** @return list<string> */
    public function tags(): array
    {
        throw new RuntimeException('tags() must not run for an encrypted job');
    }

    public function handle(): void {}
}

/** A DTO owning a resource: a stray unserialize would run these two hooks. */
final class TagsDto
{
    public int $ownerPid;

    public function __construct(public string $marker)
    {
        $this->ownerPid = getmypid();
    }

    public function __wakeup(): void
    {
        file_put_contents($this->marker, "wakeup\n", FILE_APPEND);
    }

    public function __destruct()
    {
        if (getmypid() !== $this->ownerPid) {
            file_put_contents($this->marker, "destruct\n", FILE_APPEND);
        }
    }
}

final class TagsDtoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public TagsDto $dto) {}

    public function handle(): void
    {
        file_put_contents($this->dto->marker, "handle\n", FILE_APPEND);
    }
}

final class TagsFailingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @return list<string> */
    public function tags(): array
    {
        return ['fail:me'];
    }

    public function handle(): void
    {
        throw new RuntimeException('always fails');
    }
}

final class TagsSilencedReleasingJob implements ShouldQueue, Silenced
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        $this->release(60);
    }
}

final class TagsSelfFailingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        $this->fail(new RuntimeException('failed without a throw'));
    }
}

/** Just enough of a queue job for the recorder's listeners. */
final class FakeQueueJob
{
    public int $payloadReads = 0;

    public bool $released = false;

    public bool $failed = false;

    /** @param  list<string>  $tags  What a push would have stored in the payload. */
    public function __construct(private string $uuid, private object $command, private array $tags = []) {}

    public function uuid(): string
    {
        return $this->uuid;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $this->payloadReads++;

        return [
            'displayName' => $this->command::class,
            'torqueTags' => $this->tags,
            'data' => ['commandName' => $this->command::class, 'command' => serialize($this->command)],
        ];
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function getQueue(): string
    {
        return 'default';
    }

    public function attempts(): int
    {
        return 1;
    }

    public function resolveName(): string
    {
        return $this->command::class;
    }
}
