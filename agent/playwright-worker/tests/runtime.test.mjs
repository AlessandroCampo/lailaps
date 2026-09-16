import assert from 'node:assert/strict';
import http from 'node:http';
import { after, before, test } from 'node:test';
import { randomUUID } from 'node:crypto';
import { createRuntime } from '../runtime.mjs';

let runtime,
    server,
    outsideServer,
    outsideOrigin,
    outsideRequests = 0,
    origin,
    counter = 0;
const requests = [];
const scope = {
    audit_id: 'browser-tests',
    category: 'xss',
    lead_id: 'integration',
};
const html =
    '<form id="login" action="/post?time=123" method="post"><label for="username">User</label><input id="username" name="username" required><input name="password" type="password" value="private-password"><select name="choice"><option value="a">Alpha</option></select><button>Submit</button></form>';
const longText = Array.from(
    { length: 200 },
    (_, index) => `row-${index}-à🙂`,
).join('\n');
before(async () => {
    outsideServer = http.createServer((req, res) => {
        outsideRequests++;
        res.end('outside target');
    });
    await new Promise((resolve) =>
        outsideServer.listen(0, '127.0.0.1', resolve),
    );
    outsideOrigin = `http://127.0.0.1:${outsideServer.address().port}`;
    server = http.createServer(async (req, res) => {
        let body = '';
        for await (const chunk of req) body += chunk;
        const url = new URL(req.url, origin || 'http://localhost');
        requests.push({
            path: url.pathname,
            query: url.search,
            method: req.method,
            body,
            cookie: req.headers.cookie || '',
            origin: req.headers.origin,
        });
        res.setHeader('content-type', 'text/html; charset=utf-8');
        if (url.pathname === '/redirect-outside') {
            res.writeHead(307, { location: outsideOrigin + '/receive' });
            return res.end();
        }
        if (url.pathname === '/redirect-inside') {
            res.writeHead(307, { location: '/post?time=123' });
            return res.end();
        }
        if (url.pathname === '/delayed')
            return res.end(
                '<script>setTimeout(()=>{document.body.innerHTML=\'<input id="late"><div id="ready">ready</div>\'},180)</script>',
            );
        if (url.pathname === '/async')
            return res.end(
                '<button id="trigger" onclick="setTimeout(()=>{alert(\'async-marker\');fetch(\'/later\')},180)">Run</button>',
            );
        if (url.pathname === '/later') {
            await new Promise((resolve) => setTimeout(resolve, 180));
            return res.end('later');
        }
        if (url.pathname === '/post' || url.pathname === '/post-csp') {
            if (url.pathname === '/post-csp')
                res.setHeader('content-security-policy', "script-src 'none'");
            const form = new URLSearchParams(body);
            const valid =
                req.method === 'POST' &&
                url.searchParams.get('time') === '123' &&
                form.get('time') === 'body-marker' &&
                form.getAll('duplicate').length === 2;
            return res.end(
                `<div id="result">${valid ? 'split-ok' : 'bad'}</div>${valid ? '<script>alert("split-marker")</script>' : ''}`,
            );
        }
        if (url.pathname === '/cookie')
            return res.end(
                `<div id="cookie">${(req.headers.cookie || '').includes('view=compact') ? 'compact' : 'absent'}</div>`,
            );
        if (url.pathname === '/counter')
            return res.end(`<div id="counter">${++counter}</div>`);
        if (url.pathname === '/noise')
            return res.end(
                '<script>Promise.all(Array.from({length:120},(_,i)=>fetch("/resource?i="+i))).then(()=>document.body.innerHTML="<div id=done>done</div>")</script>',
            );
        if (url.pathname === '/resource') return res.end('resource');
        if (url.pathname === '/long') return res.end(`<pre>${longText}</pre>`);
        return res.end(html);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    runtime = createRuntime({ target: origin, maxNetwork: 40 });
});
after(async () => {
    await runtime?.dispatch('/v1/close-all', {});
    await new Promise((resolve) => server.close(resolve));
    await new Promise((resolve) => outsideServer.close(resolve));
});
const flow = (actor, steps, assertions = [], invocation_id = randomUUID()) =>
    runtime.dispatch('/v1/flow', {
        scope,
        actor,
        steps,
        assertions,
        invocation_id,
    });
const inspect = (actor, mode, options = {}) =>
    runtime.dispatch('/v1/inspect', { scope, actor, mode, ...options });
const nav = (path) => ({ action: 'navigate', path });
const dialog = (expected) => ({
    assertion: 'dialog',
    expected,
    timeout_ms: 2000,
});
const text = (selector, expected) => ({
    assertion: 'text',
    locator: 'css',
    selector,
    expected,
    timeout_ms: 2000,
});

test('auto-wait handles delayed inputs and already-detached elements', async () => {
    const result = await flow(
        'waiting',
        [
            nav('/delayed'),
            {
                action: 'fill',
                locator: 'css',
                selector: '#late',
                value: 'works',
                timeout_ms: 2000,
            },
            {
                action: 'wait_for',
                locator: 'css',
                selector: '#absent',
                state: 'detached',
                timeout_ms: 500,
            },
        ],
        [
            text('#ready', 'ready'),
            { assertion: 'hidden', locator: 'css', selector: '#absent' },
        ],
    );
    assert.equal(result.status, 'ok', result.summary);
    const wait = await flow('wait-for', [
        nav('/delayed'),
        {
            action: 'wait_for',
            locator: 'css',
            selector: '#ready',
            state: 'visible',
            timeout_ms: 2000,
        },
    ]);
    assert.equal(wait.status, 'ok', wait.summary);
});
test('async dialogs and responses are awaited in the triggering action', async () => {
    const result = await flow(
        'async',
        [
            nav('/async'),
            { action: 'click', locator: 'css', selector: '#trigger' },
        ],
        [
            dialog('async-marker'),
            {
                assertion: 'response',
                method: 'GET',
                path: '/later',
                status: 200,
                timeout_ms: 2000,
            },
        ],
    );
    assert.equal(result.status, 'ok', result.summary);
    assert.match(result.summary, /dialog: PASS/);
    assert.equal(
        result.events.filter((event) => event.type === 'dialog').length,
        1,
    );
    const negative = await flow(
        'async',
        [nav('/')],
        [{ ...dialog('async-marker'), timeout_ms: 150 }],
    );
    assert.equal(negative.error_code, 'assertion_failed');
    assert.match(negative.summary, /dialog: FAIL/);
    assert.equal(negative.last_completed_step_index, 0);
});
test('intentional repeat executes; duplicate in-flight invocation executes only once', async () => {
    const id = randomUUID(),
        initial = counter;
    const [one, retry] = await Promise.all([
        flow('repeat', [nav('/counter')], [], id),
        flow('repeat', [nav('/counter')], [], id),
    ]);
    assert.equal(counter, initial + 1);
    assert.deepEqual(one, retry);
    const again = await flow('repeat', [nav('/counter')]);
    assert.equal(counter, initial + 2);
    assert.notEqual(one.generation, again.generation);
    const misuse = await flow('repeat', [nav('/')], [], id);
    assert.equal(misuse.error_code, 'invalid_input');
});
test('network ring keeps collecting after more than 200 events', async () => {
    const noisy = await flow('network', [
        nav('/noise'),
        {
            action: 'wait_for',
            locator: 'css',
            selector: '#done',
            timeout_ms: 10000,
        },
    ]);
    assert.equal(noisy.status, 'ok', noisy.summary);
    assert.equal(noisy.truncated, true);
    const later = await flow(
        'network',
        [nav('/later')],
        [{ assertion: 'response', method: 'GET', path: '/later', status: 200 }],
    );
    assert.equal(later.status, 'ok', later.summary);
    assert.ok(
        later.network.some(
            (event) =>
                event.type === 'response' && event.url.endsWith('/later'),
        ),
    );
    let part = await inspect('network', 'network', { max_chars: 200 }),
        full = part.excerpt;
    while (part.next_cursor) {
        part = await inspect('network', 'network', {
            max_chars: 200,
            cursor: part.next_cursor,
        });
        full += part.excerpt;
    }
    const parsed = JSON.parse(full);
    assert.ok(parsed.dropped > 0);
    assert.ok(parsed.rows.length <= 40);
});
test('synthetic form preserves query, duplicate body fields and real script execution', async () => {
    const result = await flow(
        'post',
        [
            {
                action: 'submit_form',
                path: '/post?time=123',
                form: 'time=body-marker&duplicate=a&duplicate=b',
            },
        ],
        [dialog('split-marker'), text('#result', 'split-ok')],
    );
    assert.equal(result.status, 'ok', result.summary);
    const request = requests.findLast((row) => row.path === '/post');
    assert.equal(request.method, 'POST');
    assert.equal(request.query, '?time=123');
    assert.equal(request.origin, origin);
    assert.deepEqual(new URLSearchParams(request.body).getAll('duplicate'), [
        'a',
        'b',
    ]);
    assert.match(result.summary, /non prova provenienza cross-site/);
    const metadata = result.network.find(
        (row) => row.type === 'request' && row.method === 'POST',
    );
    assert.deepEqual(metadata.form_fields, ['time', 'duplicate', 'duplicate']);
});
test('POST response CSP is respected: raw reflection does not yield execution', async () => {
    const result = await flow(
        'csp',
        [
            {
                action: 'submit_form',
                path: '/post-csp?time=123',
                form: 'time=body-marker&duplicate=a&duplicate=b',
            },
        ],
        [
            { ...dialog('split-marker'), timeout_ms: 300 },
            text('#result', 'split-ok'),
        ],
    );
    assert.equal(result.error_code, 'assertion_failed');
    assert.equal(
        result.events.some((row) => row.type === 'dialog'),
        false,
    );
    assert.ok(result.network.some((row) => row.csp === "script-src 'none'"));
});
test('cookie setup and deletion are actor scoped and recorded as setup', async () => {
    const result = await flow(
        'cookies',
        [
            { action: 'set_cookie', name: 'view', value: 'compact', path: '/' },
            nav('/cookie'),
        ],
        [text('#cookie', 'compact')],
    );
    assert.equal(result.status, 'ok', result.summary);
    assert.ok(result.events.some((row) => row.type === 'setup'));
    const isolated = await flow(
        'other-actor',
        [nav('/cookie')],
        [text('#cookie', 'absent')],
    );
    assert.equal(isolated.status, 'ok', isolated.summary);
    const cleared = await flow(
        'cookies',
        [{ action: 'clear_cookie', name: 'view', path: '/' }, nav('/cookie')],
        [text('#cookie', 'absent')],
    );
    assert.equal(cleared.status, 'ok', cleared.summary);
});
test('forms expose usable selectors without password values', async () => {
    await flow('forms', [nav('/')]);
    const result = await inspect('forms', 'forms', { max_chars: 12000 });
    assert.ok(!result.excerpt.includes('private-password'));
    const forms = JSON.parse(result.excerpt);
    assert.equal(forms[0].method, 'POST');
    const field = forms[0].fields.find((row) => row.name === 'username');
    assert.equal(field.label, 'User');
    const fill = await flow('forms', [
        {
            action: 'fill',
            locator: 'css',
            selector: field.locator,
            value: 'tester',
        },
    ]);
    assert.equal(fill.status, 'ok', fill.summary);
});
test('pagination retains an immutable DOM snapshot across subsequent navigations', async () => {
    await flow('pages', [nav('/long')]);
    let part = await inspect('pages', 'visible_text', { max_chars: 200 }),
        full = part.excerpt;
    assert.ok(part.next_cursor);
    await flow('pages', [nav('/')]);
    while (part.next_cursor) {
        part = await inspect('pages', 'visible_text', {
            max_chars: 200,
            cursor: part.next_cursor,
        });
        full += part.excerpt;
    }
    assert.equal(full, longText);
});
test('off-origin synthetic form destinations are rejected before any request', async () => {
    const start = requests.length;
    for (const path of [
        'https://outside.invalid/',
        '//outside.invalid/',
        'javascript:alert(1)',
        '/\\outside.invalid/',
    ]) {
        const result = await flow('policy', [
            { action: 'submit_form', path, form: 'x=y' },
        ]);
        assert.equal(result.error_code, 'policy_blocked', result.summary);
    }
    assert.equal(requests.length, start);
});

test('redacting sensitive query values never makes a different response match', async () => {
    const result = await flow(
        'redacted',
        [nav('/later?token=actual-secret')],
        [
            {
                assertion: 'response',
                method: 'GET',
                path: '/later?token=different-secret',
                status: 200,
                timeout_ms: 150,
            },
            {
                assertion: 'response',
                method: 'GET',
                path: '/later?token=actual-secret',
                status: 200,
                timeout_ms: 150,
            },
        ],
    );
    assert.equal(result.assertion_results[0].passed, false);
    assert.equal(result.assertion_results[1].passed, true);
    assert.ok(!JSON.stringify(result.network).includes('actual-secret'));
});

test('POST redirects cannot deliver a body outside the authorized origin', async () => {
    const result = await flow('redirect', [
        {
            action: 'submit_form',
            path: '/redirect-outside',
            form: 'private=data',
        },
    ]);
    assert.equal(outsideRequests, 0);
    assert.equal(result.status, 'error');
    assert.ok(result.network.some((row) => row.type === 'blocked'));
});

test('same-origin POST redirects preserve the body and execute the final response', async () => {
    const result = await flow(
        'redirect-inside',
        [
            {
                action: 'submit_form',
                path: '/redirect-inside',
                form: 'time=body-marker&duplicate=a&duplicate=b',
            },
        ],
        [dialog('split-marker'), text('#result', 'split-ok')],
    );
    assert.equal(result.status, 'ok', result.summary);
    assert.equal(result.page_url, origin + '/post?time=123');
});
