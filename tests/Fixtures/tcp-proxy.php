<?php

declare(strict_types=1);

/*
 * A plain TCP forwarder. Workers boot through it against the real Redis; killing it
 * is then a real outage for them, with nothing listening on the port any more,
 * while the test itself still reaches Redis directly.
 *
 * Arguments: file to write the chosen port to, target host, target port.
 */

[, $portFile, $host, $port] = $argv;

$server = stream_socket_server('tcp://127.0.0.1:0');
file_put_contents($portFile, (string) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT));

/** @var array<int, resource> $peers socket id => the socket it forwards to */
$peers = [];
$sockets = [];

while (true) {
    $read = [$server, ...array_values($sockets)];
    $write = $except = null;

    if (stream_select($read, $write, $except, 1) === false) {
        continue;
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = @stream_socket_accept($server, 0);
            $upstream = @stream_socket_client("tcp://{$host}:{$port}");

            if ($client !== false && $upstream !== false) {
                $sockets[(int) $client] = $client;
                $sockets[(int) $upstream] = $upstream;
                $peers[(int) $client] = $upstream;
                $peers[(int) $upstream] = $client;
            }

            continue;
        }

        // Both ends can be readable in one pass: the first one closed this one.
        if (! isset($sockets[(int) $socket])) {
            continue;
        }

        $data = @fread($socket, 65536);

        if ($data === false || ($data === '' && feof($socket))) {
            $peer = $peers[(int) $socket];
            unset($sockets[(int) $socket], $sockets[(int) $peer], $peers[(int) $socket], $peers[(int) $peer]);
            fclose($socket);
            fclose($peer);

            continue;
        }

        @fwrite($peers[(int) $socket], $data);
    }
}
