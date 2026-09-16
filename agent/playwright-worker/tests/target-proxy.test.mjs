import assert from 'node:assert/strict';
import net from 'node:net';
import { test } from 'node:test';
import { createTargetProxy } from '../target-proxy.mjs';

test('HTTPS proxy permits only the target authority and transports opaque bytes', async () => {
    // Echo opaque bytes at the target: the proxy must neither parse nor terminate TLS.
    const target = net.createServer((socket) => socket.pipe(socket));
    await new Promise((resolve) => target.listen(0, '127.0.0.1', resolve));
    const authority = `127.0.0.1:${target.address().port}`;
    const blocks = [];
    const proxy = await createTargetProxy(`https://${authority}`, (row) =>
        blocks.push(row),
    );
    const port = Number(new URL(proxy.url).port);
    try {
        const open = (host) =>
            new Promise((resolve, reject) => {
                const socket = net.connect(port, '127.0.0.1', () =>
                    socket.write(
                        `CONNECT ${host} HTTP/1.1\r\nHost: ${host}\r\n\r\n`,
                    ),
                );
                socket.on('error', reject);
                socket.once('data', (buffer) =>
                    resolve({ socket, header: buffer.toString() }),
                );
            });
        const denied = await open('outside.invalid:443');
        assert.match(denied.header, /403 Forbidden/);
        denied.socket.destroy();
        assert.equal(blocks.length, 1);
        const allowed = await open(authority);
        assert.match(allowed.header, /200 Connection Established/);
        const bytes = Buffer.from([0x16, 0x03, 0x01, 0x00, 0x02, 0x42, 0x43]);
        const echoed = new Promise((resolve) =>
            allowed.socket.once('data', resolve),
        );
        allowed.socket.write(bytes);
        assert.deepEqual(await echoed, bytes);
        allowed.socket.destroy();
    } finally {
        await proxy.close();
        await new Promise((resolve) => target.close(resolve));
    }
});
