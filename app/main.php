<?php
error_reporting(E_ALL);

// You can use print statements as follows for debugging, they'll be visible when running tests.
echo "Logs from your program will appear here";

function parseResp(string $buffer, int $offset): ?array
{
    if ($offset >= strlen($buffer)) {
        return null;
    }

    $type = $buffer[$offset];
    $offset++;

    $crlfPos = strpos($buffer, "\r\n", $offset);
    if ($crlfPos === false) {
        return null;
    }

    $line = substr($buffer, $offset, $crlfPos - $offset);
    $offset = $crlfPos + 2;

    switch ($type) {
        case '+':
        case '-':
            return [$line, $offset];
        case ':':
            return [(int) $line, $offset];
        case '$':
            $len = (int) $line;
            if ($len === -1) {
                return [null, $offset];
            }
            if (strlen($buffer) < $offset + $len + 2) {
                return null;
            }
            $str = substr($buffer, $offset, $len);
            return [$str, $offset + $len + 2];
        case '*':
            $count = (int) $line;
            if ($count === -1) {
                return [null, $offset];
            }
            $arr = [];
            for ($i = 0; $i < $count; $i++) {
                $res = parseResp($buffer, $offset);
                if ($res === null) {
                    return null;
                }
                $arr[] = $res[0];
                $offset = $res[1];
            }
            return [$arr, $offset];
    }

    return null;
}

function encodeBulkString(string $value): string
{
    return '$' . strlen($value) . "\r\n" . $value . "\r\n";
}

function handleCommand(array $command): string
{
    if (count($command) === 0) {
        return "";
    }

    $name = strtoupper((string) $command[0]);

    switch ($name) {
        case 'PING':
            return "+PONG\r\n";
        case 'ECHO':
            if (count($command) < 2) {
                return "-ERR wrong number of arguments for 'echo' command\r\n";
            }
            return encodeBulkString((string) $command[1]);
    }

    return "-ERR unknown command '" . $name . "'\r\n";
}

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
// Since the tester restarts your program quite often, setting SO_REUSEADDR
// ensures that we don't run into 'Address already in use' errors
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($sock, "localhost", 6379);
socket_listen($sock, 5);

socket_set_nonblock($sock);

$clients = [];
$buffers = [];

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
                $id = spl_object_id($client);
                $clients[$id] = $client;
                $buffers[$id] = '';
            }
            continue;
        }

        $id = spl_object_id($readable);
        $data = @socket_read($readable, 1024);
        if ($data === false || $data === "") {
            unset($clients[$id], $buffers[$id]);
            socket_close($readable);
            continue;
        }

        $buffers[$id] .= $data;

        while (true) {
            $parsed = parseResp($buffers[$id], 0);
            if ($parsed === null) {
                break;
            }

            [$value, $newOffset] = $parsed;
            $buffers[$id] = substr($buffers[$id], $newOffset);

            if (is_array($value)) {
                socket_write($readable, handleCommand($value));
            }
        }
    }
}

socket_close($sock);
