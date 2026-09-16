import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';
import { createTargetProxy } from './target-proxy.mjs';

export const capabilities =
    'navigate GET; submit_form POST con query in path e form URL-encoded; ' +
    'set_cookie/clear_cookie con name/value/path per actor; locator strict e assertion con attesa. ' +
    'Solo origin target e pagina principale, niente frame interattivi, popup, origin attaccante o JS arbitrario. ' +
    'Sessioni HTTP/browser separate. Form sintetiche same-origin e cookie impostati sono setup, non prova cross-site.';
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
const timeoutFor = (item, fallback) =>
    Math.min(Math.max(Number(item.timeout_ms || fallback), 100), 15000);
const locator = (page, item) => {
    const exact = true;
    switch (item.locator) {
        case 'role':
            return page.getByRole(item.selector, {
                name: item.name || undefined,
                exact,
            });
        case 'label':
            return page.getByLabel(item.selector, { exact });
        case 'text':
            return page.getByText(item.selector, { exact });
        case 'placeholder':
            return page.getByPlaceholder(item.selector, { exact });
        case 'test_id':
            return page.getByTestId(item.selector);
        case 'css':
            return page.locator(item.selector);
        default:
            throw new Error('locator_failure: locator mancante');
    }
};
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

export function createRuntime({ target, maxNetwork = 200 }) {
    const targetOrigin = new URL(target);
    if (
        !['http:', 'https:'].includes(targetOrigin.protocol) ||
        targetOrigin.username ||
        targetOrigin.password
    )
        throw new Error('Invalid browser target origin');
    maxNetwork = Math.max(20, Math.min(Number(maxNetwork), 2000));
    const basePath = targetOrigin.pathname.replace(/\/$/, '');
    const contexts = new Map(),
        replays = new Map(),
        queues = new Map();
    let browser;
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
    const targetUrl = (path) => {
        if (
            typeof path !== 'string' ||
            !path ||
            path.startsWith('//') ||
            path.includes('\\') ||
            /^[a-z][a-z0-9+.-]*:/i.test(path)
        )
            throw new Error('policy_blocked: usare un path relativo al target');
        let effective;
        if (path.startsWith('?') || path.startsWith('#'))
            effective = `${basePath || '/'}${path}`;
        else {
            const requested = path.startsWith('/') ? path : `/${path}`;
            effective =
                basePath &&
                requested !== basePath &&
                !requested.startsWith(`${basePath}/`)
                    ? `${basePath}${requested}`
                    : requested;
        }
        const url = new URL(effective, targetOrigin.origin);
        if (!sameOrigin(url.href))
            throw new Error('policy_blocked: origin non autorizzato');
        return url.href;
    };
    function collect(state, kind, row) {
        // Match the original URL before redaction; masking tokens must not make two
        // different requests interchangeable. Remember matches even if the ring rolls.
        const matched = (state.active?.assertions || []).filter((item) => {
            if (item.assertion === 'dialog')
                return (
                    row.type === 'dialog' &&
                    row.text === item.expected &&
                    sameOrigin(row.url)
                );
            if (item.assertion === 'response' && row.type === 'response') {
                try {
                    return (
                        row.url === targetUrl(item.path) &&
                        row.method === item.method.toUpperCase() &&
                        row.status === item.status
                    );
                } catch {
                    return false;
                }
            }
            return false;
        });
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
            for (const item of matched) state.active.matches.set(item, row);
            const rows = state.active[kind];
            rows.push(row);
            if (rows.length > (kind === 'network' ? 80 : 40)) {
                const protectedRows = new Set(state.active.matches.values());
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
        if (!browser)
            browser = chromium.launch({
                headless: true,
                chromiumSandbox: true,
            });
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
            context = await (
                await browser
            ).newContext({
                serviceWorkers: 'block',
                acceptDownloads: false,
                proxy: { server: state.proxy.url, bypass: '<-loopback>' },
            });
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
        const page = await context.newPage();
        state.page = page;
        context.on('page', async (popup) => {
            if (popup !== page) await popup.close().catch(() => {});
        });
        page.on('dialog', async (dialog) => {
            collect(state, 'events', {
                type: 'dialog',
                dialog_type: dialog.type(),
                text: dialog.message(),
                url: safeUrl(page.url()),
            });
            await (
                state.dialogPolicy === 'accept'
                    ? dialog.accept()
                    : dialog.dismiss()
            ).catch(() => {});
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
    async function performStep(state, step, body) {
        const opts = { timeout: timeoutFor(step, 5000) },
            page = state.page;
        if (step.action === 'navigate')
            return page.goto(targetUrl(step.path), {
                ...opts,
                waitUntil: 'domcontentloaded',
            });
        if (step.action === 'submit_form') {
            const destination = targetUrl(step.path);
            if (typeof step.form !== 'string' || step.form.length > 65536)
                throw new Error(
                    'invalid_input: form deve essere testo URL-encoded bounded',
                );
            // Keep the real response, CSP and navigation semantics; never setContent(APIResponse).
            if (!sameOrigin(page.url()))
                await page.goto(targetUrl('/'), {
                    ...opts,
                    waitUntil: 'domcontentloaded',
                });
            for (const [name, value] of new URLSearchParams(step.form))
                if (sensitive.test(name) && value) state.secrets.add(value);
            await Promise.all([
                page.waitForNavigation({
                    ...opts,
                    waitUntil: 'domcontentloaded',
                }),
                page.evaluate(
                    ({ destination, form }) => {
                        const element = document.createElement('form');
                        element.method = 'POST';
                        element.action = destination;
                        element.enctype = 'application/x-www-form-urlencoded';
                        for (const [name, value] of new URLSearchParams(form)) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = name;
                            input.value = value;
                            element.append(input);
                        }
                        document.documentElement.append(element);
                        HTMLFormElement.prototype.submit.call(element);
                    },
                    { destination, form: step.form },
                ),
            ]);
            collect(state, 'events', {
                type: 'setup',
                text: 'Form POST sintetica same-origin; non prova provenienza cross-site.',
                url: safeUrl(page.url()),
            });
            return;
        }
        if (['set_cookie', 'clear_cookie'].includes(step.action)) {
            const name = step.name,
                path = step.path || '/',
                value = step.value || '';
            if (
                typeof name !== 'string' ||
                !/^[!#$%&'*+.^_`|~0-9A-Za-z-]{1,256}$/.test(name) ||
                !path.startsWith('/') ||
                /[\r\n;]/.test(path) ||
                value.length > 4096 ||
                /[\x00-\x20\x7f;,]/.test(value)
            )
                throw new Error(
                    'invalid_input: cookie name/value/path non validi',
                );
            if (step.action === 'clear_cookie')
                await state.context.clearCookies({
                    name,
                    domain: targetOrigin.hostname,
                    path,
                });
            else {
                if (step.same_site === 'None' && !step.secure)
                    throw new Error(
                        'invalid_input: SameSite=None richiede secure',
                    );
                if (sensitive.test(name) && value) state.secrets.add(value);
                await state.context.addCookies([
                    {
                        name,
                        value,
                        domain: targetOrigin.hostname,
                        path,
                        sameSite: step.same_site || 'Lax',
                        secure: Boolean(step.secure),
                        httpOnly: Boolean(step.http_only),
                    },
                ]);
            }
            collect(state, 'events', {
                type: 'setup',
                text: `${step.action} ${name} path=${path}; setup harness, controllo attaccante da giustificare.`,
                url: targetOrigin.origin,
            });
            return;
        }
        const loc = locator(page, step);
        // Do not pre-count: Playwright owns strictness and auto-waiting, including absent/detached elements.
        switch (step.action) {
            case 'fill': {
                const value = step.value_from_actor
                    ? body.actor_values?.[step.value_from_actor]
                    : step.value;
                if (typeof value !== 'string')
                    throw new Error(
                        'actor_value_missing: credenziale privata assente',
                    );
                if (step.value_from_actor && value) state.secrets.add(value);
                return loc.fill(value, opts);
            }
            case 'clear':
                return loc.clear(opts);
            case 'click':
                return loc.click(opts);
            case 'check':
                return loc.check(opts);
            case 'uncheck':
                return loc.uncheck(opts);
            case 'select_option':
                return loc.selectOption(step.option, opts);
            case 'press':
                return loc.press(step.key, opts);
            case 'focus':
                return loc.focus(opts);
            case 'hover':
                return loc.hover(opts);
            case 'wait_for':
                return loc.waitFor({ state: step.state || 'visible', ...opts });
            default:
                throw new Error('invalid_input: action non supportata');
        }
    }
    async function checkAssertion(state, item) {
        const timeout = timeoutFor(item, 3000),
            deadline = Date.now() + timeout;
        let passed = false,
            observed = '';
        do {
            try {
                if (item.assertion === 'path') {
                    const url = new URL(state.page.url());
                    observed = url.pathname + url.search + url.hash;
                    passed = observed === item.expected;
                } else if (item.assertion === 'dialog') {
                    const event = state.active.matches.get(item);
                    passed = Boolean(event);
                    observed = event?.text || '';
                } else if (item.assertion === 'response') {
                    const event = state.active.matches.get(item);
                    passed = Boolean(event);
                    observed = event
                        ? `${event.method} ${event.url} ${event.status}`
                        : '';
                } else {
                    const loc = locator(state.page, item),
                        count = await loc.count(),
                        opts = { timeout: Math.max(1, deadline - Date.now()) };
                    if (item.assertion === 'count') {
                        observed = String(count);
                        passed = count === item.count;
                    } else if (item.assertion === 'hidden' && count === 0) {
                        passed = true;
                        observed = 'element absent';
                    } else if (count !== 1)
                        observed = `strict locator matched ${count}`;
                    else if (item.assertion === 'visible') {
                        passed = await loc.isVisible();
                        observed = String(passed);
                    } else if (item.assertion === 'hidden') {
                        passed = await loc.isHidden();
                        observed = String(passed);
                    } else if (item.assertion === 'checked') {
                        passed = await loc.isChecked(opts);
                        observed = String(passed);
                    } else if (item.assertion === 'text') {
                        observed = (await loc.textContent(opts)) || '';
                        passed = observed.includes(item.expected);
                    } else if (item.assertion === 'attribute') {
                        observed =
                            (await loc.getAttribute(item.attribute, opts)) ||
                            '';
                        passed = observed === item.expected;
                    }
                }
            } catch (error) {
                observed = String(error?.message || error).slice(0, 300);
            }
            if (passed || Date.now() >= deadline) break;
            await new Promise((resolve) =>
                setTimeout(resolve, Math.min(50, deadline - Date.now())),
            );
        } while (true);
        return {
            assertion: item.assertion,
            passed,
            expected: item.expected || '',
            observed,
            timeout_ms: timeout,
        };
    }
    async function executeFlow(body) {
        const state = await getState(body.scope, body.actor);
        for (const value of Object.values(body.actor_values || {}))
            if (typeof value === 'string' && value) state.secrets.add(value);
        state.active = {
            events: [],
            network: [],
            truncated: false,
            assertions: body.assertions || [],
            matches: new Map(),
        };
        let failedStep = null,
            errorCode = null,
            summary = 'Azioni completate.';
        for (let index = 0; index < body.steps.length; index++) {
            const step = body.steps[index];
            state.dialogPolicy = step.dialog_policy || 'dismiss';
            try {
                await performStep(state, step, body);
            } catch (error) {
                failedStep = index;
                const message = String(error?.message || error);
                errorCode = message.includes('policy_blocked')
                    ? 'policy_blocked'
                    : /strict mode|locator_failure/.test(message)
                      ? 'locator_failure'
                      : /timeout/i.test(message)
                        ? 'timeout'
                        : message.includes('invalid_input')
                          ? 'invalid_input'
                          : 'action_failed';
                summary = `Step ${index + 1} (${step.action}) fallito: ${message.slice(0, 500)}`;
                break;
            }
        }
        const assertionResults =
            failedStep === null
                ? await Promise.all(
                      (body.assertions || []).map((item) =>
                          checkAssertion(state, item),
                      ),
                  )
                : [];
        if (
            state.active.network.some(
                (row) =>
                    row.type === 'blocked' && row.resource_type === 'proxy',
            )
        ) {
            errorCode = 'policy_blocked';
            summary +=
                '\nRichiesta o redirect fuori origin bloccato dal proxy privato.';
        }
        if (!errorCode && assertionResults.some((item) => !item.passed))
            errorCode = 'assertion_failed';
        summary +=
            '\n' +
            assertionResults
                .map(
                    (item, index) =>
                        `Assertion ${index + 1} ${item.assertion}: ${item.passed ? 'PASS' : 'FAIL'}; expected=${JSON.stringify(item.expected)}; observed=${JSON.stringify(item.observed.slice(0, 500))}; attesa massima ${item.timeout_ms}ms.`,
                )
                .join('\n');
        const { events, network, truncated } = state.active;
        const diagnostics = [
            ...events.filter(
                (event) => event.type === 'dialog' || event.type === 'setup',
            ),
            ...network
                .filter(
                    (event) =>
                        event.type === 'response' || event.type === 'blocked',
                )
                .slice(-8),
        ];
        summary +=
            '\n' +
            diagnostics
                .map((row) =>
                    row.text
                        ? `${row.type}: ${row.text.slice(0, 500)}`
                        : `${row.type}: ${row.method || ''} ${row.url} ${row.status || ''}`,
                )
                .join('\n');
        if (failedStep !== null)
            summary +=
                '\nIspeziona summary/forms/aria e correggi locator o setup prima di riprovare.';
        if (truncated)
            summary +=
                '\nRaccolta bounded: eventi omessi; inspect_browser legge la finestra recente.';
        const result = redact(state, {
            status: errorCode ? 'error' : 'ok',
            error_code: errorCode,
            failed_step_index: failedStep,
            last_completed_step_index:
                failedStep === null ? body.steps.length - 1 : failedStep - 1,
            summary: summary.trim(),
            generation: state.generation++,
            page_url: safeUrl(state.page.url()),
            assertion_results: assertionResults,
            events,
            network,
            truncated,
        });
        state.active = null;
        return result;
    }
    async function flow(body) {
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
        if (
            !Array.isArray(body.steps) ||
            body.steps.length < 1 ||
            body.steps.length > 10 ||
            !Array.isArray(body.assertions || []) ||
            (body.assertions || []).length > 10
        )
            return {
                status: 'error',
                error_code: 'invalid_input',
                summary: 'Limite step/assertion non rispettato.',
            };
        const key = keyFor(body.scope, body.actor),
            replayKey = `${key}\u001f${body.invocation_id}`,
            signature = JSON.stringify([body.steps, body.assertions || []]);
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
            .then(() => executeFlow(body));
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
        if (path === '/v1/flow') return flow(body);
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
                    await queues.get(key)?.catch(() => {});
                    await state.context.close();
                    await state.proxy.close();
                    contexts.delete(key);
                    for (const replay of replays.keys())
                        if (replay.startsWith(key + '\u001f'))
                            replays.delete(replay);
                }
            return { status: 'ok' };
        }
        if (path === '/v1/close-all') {
            await Promise.allSettled([...queues.values()]);
            for (const state of contexts.values()) {
                await state.context.close();
                await state.proxy.close();
            }
            contexts.clear();
            replays.clear();
            queues.clear();
            if (browser) await (await browser).close();
            browser = undefined;
            return { status: 'ok' };
        }
        if (path === '/health') {
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
