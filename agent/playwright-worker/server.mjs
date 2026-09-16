import http from 'node:http';
import { createRuntime } from './runtime.mjs';

const token = process.env.BROWSER_GATEWAY_TOKEN || '';
const runtime = createRuntime({
    target: process.env.BROWSER_TARGET_ORIGIN || 'http://target',
    maxNetwork: Number(process.env.BROWSER_MAX_NETWORK_EVENTS || 200),
});
const json = (res, status, value) => {
    const body = JSON.stringify(value);
    res.writeHead(status, {
        'content-type': 'application/json',
        'content-length': Buffer.byteLength(body),
    });
    res.end(body);
};
http.createServer((req, res) => {
    if (!token || req.headers.authorization !== `Bearer ${token}`)
        return json(res, 401, { status: 'error', error_code: 'unauthorized' });
    let raw = '';
    req.on('data', (chunk) => {
        raw += chunk;
        if (raw.length > 1_000_000) req.destroy();
    });
    req.on('end', async () => {
        try {
            json(
                res,
                200,
                await runtime.dispatch(req.url, raw ? JSON.parse(raw) : {}),
            );
        } catch {
            // Never echo protocol values or credentials in an unredacted exception.
            json(res, 500, {
                status: 'error',
                error_code: 'internal',
                summary:
                    'Errore gateway browser; ispeziona lo stato prima di un nuovo esperimento.',
            });
        }
    });
}).listen(Number(process.env.PORT || 3000), '0.0.0.0');
for (const signal of ['SIGTERM', 'SIGINT'])
    process.on(signal, async () => {
        await runtime.dispatch('/v1/close-all', {});
        process.exit(0);
    });
