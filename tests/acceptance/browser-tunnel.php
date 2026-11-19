<?php

declare(strict_types=1);

// 测试专用 TCP 转发，只连接同容器的固定验收端口，不接受外部目标地址。
$socket = stream_socket_client('tcp://127.0.0.1:18770', $errorCode, $error, 5);
if ($socket === false) {
    fwrite(STDERR, $error);
    exit(1);
}
while (true) {
    $read = [STDIN, $socket];
    $write = null;
    $except = null;
    $ready = stream_select($read, $write, $except, 30);
    if ($ready === false) {
        fclose($socket);
        exit(1);
    }
    if ($ready === 0) {
        break;
    }
    foreach ($read as $source) {
        $data = fread($source, 65536);
        if ($data === false || $data === '') {
            fclose($socket);
            exit(0);
        }
        $target = $source === STDIN ? $socket : STDOUT;
        while ($data !== '') {
            $written = fwrite($target, $data);
            if ($written === false || $written === 0) {
                exit(1);
            }
            $data = substr($data, $written);
        }
        fflush($target);
    }
}
fclose($socket);
