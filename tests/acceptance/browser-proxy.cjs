'use strict';

const net = require('node:net');
const { spawn } = require('node:child_process');

// 只在本机回环地址暴露测试入口；转发至固定 Docker 容器中的真实 HTTP 服务。
const server = net.createServer((socket) => {
    const tunnel = spawn('docker', ['exec', '-i', 'lw-laravel-db-test', 'php', '/app/tests/acceptance/browser-tunnel.php'], { stdio: ['pipe', 'pipe', 'pipe'] });
    socket.pipe(tunnel.stdin);
    tunnel.stdout.pipe(socket);
    tunnel.stderr.on('data', (data) => process.stderr.write(data));
    socket.on('error', () => tunnel.kill());
    socket.on('close', () => tunnel.kill());
    tunnel.stdin.on('error', () => socket.destroy());
    tunnel.on('error', () => socket.destroy());
    tunnel.on('exit', () => socket.end());
});
server.listen(19070, '127.0.0.1', () => console.log('Acceptance browser proxy ready on 127.0.0.1:19070'));
