<?php
error_reporting(E_ALL);

// You can use print statements as follows for debugging, they'll be visible when running tests.
echo "Logs from your program will appear here";

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
// Since the tester restarts your program quite often, setting SO_REUSEADDR
// ensures that we don't run into 'Address already in use' errors
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($sock, "localhost", 6379);
socket_listen($sock, 5);

socket_set_nonblock($sock);

$clients = [];

while (true) {
    $read = array_merge([$sock], $clients);
    $write = null;
    $except = null;

    if (socket_select($read, $write, $except, null) < 1) {
        continue;
    }

    foreach ($read as $readable) {
        if ($readable === $sock) {
            $client = socket_accept($sock);
            if ($client !== false) {
                socket_set_nonblock($client);
                $clients[(int) $client] = $client;
            }
            continue;
        }

        $data = @socket_read($readable, 1024);
        if ($data === false || $data === "") {
            unset($clients[(int) $readable]);
            socket_close($readable);
            continue;
        }

        socket_write($readable, "+PONG\r\n");
    }
}

socket_close($sock);
