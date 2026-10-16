import assert from 'node:assert/strict';
import http from 'node:http';
import { after, before, test } from 'node:test';
import { randomUUID } from 'node:crypto';
import { createRuntime } from '../runtime.mjs';

let server, outside, target, outsideUrl, runtime, posts = 0, outsideRequests = 0;
const scope = { audit_id: 'code-tests', category: 'xss', lead_id: 'one' };
before(async () => {
    outside = http.createServer((req, res) => { outsideRequests++; res.end('outside'); });
    await new Promise((r) => outside.listen(0, '127.0.0.1', r));
    outsideUrl = `http://127.0.0.1:${outside.address().port}`;
    server = http.createServer(async (req, res) => {
        let body = '';
        for await (const chunk of req) body += chunk;
        res.setHeader('content-type', 'text/html');
        if (req.url === '/outside') { res.writeHead(307, { location: outsideUrl }); return res.end(); }
        if (req.url === '/post') {
            posts++;
            res.setHeader('set-cookie', 'logged=yes; HttpOnly; Path=/');
            return res.end('<div id="done">saved</div><script>alert("execution-marker")</script>');
        }
        if (req.url === '/delayed') return res.end('<script>setTimeout(()=>document.body.innerHTML="<input id=late>",100)</script>');
        if (req.url === '/cookie') return res.end(`<p>${req.headers.cookie || 'none'}</p>`);
        if (req.url === '/confirm') return res.end('<body><script>document.body.innerHTML=confirm("confirmation-marker")?"yes":"no"</script>');
        return res.end('<title>Fixture</title><form action="/post" method="post"><label>Email<input name="email"></label><button>Save</button></form>');
    });
    await new Promise((r) => server.listen(0, '127.0.0.1', r));
    target = `http://127.0.0.1:${server.address().port}`;
    runtime = createRuntime({ target, codeTimeoutMs: 1200 });
});
after(async () => {
    await runtime?.dispatch('/v1/close-all', {});
    await Promise.all([server, outside].map((s) => new Promise((r) => s.close(r))));
});
const code = (actor, code, id = randomUUID()) => runtime.dispatch('/v1/code', {
    scope, actor, code, invocation_id: id,
    actor_values: { username: 'private-user', password: 'private-pass' },
});

test('readiness checks namespaces and Chromium sandbox', async () => {
    assert.deepEqual(await runtime.dispatch('/health', {}), {status:'ok', sandbox:true});
});

test('native code auto-waits, emits real events and retains cookies between calls', async () => {
    const first = await code('author', `
        await page.goto(target_url);
        await page.getByLabel('Email').fill(actor.username);
        await page.getByRole('button', { name: 'Save' }).click();
        await page.locator('#done').waitFor();
        return await page.locator('#done').innerText();
    `);
    assert.equal(first.status, 'ok', first.summary);
    assert.equal(first.output, 'saved');
    assert.ok(first.events.some((e) => e.type === 'dialog' && e.text === 'execution-marker'));
    assert.ok(first.network.some((e) => e.method === 'POST' && e.type === 'response'));
    const next = await code('author', "await page.goto(target_url + '/cookie'); return await page.locator('body').innerText();");
    assert.match(next.output, /logged=yes/);
    const other = await code('victim', "await page.goto(target_url + '/cookie'); return await page.locator('body').innerText();");
    assert.equal(other.output, 'none');
    await runtime.dispatch('/v1/close-context', {scope, actor:'victim'});
    const delayed = await code('author', "await page.goto(target_url + '/delayed'); await page.locator('#late').fill('value'); return await page.locator('#late').inputValue();");
    assert.equal(delayed.output, 'value', delayed.summary);
});

test('syntax and serialization errors are recoverable without losing state', async () => {
    const invalid = await code('author', 'this is invalid javascript @');
    assert.equal(invalid.error_code, 'script_error');
    assert.equal(invalid.state_lost, false);
    const cyclic = await code('author', 'const x={}; x.x=x; return x;');
    assert.equal(cyclic.error_code, 'script_error');
    assert.equal((await code('author', 'return page.url();')).status, 'ok');
});

test('scripts can handle dialogs while the gateway records them independently', async () => {
    const result = await code('author', `
        page.once('dialog', (dialog) => dialog.accept());
        await page.goto(target_url + '/confirm');
        return await page.locator('body').innerText();
    `);
    assert.equal(result.output, 'yes', result.summary);
    assert.ok(result.events.some((e) => e.type === 'dialog' && e.text === 'confirmation-marker'));
});

test('failed scripts retain evidence, redact logs and never replay a POST', async () => {
    const before = posts;
    const script = `await page.goto(target_url); await page.getByRole('button', {name:'Save'}).click();
        await page.locator('#done').waitFor(); console.log(actor.password); throw new Error('stop after mutation');`;
    const id = randomUUID();
    const result = await code('partial', script, id);
    assert.equal(result.status, 'error');
    assert.match(result.summary, /stop after mutation/);
    assert.ok(result.events.some((e) => e.type === 'dialog'));
    assert.match(result.stdout, /REDACTED/);
    assert.ok(!JSON.stringify(result).includes('private-pass'));
    assert.deepEqual(await code('partial', script, id), result);
    assert.equal(posts, before + 1);
    await runtime.dispatch('/v1/close-context', {scope, actor:'partial'});
});

test('Node and browser egress stay confined, including redirects and new contexts', async () => {
    const result = await code('network', `
        const report = { token: Boolean(process.env.BROWSER_GATEWAY_TOKEN) };
        const fs = await import('node:fs');
        report.gatewayVisible = fs.existsSync('/srv/server.mjs');
        try { await fetch(${JSON.stringify(outsideUrl)}, {signal: AbortSignal.timeout(300)}); report.nodeEscaped=true; }
        catch { report.nodeEscaped=false; }
        try { report.redirectStatus = (await page.goto(target_url + '/outside')).status(); }
        catch { report.redirectStatus = null; }
        const extra = await context.browser().newContext({proxy:{server:${JSON.stringify(outsideUrl)}}});
        try { await (await extra.newPage()).goto(${JSON.stringify(outsideUrl)}, {timeout:300}); report.newContextEscaped=true; }
        catch { report.newContextEscaped=false; }
        await extra.close();
        return report;
    `);
    assert.equal(result.error_code, 'policy_blocked', result.summary);
    assert.deepEqual(result.output, {token:false,gatewayVisible:false,nodeEscaped:false,redirectStatus:403,newContextEscaped:false});
    assert.equal(outsideRequests, 0);
    await runtime.dispatch('/v1/close-context', {scope, actor:'network'});
});

test('a hung actor is terminated and does not destroy another actor session', async () => {
    const hung = code('hung', 'while (true) {}');
    const healthy = await code('author', 'return 42');
    assert.equal(healthy.output, 42);
    const result = await hung;
    assert.equal(result.error_code, 'timeout');
    assert.equal(result.state_lost, true);
    assert.equal((await code('hung', 'return 7')).output, 7);
    const preserved = await code('author', "await page.goto(target_url + '/cookie'); return await page.locator('body').innerText();");
    assert.match(preserved.output, /logged=yes/);
});

test('inspection remains paginated and does not execute the script again', async () => {
    await code('inspect', 'await page.goto(target_url);');
    const result = await runtime.dispatch('/v1/inspect', {scope,actor:'inspect',mode:'forms'});
    assert.equal(result.status, 'ok');
    assert.match(result.excerpt, /email/);
    const old = await runtime.dispatch('/v1/flow', {scope,actor:'inspect',steps:[]});
    assert.equal(old.error_code, 'not_found');
});


test('closing a scope cancels its active script without replay', async () => {
    await code('cancel', 'return 1');
    const running = code('cancel', 'while (true) {}');
    await new Promise((resolve) => setTimeout(resolve, 50));
    await runtime.dispatch('/v1/close-context', {scope, actor:'cancel'});
    const result = await running;
    assert.equal(result.state_lost, true);
    assert.equal((await code('cancel', 'return 9')).output, 9);
});
