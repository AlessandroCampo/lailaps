import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';
import readline from 'node:readline';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

// Bubblewrap provides the OS boundary, not a JS evaluator or a locator whitelist.
// Only this session's proxy and CDP sockets cross the namespace boundary.
export async function createCodeSession({ proxyUrl, onOutput, timeoutMs = 60_000 }) {
    const directory = await mkdtemp(join(tmpdir(), 'browser-code-'));
    const ipc = join(directory, 'ipc');
    await mkdir(ipc);
    const target = new URL(proxyUrl);
    const sockets = new Set();
    const proxy = net.createServer((client) => {
        const upstream = net.connect(Number(target.port), target.hostname);
        for (const socket of [client, upstream]) {
            sockets.add(socket);
            socket.on('close', () => sockets.delete(socket));
            socket.on('error', () => { client.destroy(); upstream.destroy(); });
        }
        client.pipe(upstream).pipe(client);
    });
    await new Promise((resolve, reject) => {
        proxy.once('error', reject);
        proxy.listen(join(ipc, 'proxy.sock'), resolve);
    });
    const root = dirname(fileURLToPath(import.meta.url));
    const args = ['--unshare-all', '--die-with-parent',
        '--ro-bind', '/usr', '/usr', '--ro-bind', '/lib', '/lib',
        '--ro-bind', '/lib64', '/lib64', '--ro-bind', '/etc', '/etc',
        '--ro-bind', '/ms-playwright', '/ms-playwright',
        '--dir', '/srv', '--ro-bind', join(root, 'node_modules'), '/srv/node_modules',
        '--ro-bind', join(root, 'browser-host.mjs'), '/srv/browser-host.mjs',
        '--bind', ipc, '/ipc', '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
        '--chdir', '/srv', '--clearenv', '--setenv', 'PATH', '/usr/bin:/bin',
        '--setenv', 'HOME', '/tmp', '--setenv', 'PLAYWRIGHT_BROWSERS_PATH', '/ms-playwright',
        '--', process.execPath, '/srv/browser-host.mjs'];
    let closing, browser, pending, readyResolve, readyReject, channel, controlSocket;
    const ready = new Promise((resolve, reject) => { readyResolve = resolve; readyReject = reject; });
    // Always attach a handler: a dead child may settle after startup has failed.
    ready.catch(() => {});
    const control = net.createServer((socket) => {
        if (controlSocket) { socket.destroy(); return; }
        controlSocket = socket;
        let lineBytes = 0;
        socket.on('data', (chunk) => {
            if (lineBytes + chunk.length > 128_000) {
                const error = new Error('Output del protocollo runner oltre il limite');
                readyReject(error);
                pending?.reject(error);
                socket.destroy();
                return;
            }
            const newline = chunk.lastIndexOf(10);
            lineBytes = newline < 0 ? lineBytes + chunk.length : chunk.length - newline - 1;
        });
        channel = readline.createInterface({ input: socket });
        channel.on('line', (line) => {
            try {
                const value = JSON.parse(line);
                if (value.ready) readyResolve(value);
                else if (value.id === pending?.id) pending.resolve(value);
            } catch { pending?.reject(new Error('Risposta runner non serializzabile')); }
        });
        socket.on('error', (error) => { readyReject(error); pending?.reject(error); });
    });
    await new Promise((resolve) => control.listen(join(ipc, 'control.sock'), resolve));
    const child = spawn('bwrap', args, { detached: true, env: {}, stdio: ['ignore', 'pipe', 'pipe'] });
    let diagnostic = '';
    for (const name of ['stdout', 'stderr']) child[name].on('data', (bytes) => {
        diagnostic = (diagnostic + bytes.toString()).slice(-8000);
        onOutput?.(name, bytes.toString());
    });
    const exited = new Promise((resolve) => child.once('close', resolve));
    child.on('error', (error) => { readyReject(error); pending?.reject(error); });
    child.on('exit', () => {
        const error = new Error('Runner browser terminato. ' + diagnostic);
        readyReject(error);
        pending?.reject(error);
    });
    function close() {
        if (closing) return closing;
        closing = (async () => {
            try { process.kill(-child.pid, 'SIGKILL'); } catch { child.kill('SIGKILL'); }
            await exited;
            // The private PID namespace kills every descendant, including Chromium.
            await browser?.close().catch(() => {});
            channel?.close();
            controlSocket?.destroy();
            await new Promise((resolve) => control.close(resolve));
            for (const socket of sockets) socket.destroy();
            await new Promise((resolve) => proxy.close(resolve));
            await rm(directory, { recursive: true, force: true });
        })();
        return closing;
    }
    let startupTimer;
    try {
        const value = await Promise.race([ready, new Promise((_, reject) => {
            startupTimer = setTimeout(() => reject(new Error('Avvio runner browser scaduto')), 30_000);
        })]);
        browser = await chromium.connectOverCDP('ws+unix://' + join(ipc, 'cdp.sock') + ':' + value.path,
            { timeout: 10_000 });
    } catch (error) {
        await close();
        throw error;
    } finally { clearTimeout(startupTimer); }
    return {
        context: browser.contexts()[0],
        page: browser.contexts()[0].pages()[0],
        close,
        async run(code, actorValues, targetUrl) {
            let timer;
            const id = randomUUID();
            const completion = new Promise((resolve, reject) => { pending = { id, resolve, reject }; });
            try {
                controlSocket.write(JSON.stringify({ id, code, actor_values: actorValues, target_url: targetUrl }) + '\n');
                return await Promise.race([completion, new Promise((resolve) => {
                    timer = setTimeout(async () => {
                        // Settle the outcome before exit notification can reject it.
                        pending?.resolve({ status: 'error', error_code: 'timeout',
                            error: 'Deadline BrowserCode esaurita; sessione persa, nessun replay.', state_lost: true });
                        await close();
                        resolve(await completion);
                    }, timeoutMs);
                })]);
            } catch (error) {
                await close();
                return { status: 'error', error_code: 'runner_lost', error: String(error), state_lost: true };
            } finally { clearTimeout(timer); pending = null; }
        },
    };
}
