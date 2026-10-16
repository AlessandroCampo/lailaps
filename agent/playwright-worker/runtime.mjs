import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';
import { createTargetProxy } from './target-proxy.mjs';
import { createCodeSession } from './code-session.mjs';
import { spawnSync } from 'node:child_process';

export const capabilities =
    'browser_code esegue JavaScript async con page, context, target_url e actor; return libero. ' +
    'Browser/cookie persistenti per actor; 60s per script, nessun replay automatico. ' +
    'Solo origin target; rete Node isolata, niente popup o service worker. ' +
    'evaluate/addScriptTag, cookie e DOM modificati dal tester sono setup, non prova XSS.';
const sensitive =
    /password|passwd|secret|token|authorization|session|csrf|api[_-]?key/i;
const validScope = (scope) =>
    scope &&
    ['audit_id', 'category', 'lead_id'].every(
        (key) =>
            typeof scope[key] === 'string' &&
            scope[key].length > 0 &&
            scope[key].length <= 160 &&
            !/[\u0000-\u001f]/.test(scope[key]),
    );
const validActor = (actor) =>
    typeof actor === 'string' &&
    actor.length > 0 &&
    actor.length <= 160 &&
    !/[\u0000-\u001f]/.test(actor);
const keyFor = (scope, actor) =>
    [scope.audit_id, scope.category, scope.lead_id, actor].join('\u001f');
function safeUrl(value) {
    try {
        const url = new URL(value);
        for (const name of [...url.searchParams.keys()])
            if (sensitive.test(name)) url.searchParams.set(name, '[REDACTED]');
        return url.href;
    } catch {
        return value;
    }
}
function redact(state, value) {
    if (typeof value === 'string') {
        for (const secret of state.secrets)
            if (secret) value = value.split(secret).join('[REDACTED]');
        return value;
    }
    if (Array.isArray(value)) return value.map((item) => redact(state, item));
    if (value && typeof value === 'object')
        return Object.fromEntries(
            Object.entries(value).map(([key, item]) => [
                key,
                redact(state, item),
            ]),
        );
    return value;
}

export function createRuntime({ target, maxNetwork = 200, codeTimeoutMs = 60_000 }) {
    const targetOrigin = new URL(target);
    if (
        !['http:', 'https:'].includes(targetOrigin.protocol) ||
        targetOrigin.username ||
        targetOrigin.password
    )
        throw new Error('Invalid browser target origin');
    maxNetwork = Math.max(20, Math.min(Number(maxNetwork), 2000));
    const contexts = new Map(),
        replays = new Map(),
        queues = new Map();
    const sameOrigin = (value) => {
        try {
            const url = new URL(value, targetOrigin);
            return (
                url.origin === targetOrigin.origin &&
                !url.username &&
                !url.password
            );
        } catch {
            return false;
        }
    };
    function collect(state, kind, row) {
        if (row.url) row = { ...row, url: safeUrl(row.url) };
        row = redact(state, {
            ...row,
            sequence: ++state.sequence,
            timestamp: new Date().toISOString(),
        });
        state[kind].push(row);
        if (state[kind].length > (kind === 'network' ? maxNetwork : 200)) {
            state[kind].shift();
            state.dropped[kind]++;
        }
        if (state.active) {
            const rows = state.active[kind];
            rows.push(row);
            if (rows.length > (kind === 'network' ? 80 : 40)) {
                const protectedRows = new Set(rows.filter((item) => item.type === "dialog"));
                let discard = rows.findIndex(
                    (event) =>
                        !protectedRows.has(event) &&
                        (kind !== 'events' || event.type !== 'dialog'),
                );
                if (discard < 0)
                    discard = rows.findIndex(
                        (event) => !protectedRows.has(event),
                    );
                rows.splice(discard < 0 ? 0 : discard, 1);
                state.active.truncated = true;
            }
        }
    }
    function requestMetadata(request) {
        const headers = request.headers(),
            raw = request.postData() || '';
        return {
            method: request.method(),
            url: safeUrl(request.url()),
            resource_type: request.resourceType(),
            content_type: headers['content-type'] || '',
            origin: headers.origin || '',
            referer: headers.referer ? safeUrl(headers.referer) : '',
            fetch_site: headers['sec-fetch-site'] || '',
            body_bytes: Buffer.byteLength(raw),
            form_fields: (headers['content-type'] || '').includes(
                'application/x-www-form-urlencoded',
            )
                ? [...new URLSearchParams(raw).keys()].slice(0, 100)
                : [],
        };
    }
    async function getState(scope, actor) {
        const key = keyFor(scope, actor);
        if (contexts.has(key)) return contexts.get(key);
        const state = {
            context: null,
            page: null,
            generation: 1,
            events: [],
            network: [],
            sequence: 0,
            dropped: { events: 0, network: 0 },
            active: null,
            snapshots: new Map(),
            secrets: new Set(),
        };
        state.proxy = await createTargetProxy(targetOrigin.href, (row) =>
            collect(state, 'network', row),
        );
        let context;
        try {
            state.runner = await createCodeSession({ proxyUrl: state.proxy.url,
                timeoutMs: codeTimeoutMs,
                onOutput: (name, text) => {
                    if (state.active) {
                        const previous = state.active[name] || '';
                        state.active[name] = (previous + text).slice(0, 8000);
                        if (previous.length + text.length > 8000) state.active.truncated = true;
                    }
                },
            });
            context = state.runner.context;
        } catch (error) {
            await state.proxy.close();
            throw error;
        }
        state.context = context;
        await context.route('**/*', async (route) => {
            if (!sameOrigin(route.request().url())) {
                collect(state, 'network', {
                    type: 'blocked',
                    ...requestMetadata(route.request()),
                });
                return route.abort('blockedbyclient');
            }
            return route.continue();
        });
        await context.routeWebSocket('**/*', (socket) => {
            collect(state, 'network', {
                type: 'blocked',
                url: safeUrl(socket.url()),
                resource_type: 'websocket',
            });
            socket.close();
        });
        const page = state.runner.page;
        state.page = page;
        context.on('page', async (popup) => {
            if (popup !== page) await popup.close().catch(() => {});
        });
        page.on('dialog', (dialog) => {
            collect(state, 'events', {
                type: 'dialog',
                dialog_type: dialog.type(),
                text: dialog.message(),
                url: safeUrl(page.url()),
            });
        });
        page.on('pageerror', (error) =>
            collect(state, 'events', {
                type: 'page_error',
                text: String(error).slice(0, 2000),
                url: safeUrl(page.url()),
            }),
        );
        page.on('console', (message) =>
            collect(state, 'events', {
                type: 'console',
                level: message.type(),
                text: message.text().slice(0, 2000),
                url: safeUrl(page.url()),
            }),
        );
        page.on('request', (request) =>
            collect(state, 'network', {
                type: 'request',
                ...requestMetadata(request),
            }),
        );
        page.on('response', (response) => {
            const headers = response.headers();
            collect(state, 'network', {
                type: 'response',
                method: response.request().method(),
                url: response.url(),
                status: response.status(),
                resource_type: response.request().resourceType(),
                content_type: headers['content-type'] || '',
                csp: headers['content-security-policy'] || '',
                location: headers.location
                    ? safeUrl(new URL(headers.location, response.url()).href)
                    : '',
            });
        });
        contexts.set(key, state);
        return state;
    }
    async function executeCode(body) {
        let state;
        const started = Date.now();
        try {
            state = await getState(body.scope, body.actor);
        } catch (error) {
            return { status: 'error', error_code: 'runner_unavailable',
                summary: String(error?.message || error).slice(0, 2000), state_lost: true };
        }
        for (const value of Object.values(body.actor_values || {}))
            if (typeof value === 'string' && value) state.secrets.add(value);
        state.active = { events: [], network: [], stdout: '', stderr: '', truncated: false };
        const outcome = await state.runner.run(body.code, body.actor_values, targetOrigin.href.replace(/\/$/, ''));
        const active = state.active;
        state.active = null;
        const result = redact(state, {
            status: outcome.status, error_code: outcome.error_code || null,
            summary: outcome.error || 'Script Playwright completato.',
            output: outcome.output ?? null, stdout: active.stdout, stderr: active.stderr,
            duration_ms: Date.now() - started, state_lost: Boolean(outcome.state_lost || state.page.isClosed()),
            generation: state.generation++, page_url: safeUrl(state.page.url()),
            events: active.events, network: active.network,
            truncated: active.truncated || Boolean(outcome.truncated),
        });
        if (active.network.some((row) => row.type === 'blocked' && row.resource_type === 'proxy')) {
            result.status = 'error';
            result.error_code = 'policy_blocked';
            result.summary += '\nRichiesta o redirect fuori origin bloccato dal proxy.';
        }
        if (result.state_lost) {
            await state.runner.close();
            await state.proxy.close();
            contexts.delete(keyFor(body.scope, body.actor));
        }
        return result;
    }
    async function code(body) {
        if (
            !validScope(body.scope) ||
            !validActor(body.actor) ||
            typeof body.invocation_id !== 'string' ||
            !body.invocation_id ||
            body.invocation_id.length > 200
        )
            return {
                status: 'error',
                error_code: 'invalid_input',
                summary: 'Scope, actor o invocation id non validi.',
            };
        if (typeof body.code !== 'string' || !body.code.trim() || body.code.length > 65_536)
            return { status: 'error', error_code: 'invalid_input', summary: 'Codice richiesto; massimo 65536 caratteri.' };
        const key = keyFor(body.scope, body.actor),
            replayKey = `${key}\u001f${body.invocation_id}`,
            signature = JSON.stringify([body.code, body.actor_values || {}]);
        if (replays.has(replayKey)) {
            const replay = replays.get(replayKey);
            if (replay.signature !== signature)
                return {
                    status: 'error',
                    error_code: 'invalid_input',
                    summary: 'Invocation id riutilizzato per payload diverso.',
                };
            return replay.result;
        }
        const result = (queues.get(key) || Promise.resolve())
            .catch(() => {})
            .then(() => executeCode(body));
        queues.set(key, result);
        replays.set(replayKey, { signature, result });
        try {
            return await result;
        } finally {
            if (queues.get(key) === result) queues.delete(key);
            while (replays.size > 400)
                replays.delete(replays.keys().next().value);
        }
    }
    async function inspect(body) {
        if (!validScope(body.scope) || !validActor(body.actor))
            return {
                status: 'error',
                error_code: 'invalid_input',
                summary: 'Scope o actor non validi.',
            };
        if (
            ![
                'summary',
                'forms',
                'aria',
                'visible_text',
                'dom',
                'events',
                'network',
            ].includes(body.mode)
        )
            return {
                status: 'error',
                error_code: 'invalid_input',
                summary: 'Mode non supportato.',
            };
        const state = await getState(body.scope, body.actor),
            page = state.page,
            cap = Math.min(
                Math.max(Number(body.max_chars || 6000), 200),
                12000,
            );
        let snapshot,
            id,
            offset = 0;
        if (body.cursor) {
            const match = /^([a-f0-9-]+):(\d+)$/.exec(body.cursor);
            id = match?.[1];
            offset = Number(match?.[2]);
            snapshot = state.snapshots.get(id);
            if (
                !snapshot ||
                snapshot.mode !== body.mode ||
                snapshot.selector !== (body.selector || '') ||
                offset > snapshot.content.length
            )
                return {
                    status: 'error',
                    error_code: 'cursor_expired',
                    summary:
                        'Cursor non valido o snapshot scaduto; ripeti senza cursor.',
                };
        } else {
            let content;
            if (body.mode === 'summary')
                content = JSON.stringify({
                    url: safeUrl(page.url()),
                    title: await page.title(),
                    capabilities,
                    forms: await formsSnapshot(page),
                    recent_events: state.events.slice(-5),
                    recent_network: state.network.slice(-8),
                    dropped: state.dropped,
                });
            else if (body.mode === 'forms')
                content = JSON.stringify(
                    await formsSnapshot(page, body.selector),
                );
            else if (body.mode === 'visible_text')
                content = await page
                    .locator(body.selector || 'body')
                    .innerText({ timeout: 5000 });
            else if (body.mode === 'dom')
                content = body.selector
                    ? await page
                          .locator(body.selector)
                          .evaluate((el) => el.outerHTML, null, {
                              timeout: 5000,
                          })
                    : await page.content();
            else if (body.mode === 'aria')
                content = await page
                    .locator(body.selector || 'body')
                    .ariaSnapshot({ timeout: 5000 });
            else
                content = JSON.stringify({
                    dropped: state.dropped[body.mode],
                    rows: state[body.mode],
                });
            content = redact(state, content);
            id = randomUUID();
            snapshot = {
                content: content.slice(0, unicodeEnd(content, 1_000_000)),
                acquisition_truncated: content.length > 1_000_000,
                mode: body.mode,
                selector: body.selector || '',
                page_url: safeUrl(page.url()),
                generation: state.generation,
            };
            state.snapshots.set(id, snapshot);
            while (state.snapshots.size > 8)
                state.snapshots.delete(state.snapshots.keys().next().value);
        }
        const end = unicodeEnd(snapshot.content, offset + cap),
            next_cursor = end < snapshot.content.length ? `${id}:${end}` : null;
        return {
            status: 'ok',
            summary: `${body.mode} snapshot acquisito.${snapshot.acquisition_truncated ? ' Limite 1 MB raggiunto: restringi selector.' : ''}`,
            mode: body.mode,
            page_url: snapshot.page_url,
            generation: snapshot.generation,
            excerpt: snapshot.content.slice(offset, end),
            truncated: Boolean(next_cursor) || snapshot.acquisition_truncated,
            next_cursor,
        };
    }
    async function dispatch(path, body) {
        if (path === '/v1/code') return code(body);
        if (path === '/v1/inspect') return inspect(body);
        if (path === '/v1/close-context') {
            if (!validScope(body.scope))
                return { status: 'error', error_code: 'invalid_input' };
            const prefix =
                [
                    body.scope.audit_id,
                    body.scope.category,
                    body.scope.lead_id,
                ].join('\u001f') + '\u001f';
            for (const [key, state] of contexts)
                if (
                    key.startsWith(prefix) &&
                    (!body.actor || key === keyFor(body.scope, body.actor))
                ) {
                    await state.runner.close();
                    await queues.get(key)?.catch(() => {});
                    await state.proxy.close();
                    contexts.delete(key);
                    for (const replay of replays.keys())
                        if (replay.startsWith(key + '\u001f'))
                            replays.delete(replay);
                }
            return { status: 'ok' };
        }
        if (path === '/v1/close-all') {
            await Promise.allSettled([...contexts.values()].map((state) => state.runner.close()));
            await Promise.allSettled([...queues.values()]);
            for (const state of contexts.values()) {
                await state.proxy.close();
            }
            contexts.clear();
            replays.clear();
            queues.clear();
            return { status: 'ok' };
        }
        if (path === '/health') {
            const isolation = spawnSync('bwrap', ['--unshare-all', '--ro-bind', '/', '/', '--', '/usr/bin/true'],
                { env: {}, timeout: 2000 });
            if (isolation.status !== 0) throw new Error('Namespace BrowserCode non disponibili');
            const probe = await chromium.launch({
                headless: true,
                chromiumSandbox: true,
            });
            await probe.close();
            return { status: 'ok', sandbox: true };
        }
        return { status: 'error', error_code: 'not_found' };
    }
    return { dispatch };
}

async function formsSnapshot(page, selector) {
    const forms = await page.locator(selector || 'html').evaluate(
        (root) => {
            const cssPath = (element) => {
                if (
                    element.id &&
                    document.querySelectorAll(`#${CSS.escape(element.id)}`)
                        .length === 1
                )
                    return `#${CSS.escape(element.id)}`;
                const parts = [];
                while (element && element.nodeType === 1) {
                    const tag = element.tagName.toLowerCase(),
                        siblings = element.parentElement
                            ? [...element.parentElement.children].filter(
                                  (child) => child.tagName === element.tagName,
                              )
                            : [element];
                    parts.unshift(
                        `${tag}:nth-of-type(${siblings.indexOf(element) + 1})`,
                    );
                    element = element.parentElement;
                }
                return parts.join(' > ');
            };
            const forms = [...root.querySelectorAll('form')];
            if (root.matches('form')) forms.unshift(root);
            return forms.map((form) => ({
                method: form.method.toUpperCase(),
                action: form.action,
                enctype: form.enctype,
                locator: cssPath(form),
                fields: [...form.elements].map((field) => ({
                    tag: field.tagName.toLowerCase(),
                    name: field.name || '',
                    type: field.type || '',
                    label: [...(field.labels || [])]
                        .map((label) => label.textContent.trim())
                        .join(' '),
                    placeholder: field.getAttribute('placeholder') || '',
                    locator: cssPath(field),
                    disabled: field.disabled,
                    required: Boolean(field.required),
                    options:
                        field.tagName === 'SELECT'
                            ? [...field.options].map((option) => ({
                                  label: option.label,
                                  value: option.value,
                              }))
                            : undefined,
                })),
            }));
        },
        null,
        { timeout: 5000 },
    );
    return forms.map((form) => ({
        ...form,
        action: safeUrl(form.action),
        fields: form.fields.map((field) =>
            sensitive.test(field.name)
                ? { ...field, options: undefined }
                : field,
        ),
    }));
}

function unicodeEnd(text, end) {
    end = Math.min(end, text.length);
    const last = text.charCodeAt(end - 1);
    return end < text.length && last >= 0xd800 && last <= 0xdbff
        ? end - 1
        : end;
}
