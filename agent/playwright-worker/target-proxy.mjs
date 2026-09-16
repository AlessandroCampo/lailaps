import http from 'node:http';
import net from 'node:net';

// Browser-native navigation (including redirects and TLS) goes through this
// private proxy. Playwright route() alone only intercepts the first redirect URL.
// No HTML rewriting, API-response replay, credentials or model-selected hosts.
export async function createTargetProxy(target, onBlocked) {
    const origin = new URL(target);
    const sockets = new Set();
    const agent = new http.Agent({ keepAlive: true, maxSockets: 32 });
    const track = (socket) => {
        if (sockets.has(socket)) return socket;
        sockets.add(socket);
        socket.on('close', () => sockets.delete(socket));
        return socket;
    };
    const blocked = (url, method) =>
        onBlocked({
            type: 'blocked',
            url,
            method,
            resource_type: 'proxy',
        });
    const server = http.createServer((request, response) => {
        let destination;
        try {
            destination = new URL(request.url);
        } catch {
            response.writeHead(400);
            response.end();
            return;
        }
        if (
            destination.origin !== origin.origin ||
            destination.protocol !== 'http:' ||
            destination.username ||
            destination.password
        ) {
            blocked(destination.href, request.method);
            response.writeHead(403);
            response.end();
            return;
        }
        const headers = { ...request.headers, host: destination.host };
        delete headers['proxy-authorization'];
        delete headers['proxy-connection'];
        const upstream = http.request(
            destination,
            { method: request.method, headers, agent },
            (incoming) => {
                response.writeHead(incoming.statusCode, incoming.rawHeaders);
                incoming.pipe(response);
            },
        );
        upstream.on('socket', track);
        upstream.on('error', () => {
            if (!response.headersSent) response.writeHead(502);
            response.end();
        });
        request.on('aborted', () => upstream.destroy());
        response.on('close', () => upstream.destroy());
        request.pipe(upstream);
    });
    server.on('connection', track);
    server.on('connect', (request, client, head) => {
        let destination;
        try {
            destination = new URL(`https://${request.url}`);
        } catch {
            client.destroy();
            return;
        }
        if (
            origin.protocol !== 'https:' ||
            destination.origin !== origin.origin ||
            destination.username ||
            destination.password
        ) {
            blocked(destination.href, 'CONNECT');
            client.end('HTTP/1.1 403 Forbidden\r\n\r\n');
            return;
        }
        const upstream = track(
            net.connect(Number(origin.port || 443), origin.hostname, () => {
                client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
                if (head.length) upstream.write(head);
                client.pipe(upstream);
                upstream.pipe(client);
            }),
        );
        upstream.on('error', () => client.destroy());
        client.on('error', () => upstream.destroy());
        client.on('close', () => upstream.destroy());
        upstream.on('close', () => client.destroy());
    });
    server.on('upgrade', (request, socket) => {
        blocked(request.url, request.method);
        socket.destroy();
    });
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    return {
        url: `http://127.0.0.1:${server.address().port}`,
        async close() {
            agent.destroy();
            for (const socket of sockets) socket.destroy();
            await new Promise((resolve) => server.close(resolve));
        },
    };
}
