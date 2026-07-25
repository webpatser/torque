<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

/**
 * Parses worker consumer ids of the form `{host}-{pid}-{hex8}` as generated
 * by WorkerProcess (gethostname().'-'.getmypid().'-'.bin2hex(random_bytes(4))).
 *
 * Hostnames may themselves contain dashes, so parsing walks from the right:
 * the last segment is the 8-hex nonce, the second-to-last is the numeric PID,
 * everything before that is the host.
 */
final readonly class WorkerId
{
    private function __construct(
        public string $host,
        public ?int $pid,
        public ?string $nonce,
    ) {}

    public static function parse(string $id): self
    {
        $parts = explode('-', $id);

        if (count($parts) >= 3) {
            $nonce = array_pop($parts);
            $pidPart = array_pop($parts);

            if (ctype_xdigit($nonce) && strlen($nonce) === 8 && ctype_digit($pidPart)) {
                return new self(implode('-', $parts), (int) $pidPart, $nonce);
            }

            // Not the canonical 3+ segment shape; restore and fall through.
            $parts[] = $pidPart;
            $parts[] = $nonce;
        }

        // Legacy / fabricated ids: `{host}-{pid}` with a numeric tail.
        if (count($parts) >= 2 && ctype_digit(end($parts))) {
            $pid = (int) array_pop($parts);

            return new self(implode('-', $parts), $pid, null);
        }

        return new self($id, null, null);
    }
}
