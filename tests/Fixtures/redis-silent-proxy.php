<?php

declare(strict_types=1);

/*
 * A TCP proxy in front of Redis for tests/Integration/WorkerStealTest.php. While
 * the flag file exists, a connection that sends XREADGROUP goes silent for good:
 * the command never reaches Redis and no reply ever comes, as on a half-open
 * socket. Everything else, and every new connection, is passed on.
 *
 * Arguments: upstream address (tcp://host:port), flag file. Prints its own
 * host:port on the first line. Runs until it is terminated.
 */

[, $upstream, $flagFile] = $argv;

$server = stream_socket_server('tcp://127.0.0.1:0');

if ($server === false) {
    exit(1);
}

fwrite(STDOUT, stream_socket_get_name($server, false)."\n");

/** @var array<int, resource> $peers Each socket's other end, by socket id. */
$peers = [];
/** @var array<int, resource> $sockets */
$sockets = [];
/** @var array<int, true> $clients */
$clients = [];
/** @var array<int, true> $silent */
$silent = [];

while (true) {
    $read = [$server, ...array_values($sockets)];
    $write = $except = null;

    if (stream_select($read, $write, $except, 1) === false) {
        exit(1);
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server);
            $target = $client === false ? false : stream_socket_client($upstream);

            if ($client !== false && $target !== false) {
                $sockets[(int) $client] = $client;
                $sockets[(int) $target] = $target;
                $peers[(int) $client] = $target;
                $peers[(int) $target] = $client;
                $clients[(int) $client] = true;
            }

            continue;
        }

        $id = (int) $socket;

        // Closed by the other side of a pair handled earlier in this round.
        if (! isset($sockets[$id])) {
            continue;
        }

        $data = stream_socket_recvfrom($socket, 65536);
        $peer = $peers[$id];

        if ($data === '' || $data === false) {
            foreach ([$id, (int) $peer] as $closing) {
                unset($sockets[$closing], $peers[$closing], $clients[$closing], $silent[$closing]);
            }

            fclose($socket);
            fclose($peer);

            continue;
        }

        if (isset($clients[$id]) && stripos($data, 'XREADGROUP') !== false) {
            // The test removes the flag from another process, past PHP's stat cache.
            clearstatcache(true, $flagFile);

            if (is_file($flagFile)) {
                $silent[$id] = true;
            }
        }

        $clientId = isset($clients[$id]) ? $id : (int) $peer;

        if (! isset($silent[$clientId])) {
            fwrite($peer, $data);
        }
    }
}
