<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;

/**
 * DeadLetterRetryProbeJob whose serialized command is encrypted in the payload.
 */
final class EncryptedDeadLetterRetryProbeJob extends DeadLetterRetryProbeJob implements ShouldBeEncrypted {}
