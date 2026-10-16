// Runs inside a private PID, mount and network namespace. The gateway is outside.
import { chromium } from 'playwright';
import { mkdtemp, readFile } from 'node:fs/promises';
import net from 'node:net';
import readline from 'node:readline';

const channel = net.connect('/ipc/control.sock');
const send = (value) => channel.write(JSON.stringify(value) + '\n');
const bridge = (socket) => net.createServer((client) => {
    const peer = net.connect(socket);
    client.pipe(peer).pipe(client);
    client.on('error', () => peer.destroy());
    peer.on('error', () => client.destroy());
});
const proxy = bridge('/ipc/proxy.sock');
await new Promise((resolve) => proxy.listen(0, '127.0.0.1', resolve));
const profile = await mkdtemp('/tmp/profile-');
const context = await chromium.launchPersistentContext(profile, {
    headless: true,
    chromiumSandbox: true,
    serviceWorkers: 'block',
    acceptDownloads: false,
    proxy: { server: `http://127.0.0.1:${proxy.address().port}`, bypass: '<-loopback>' },
    args: ['--remote-debugging-port=0'],
});
context.setDefaultTimeout(10_000);
context.setDefaultNavigationTimeout(30_000);
const [port, path] = (await readFile(profile + '/DevToolsActivePort', 'utf8')).trim().split('\n');
const control = net.createServer((client) => {
    const peer = net.connect(Number(port), '127.0.0.1');
    client.pipe(peer).pipe(client);
    client.on('error', () => peer.destroy());
    peer.on('error', () => client.destroy());
});
await new Promise((resolve) => control.listen('/ipc/cdp.sock', resolve));
send({ ready: true, path });
const page = context.pages()[0];
// Exactly one CDP client handles dialogs. The external client only observes.
// A script can install its own Playwright handler; that replaces this default.
page.on('dialog', (dialog) => {
    if (page.listenerCount('dialog') === 1) dialog.dismiss().catch(() => {});
});
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
for await (const line of readline.createInterface({ input: channel })) {
    const command = JSON.parse(line);
    try {
        const run = new AsyncFunction('page', 'context', 'target_url', 'actor', command.code);
        const result = await run(page, context, command.target_url, command.actor_values || {});
        const encoded = JSON.stringify(result ?? null);
        send({ id: command.id, status: 'ok', output: encoded.length <= 24_000
            ? JSON.parse(encoded) : encoded.slice(0, 24_000), truncated: encoded.length > 24_000 });
    } catch (error) {
        send({ id: command.id, status: 'error', error_code: 'script_error',
            error: String(error?.stack || error).slice(0, 8000) });
    }
}
await context.close();
control.close();
proxy.close();
