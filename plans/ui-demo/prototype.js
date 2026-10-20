const content = document.getElementById('content');
const scenario = document.getElementById('scenario');
let state = 'first';
let selectedProject = 'portal';
let projectName = 'Northstar Portal';
let query = '';
let severity = 'all';
let verdict = 'all';
let liveStep = 0;
let activeRun = 'current';
let scanTab = 'findings';
let toastTimer;

const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const badge = (label, variant = '') => `<span class="badge ${variant}">${esc(label)}</span>`;
const button = (label, route, primary = false) => `<a class="button ${primary ? 'primary' : ''}" href="#${route}">${label}</a>`;
const sourceLabel = 'ZIP · Uploaded Oct 18, 2026';
const titles = { confirmed: 'Confirmed', suspected: 'Suspected', blocked: 'Blocked', inconclusive: 'Inconclusive', running: 'Investigating', pending: 'Queued' };
const verdictBadge = status => badge(`${status === 'confirmed' ? '✓ ' : ''}${titles[status] || status}`, ['suspected', 'blocked', 'inconclusive'].includes(status) ? 'warning' : status === 'running' ? 'info' : '');
const severityBadge = value => value ? `<span class="severity ${value.toLowerCase()}">${esc(value)}</span>` : '<span class="meta">Not assessed</span>';
const leads = [
    {
        id: 'access', status: 'confirmed', title: 'Cross-account document access', severity: 'High', confidence: 'high',
        description: 'The document endpoint accepts an identifier without restricting the lookup to the signed-in account.',
        conclusion: 'A request authenticated as Member A returned a document owned by Member B. This confirms a cross-account read in the tested application.',
        recommendation: 'Authorize access to the document against the current account before returning it.',
        category: 'Broken Access Control', cwe_ids: ['CWE-639'],
        locations: [{ file: 'app/Http/Controllers/DocumentController.php', line: 44, snippet: { available: true, start_line: 42, truncated: false, lines: ['public function show(Document $document)', '{', '    return response()->json($document);', '}'] } }],
        evidence: { http_transactions: [{ available: true, method: 'GET', path: '/documents/842', status: 200, actor: 'Member A', request_excerpt: 'GET /documents/842 HTTP/1.1\nHost: portal.test\nCookie: [REDACTED]', request_truncated: false, body_excerpt: '{ "id": 842, "owner": "Member B", "title": "Private document" }', body_truncated: true }] }
    },
    {
        id: 'export', status: 'confirmed', title: 'Export available outside the permitted role', severity: 'High', confidence: null,
        description: 'The export handler does not enforce the administrative role required for downloading account exports.',
        conclusion: 'The saved investigation concluded that a standard member could retrieve an administrative export.',
        recommendation: 'Apply the administrative authorization check before producing the export.', category: 'Broken Access Control', cwe_ids: ['CWE-862'],
        locations: [{ file: 'app/Http/Controllers/ExportController.php', line: 33, snippet: { available: true, start_line: 31, truncated: false, lines: ['public function export()', '{', '    return Export::forAccount(auth()->user()->account);', '}'] } }],
        evidence: { http_transactions: [{ available: false, reason: 'HTTP evidence is not available.' }] }
    },
    {
        id: 'redirect', status: 'suspected', title: 'Unvalidated redirect destination', severity: 'Medium', confidence: 'medium',
        description: 'A user-supplied return destination reaches the redirect handler without a visible destination check.',
        conclusion: 'Source review supports the hypothesis. No conclusive dynamic verification was recorded.',
        recommendation: 'Restrict return destinations to permitted local paths.', category: 'Security Misconfiguration', cwe_ids: ['CWE-601'],
        locations: [{ file: 'app/Http/Controllers/SessionController.php', line: 29, snippet: { available: true, start_line: 28, truncated: false, lines: ["$destination = $request->input('return_to');", 'return redirect($destination);'] } }],
        evidence: { http_transactions: [] }
    },
    {
        id: 'roles', status: 'blocked', title: 'Role change could not be tested', severity: null, confidence: null,
        description: 'A role-management operation requires verification with an administrative account.',
        conclusion: null, reason: 'An administrative test account was unavailable.', recommendation: null,
        category: 'Broken Access Control', cwe_ids: [],
        locations: [{ file: 'app/Http/Controllers/RoleController.php', line: 58, snippet: { available: false, reason: 'The source file could not be read.' } }],
        evidence: { http_transactions: [] }
    },
    {
        id: 'session', status: 'inconclusive', title: 'Session invalidation could not be verified', severity: 'Low', confidence: null,
        description: 'The logout flow was reviewed to check whether a prior session remained usable.',
        conclusion: 'The available observations do not establish whether the session was invalidated.',
        reason: 'A session refresh failed during verification.', recommendation: 'Repeat verification after restoring the test session.',
        category: 'Authentication Failures', cwe_ids: [], locations: [], evidence: { http_transactions: [] }
    }
];

function projectStatus() {
    if (selectedProject === 'docs' || state === 'preparing') return { label: 'Preparing', variant: 'info', ready: false };
    if (selectedProject === 'customer') return { label: 'Source required', variant: '', ready: false };
    if (state === 'setup-failed') return { label: 'Not ready', variant: 'warning', ready: false };
    return { label: 'Ready', variant: 'good', ready: true };
}

function runData(which = activeRun) {
    const previous = which === 'previous';
    const running = !previous && ['running', 'running-current', 'reconnecting'].includes(state);
    const status = previous ? 'completed' : state === 'queued' ? 'pending' : running ? 'running' : state === 'partial' ? 'partial' : ['failed', 'failed-no-report'].includes(state) ? 'failed' : 'completed';
    const live = running && state !== 'running-current';
    const available = (previous || state !== 'failed-no-report') && status !== 'pending' && (!running || live);
    const data = ['zero', 'failed-no-report'].includes(state) && !previous ? [] : previous ? leads.slice(0, 3) : running ? live ? [leads[0], { ...leads[2], status: liveStep > 0 ? 'confirmed' : 'suspected', conclusion: liveStep > 0 ? 'A redirect to an external destination was verified in the test application.' : leads[2].conclusion }] : [] : leads.map(lead => !previous && liveStep > 0 && lead.id === 'redirect' ? { ...lead, status: 'confirmed', conclusion: 'A redirect to an external destination was verified in the test application.' } : lead);
    if (live && liveStep >= 2) data.push(leads[3]);
    if (live && liveStep >= 3) data.push(leads[4]);
    const usage = state === 'failed-no-report' && !previous || status === 'pending' || state === 'running-current' && !previous ? null : { points_used: live ? 380000 + liveStep * 80000 : 620000, point_limit: 1000000, estimated_cost_usd: running ? null : 0.42, provider_cost_usd: null };
    return { status, live, available, leads: data, usage, report: available && !running && status !== 'pending', partial: status === 'partial' || status === 'failed', date: previous ? 'Oct 16, 2026 · 14:20' : 'Oct 18, 2026 · 10:05', time: status === 'pending' ? null : running ? liveStep ? '9m 18s' : '8m 42s' : '18m 24s', coverage: previous || state === 'zero' ? null : false };
}

function runStatus(run) {
    return badge(run.status === 'pending' ? 'Queued' : run.status === 'running' ? '● Running' : run.status === 'partial' ? 'Partial' : run.status === 'failed' ? 'Failed' : '✓ Completed', run.status === 'pending' || run.status === 'running' ? 'info' : run.status === 'partial' ? 'warning' : run.status === 'failed' ? 'fail' : 'good');
}

function crumb(items) {
    return `<nav class="crumb" aria-label="Breadcrumb"><a href="#projects">Projects</a>${items.map(([name, href]) => `<span aria-hidden="true">/</span>${href ? `<a href="#${href}">${esc(name)}</a>` : `<span>${esc(name)}</span>`}`).join('')}</nav>`;
}

function counters(run, compact = false) {
    if (!run.available) return `<p class="meta ${compact ? 'counter-note' : ''}">${run.status === 'failed' ? 'Findings unavailable' : 'Findings not available yet'}</p>`;
    return `<div class="${compact ? 'inline-counts' : 'metrics four'}">${['confirmed', 'suspected', 'blocked', 'inconclusive'].map(status => `<div class="${compact ? 'inline-count' : 'metric'}"><${compact ? 'span' : 'small'}>${titles[status]}</${compact ? 'span' : 'small'}><strong>${run.leads.filter(lead => lead.status === status).length}</strong></div>`).join('')}</div>`;
}

function budget(run, compact = false) {
    if (!run.usage) return '';
    const percent = Math.round(run.usage.points_used / run.usage.point_limit * 100);
    return `<div class="budget ${compact ? 'compact-budget' : ''}"><div class="summaryline"><span class="meta">Budget used</span><span class="mono">${percent}%</span></div><progress value="${percent}" max="100" aria-label="Budget consumed"></progress></div>`;
}

function routeFor(tab, which = activeRun) {
    return tab === 'report' ? `report/${which}` : `scan/${which}/${tab}`;
}

function navigation() {
    return `<nav class="tabs" aria-label="Scan navigation">${['activity', 'findings', 'report'].map(tab => `<a href="#${routeFor(tab)}" ${scanTab === tab ? 'aria-current="page"' : ''}>${tab[0].toUpperCase() + tab.slice(1)}</a>`).join('')}</nav>`;
}

function notice(run) {
    if (run.status === 'pending') return '<div class="infobox neutral"><strong>Queued</strong><p>The scan is waiting to start.</p></div>';
    if (run.status === 'running') return `<div class="infobox neutral"><strong>${run.live ? 'Results are provisional' : 'Scan in progress'}</strong><p>${run.live ? 'Saved findings may change as verification continues.' : 'Findings will appear when results are available.'}</p></div>`;
    if (run.status === 'failed') return `<div class="infobox error"><strong>The scan failed</strong><p>${run.available ? 'Previously saved results remain available. This report is incomplete.' : 'No report was acquired for this scan.'}</p></div>`;
    if (run.status === 'partial') return '<div class="infobox"><strong>Partial results</strong><p>The investigation ended before all planned checks were completed.</p></div>';
    return '';
}

function projects() {
    const cards = [
        { key: 'portal', name: projectName, status: projectStatus(), detail: 'Created Oct 18, 2026' },
        { key: 'docs', name: 'Documentation Hub', status: { label: 'Preparing', variant: 'info' }, detail: 'Created Oct 18, 2026' },
        { key: 'customer', name: 'Customer Workspace', status: { label: 'Source required', variant: '' }, detail: 'Created Oct 17, 2026' }
    ];
    cards[0].status = state === 'preparing' ? { label: 'Preparing', variant: 'info' } : state === 'setup-failed' ? { label: 'Not ready', variant: 'warning' } : { label: 'Ready', variant: 'good' };
    content.innerHTML = `<div class="heading"><h1>Projects</h1>${button('+ New project', 'new', true)}</div>${state === 'empty' ? `<section class="empty first-scan"><div class="empty-mark" aria-hidden="true">◎</div><h2>No projects yet</h2>${button('+ Create project', 'new', true)}</section>` : `<div class="toolbar"><input id="project-search" class="search" placeholder="Search projects…" aria-label="Search projects"></div><div class="divider-title">All projects <span class="count">3</span></div><div class="grid3" id="project-grid">${cards.map(card => `<a class="project" href="#project" data-project="${card.key}"><div class="projecthead"><span class="project-icon">${esc(card.name[0])}</span><h2>${esc(card.name)}</h2></div>${badge(card.status.label, card.status.variant)}<p class="meta" style="margin-top:18px">${card.detail}</p></a>`).join('')}</div><p class="empty" id="project-no-results" hidden>No projects match your search.</p>`}`;
    document.getElementById('project-search')?.addEventListener('input', event => {
        let visible = 0;
        document.querySelectorAll('.project').forEach(card => { card.hidden = !card.querySelector('h2').textContent.toLowerCase().includes(event.target.value.toLowerCase()); if (!card.hidden) visible++; });
        document.getElementById('project-no-results').hidden = visible > 0;
    });
}

function newProject() {
    content.innerHTML = `${crumb([['New project']])}<div class="heading"><h1>New project</h1></div><form class="panel form" id="project-form"><div class="field"><label for="project-name">Project name</label><input type="text" id="project-name" required maxlength="255" placeholder="Customer portal" autocomplete="off"></div><div class="field"><label for="source-file">Source code</label><div class="filebox"><h3>Choose a ZIP archive</h3><input type="file" id="source-file" accept=".zip,application/zip" required></div></div><p id="file-error" class="error-copy" role="alert"></p><div class="formfooter">${button('Cancel', 'projects')}<button class="button primary" type="submit">Create demo project</button></div></form>`;
    document.getElementById('project-form').addEventListener('submit', event => {
        event.preventDefault();
        const file = document.getElementById('source-file').files[0];
        if (!file?.name.toLowerCase().endsWith('.zip')) { document.getElementById('file-error').textContent = 'Choose a ZIP archive.'; return; }
        projectName = document.getElementById('project-name').value.trim();
        if (!projectName) return;
        selectedProject = 'portal';
        setState('preparing');
        location.hash = 'project';
        notify('Prototype only — no file was uploaded.');
    });
}

function emptyProject(status) {
    const preparing = status.label === 'Preparing';
    const failed = status.label === 'Not ready';
    const missing = status.label === 'Source required';
    return `<section class="empty first-scan" aria-live="polite"><div class="empty-mark" aria-hidden="true">◎</div><h2>${preparing ? 'Preparing project' : failed ? 'Project setup failed' : missing ? 'Upload your source code' : 'Run your first scan'}</h2>${preparing ? '<p class="muted">A scan can start when preparation is complete.</p><span class="badge info">Preparing</span>' : failed ? '<p class="muted">The application could not be prepared for scanning.</p><button class="button primary" data-retry>Retry setup</button>' : missing ? '<button class="button primary" data-upload>Upload source</button>' : '<button class="button primary" data-start>Start scan →</button>'}</section>`;
}

function summaryCard() {
    const run = runData('current');
    const running = run.status === 'running';
    const ongoing = running || run.status === 'pending';
    return `<section class="panel scan-summary"><div class="panelhead"><div class="actions"><h2>${ongoing ? 'Current scan' : 'Latest scan'}</h2>${runStatus(run)}</div><span class="meta">${run.time ? `${run.time}${running ? ' elapsed' : ''}` : 'Waiting to start'}</span></div><div class="summary-main"><div><h3>${running ? run.live ? 'Reviewing access controls' : 'Scan in progress' : run.status === 'pending' ? 'Waiting to start' : run.date}</h3><p class="meta" style="margin-top:6px">${running ? run.live ? liveStep ? 'Latest update · 10:14:18' : 'Latest update · 10:13:42' : 'Results not available yet' : !run.available ? 'No report available' : run.partial ? 'Partial results available' : 'Results saved'}</p></div>${button(ongoing || !run.available ? 'View scan →' : 'View results →', 'scan/current/' + (ongoing || !run.available ? 'activity' : 'findings'), true)}</div>${running && run.live ? budget(run, true) : ''}${state === 'reconnecting' ? '<p class="connection" role="status">Reconnecting · showing the last saved update</p>' : ''}${counters(run, true)}${!ongoing && run.report ? `<div class="summary-footer">${button('View report', 'report/current')}<span class="meta">${run.leads.length} findings · this scan</span></div>` : ''}</section>`;
}

function history() {
    const rows = [{ which: 'current', run: runData('current') }, { which: 'previous', run: runData('previous') }];
    return `<section class="sectiongap"><div class="panelhead"><h2>Scan history</h2><span class="meta">2 scans</span></div><div class="tablewrap"><table><thead><tr><th>Started</th><th>Status</th><th>Findings</th><th>Duration</th></tr></thead><tbody>${rows.map(({ which, run }) => `<tr><td><a href="#scan/${which}/${run.status === 'running' || run.status === 'pending' ? 'activity' : 'findings'}">${run.date}</a><span class="meta">ZIP upload</span></td><td>${runStatus(run)}</td><td>${run.available ? run.leads.length : '<span class="meta">Not available yet</span>'}</td><td>${run.time || '—'}</td></tr>`).join('')}</tbody></table></div></section>`;
}

function project() {
    const name = selectedProject === 'portal' ? projectName : selectedProject === 'docs' ? 'Documentation Hub' : 'Customer Workspace';
    const status = projectStatus();
    const noRuns = selectedProject !== 'portal' || ['first', 'preparing', 'setup-failed'].includes(state);
    const active = !noRuns && ['running', 'running-current', 'reconnecting', 'queued'].includes(state);
    content.innerHTML = `${crumb([[name]])}<div class="heading project-heading"><div><div class="actions"><h1>${esc(name)}</h1>${badge(status.label, status.variant)}</div><div class="subline"><span class="meta">${selectedProject === 'customer' ? 'No source uploaded' : sourceLabel}</span></div></div><div class="actions">${selectedProject !== 'customer' ? '<button class="button" data-upload>Upload new version</button>' : ''}${!noRuns ? active ? '' : `<button class="button primary" data-start ${status.ready ? '' : 'disabled'}>Start scan</button>` : ''}</div></div>${noRuns ? emptyProject(status) : summaryCard() + history()}`;
}

function table() {
    const run = runData();
    if (!run.available) return `<div class="empty"><h2>${run.status === 'failed' ? 'Results unavailable' : 'No results available yet'}</h2><p class="muted">${run.status === 'failed' ? 'The scan ended without an acquired report.' : 'The scan is in progress.'}</p></div>`;
    const filtered = run.leads.filter(lead => (!query || `${lead.title} ${lead.description}`.toLowerCase().includes(query.toLowerCase())) && (severity === 'all' || (lead.severity || 'unassessed') === severity) && (verdict === 'all' || lead.status === verdict));
    if (!filtered.length) return `<div class="empty"><h2>${run.leads.length ? 'No findings match your filters' : 'No findings reported'}</h2>${run.leads.length ? '<button class="button" data-reset-filters>Clear filters</button>' : '<p class="muted">Review the report for scope and limitations.</p>'}</div>`;
    return `<div class="tablewrap"><table><thead><tr><th>Finding</th><th>Severity</th><th>Verdict</th><th>Evidence</th></tr></thead><tbody>${filtered.map(lead => `<tr><td><a href="#finding/${activeRun}/${lead.id}">${esc(lead.title)}</a>${lead.locations.length ? `<span class="meta mono">${esc(lead.locations[0].file.split('/').pop())}</span>` : ''}</td><td>${severityBadge(lead.severity)}</td><td>${verdictBadge(lead.status)}</td><td class="meta">${lead.evidence.http_transactions.some(item => item.available) ? 'HTTP excerpt' : lead.evidence.http_transactions.length ? 'Unavailable' : lead.locations.some(item => item.snippet.available) ? 'Source code' : 'Not available'}</td></tr>`).join('')}</tbody></table></div>`;
}

function findingsView() {
    const run = runData();
    return `${counters(run)}${notice(run)}<div class="toolbar"><input id="finding-search" class="search" placeholder="Search findings…" aria-label="Search findings" value="${esc(query)}"><select id="severity-filter" aria-label="Filter by severity">${['all', 'High', 'Medium', 'Low', 'unassessed'].map(value => `<option value="${value}" ${value === severity ? 'selected' : ''}>${value === 'all' ? 'All severities' : value === 'unassessed' ? 'Not assessed' : value}</option>`).join('')}</select><select id="verdict-filter" aria-label="Filter by verdict">${['all', 'confirmed', 'suspected', 'blocked', 'inconclusive'].map(value => `<option value="${value}" ${value === verdict ? 'selected' : ''}>${value === 'all' ? 'All verdicts' : titles[value]}</option>`).join('')}</select></div><div id="finding-results" aria-live="polite">${table()}</div>`;
}

function activityView() {
    const run = runData();
    if (run.status === 'pending') return `${notice(run)}<section class="panel"><h2>Waiting to start</h2><p class="meta" style="margin-top:12px">Queued Oct 18, 2026 · 10:05</p></section>`;
    const live = run.live;
    const events = run.status === 'running' ? live ? [
        { time: liveStep ? '10:14' : '10:13', title: liveStep ? 'Redirect verification saved' : 'Reviewing access controls', body: liveStep ? 'Unvalidated redirect destination · Confirmed' : 'Document access and role boundaries.' },
        { time: '10:10', title: 'Finding saved', body: 'Cross-account document access · Confirmed' },
        { time: '10:05', title: 'Scan started', body: 'Uploaded source · Oct 18, 2026' }
    ] : [{ time: '10:05', title: 'Scan started', body: 'Investigation is in progress.' }] : [
        { time: '10:23', title: run.status === 'failed' ? 'Scan failed · saved results retained' : run.status === 'partial' ? 'Scan finished with partial results' : 'Scan completed', body: `${run.leads.length} findings saved.` },
        { time: '10:05', title: 'Scan started', body: 'Uploaded source · Oct 18, 2026' }
    ];
    return `${notice(run)}<div class="grid2"><div class="stack">${live ? `<section class="panel"><div class="panelhead"><h2>Investigating</h2>${badge('1 active lead', 'info')}</div><p>Role checks on account exports</p><p class="meta" style="margin-top:8px">Verification in progress · excluded from finding counts</p>${budget(run)}</section>` : ''}<section class="panel"><div class="panelhead"><h2>Activity</h2><span class="meta">${live ? 'Updates from saved checkpoints' : 'Execution status'}</span></div><ol class="timeline">${events.map((event, i) => `<li class="event ${run.status === 'running' && i === 0 ? 'active' : 'done'}"><time>${event.time}</time><span class="pin"></span><div><h3>${event.title}</h3><p>${event.body}</p></div></li>`).join('')}</ol></section></div><aside class="panel"><h2>Scan details</h2><dl class="facts" style="margin-top:24px"><div><dt>Started</dt><dd>${run.date}</dd></div><div><dt>Time elapsed</dt><dd>${run.time}</dd></div><div><dt>Source</dt><dd>ZIP · Oct 18, 2026</dd></div><div><dt>Results</dt><dd>${run.available ? `${run.leads.length} saved findings` : 'Not available yet'}</dd></div></dl>${!live && run.usage ? budget(run) : ''}${live ? `<div class="sectiongap">${button('View findings →', routeFor('findings'))}</div>` : ''}</aside></div>`;
}

function scan() {
    const run = runData();
    content.innerHTML = `${crumb([[projectName, 'project'], ['Scan · ' + (activeRun === 'previous' ? 'Oct 16' : 'Oct 18')]])}<div class="heading"><div><h1>Scan · ${activeRun === 'previous' ? 'Oct 16' : 'Oct 18'}, 2026</h1><div class="subline">${runStatus(run)}<span class="meta">${run.time ? run.time + (run.status === 'running' ? ' elapsed' : '') : 'Waiting to start'}</span></div></div>${run.report ? button('View report', routeFor('report'), true) : ''}</div>${navigation()}${state === 'reconnecting' && activeRun !== 'previous' ? '<p class="connection" role="status">Reconnecting · showing the last saved update</p>' : ''}${scanTab === 'activity' ? activityView() : findingsView()}`;
    document.getElementById('finding-search')?.addEventListener('input', event => { query = event.target.value; document.getElementById('finding-results').innerHTML = table(); });
    document.getElementById('severity-filter')?.addEventListener('change', event => { severity = event.target.value; document.getElementById('finding-results').innerHTML = table(); });
    document.getElementById('verdict-filter')?.addEventListener('change', event => { verdict = event.target.value; document.getElementById('finding-results').innerHTML = table(); });
}

function codePreview(location, index) {
    if (!location.snippet.available) return `<div class="code"><div class="codehead"><span class="mono">${esc(location.file)}:${location.line}</span></div><p class="muted unavailable">${esc(location.snippet.reason)}</p></div>`;
    const snippet = location.snippet;
    return `<div class="code"><div class="codehead"><span class="mono">${esc(location.file)}</span><button class="button small" data-copy="code-${index}">Copy</button></div><pre id="code-${index}" class="numbered-code">${snippet.lines.map((line, i) => `<span class="source-line ${snippet.start_line + i === location.line ? 'relevant-line' : ''}"><span class="line-no" aria-hidden="true">${snippet.start_line + i}</span><span>${esc(line).replace(/\b(public|function|return)\b/g, '<span class="syntax-keyword">$1</span>')}</span></span>`).join('')}</pre>${snippet.truncated ? '<p class="meta excerpt-note">Code excerpt truncated</p>' : ''}</div>`;
}

function finding(id) {
    const lead = runData().leads.find(item => item.id === id);
    if (!lead) { content.innerHTML = `${crumb([[projectName, 'project'], ['Finding unavailable']])}<div class="empty"><h2>Finding unavailable in this scenario</h2>${button('Back to findings', routeFor('findings'))}</div>`; return; }
    content.innerHTML = `${crumb([[projectName, 'project'], ['Scan', routeFor('findings')], ['Finding']])}<div class="heading"><div><h1>${esc(lead.title)}</h1><div class="subline">${severityBadge(lead.severity)}${verdictBadge(lead.status)}</div></div>${button('← All findings', routeFor('findings'))}</div><div class="grid2"><div class="stack"><section class="sectiontext"><h2>Description</h2><p>${esc(lead.description)}</p></section>${lead.conclusion ? `<section class="sectiontext"><h2>Investigation conclusion</h2><p>${esc(lead.conclusion)}</p></section>` : ''}${lead.reason ? `<div class="infobox"><strong>${lead.status === 'blocked' ? 'Verification blocked' : 'Investigation limit'}</strong><p>${esc(lead.reason)}</p></div>` : ''}<section class="sectiontext"><h2>Evidence</h2>${lead.locations.map(codePreview).join('')}${lead.evidence.http_transactions.map((transaction, index) => transaction.available ? `<div class="code"><div class="codehead"><span>${esc(transaction.method)} ${esc(transaction.path)} · ${transaction.status}</span><button class="button small" data-copy="http-${index}">Copy</button></div><pre id="http-${index}">${esc(transaction.request_excerpt)}\n\nResponse excerpt\n${esc(transaction.body_excerpt)}</pre><div class="excerpt-note"><span class="meta">Actor: ${esc(transaction.actor)}</span>${transaction.body_truncated ? '<span class="badge">Response truncated</span>' : ''}</div></div>` : `<div class="unavailable-evidence"><h3>HTTP evidence unavailable</h3><p class="muted">${esc(transaction.reason)}</p></div>`).join('')}${!lead.locations.length && !lead.evidence.http_transactions.length ? '<p class="muted">No evidence excerpt is available for this finding.</p>' : ''}</section>${lead.recommendation ? `<section class="sectiontext"><h2>Remediation</h2><p>${esc(lead.recommendation)}</p></section>` : ''}</div><aside class="panel"><h2>Finding details</h2><dl class="facts" style="margin-top:24px"><div><dt>Verdict</dt><dd>${verdictBadge(lead.status)}</dd></div><div><dt>Severity</dt><dd>${severityBadge(lead.severity)}</dd></div>${lead.confidence ? `<div><dt>Confidence</dt><dd>${esc(lead.confidence)}</dd></div>` : ''}${lead.category ? `<div><dt>Category</dt><dd>${esc(lead.category)}</dd></div>` : ''}${lead.cwe_ids.length ? `<div><dt>Classification</dt><dd>${esc(lead.cwe_ids.join(', '))}</dd></div>` : ''}<div><dt>Source</dt><dd>ZIP · Oct ${activeRun === 'previous' ? '16' : '18'}, 2026</dd></div></dl></aside></div>`;
}

function report() {
    const run = runData();
    if (!run.report) { content.innerHTML = `${crumb([[projectName, 'project'], ['Scan', routeFor('activity')], ['Report']])}<div class="empty"><h2>${run.status === 'failed' ? 'Report unavailable' : 'Report not available yet'}</h2>${button('Back to scan', routeFor('activity'))}</div>`; return; }
    content.innerHTML = `${crumb([[projectName, 'project'], ['Scan', routeFor('findings')], ['Report']])}<div class="report"><div class="report-actions">${button('← Back to scan', routeFor('findings'))}<div class="actions"><button class="button" data-export="md">Export Markdown</button><button class="button" data-export="json">JSON</button><button class="button primary" id="print">Print</button></div></div><article class="paper"><header class="reportheader"><div class="panelhead"><span class="brand"><span class="mark">L</span> Lailaps</span>${badge(run.partial ? 'Partial report' : 'Report', run.partial ? 'warning' : '')}</div><h1>${esc(projectName)}</h1><p class="muted" style="margin-top:10px">Security audit · ${run.date}</p></header><section class="reportsection"><h2>Results</h2>${notice(run)}${run.leads.length ? `<p>${run.leads.length} findings recorded in this scan.</p>` : '<p>No findings were reported. This does not establish that the application is free of vulnerabilities.</p>'}${counters(run)}</section><section class="reportsection"><h2>Findings</h2>${run.leads.length ? run.leads.map(lead => `<a class="reportfinding" href="#finding/${activeRun}/${lead.id}">${verdictBadge(lead.status)} ${severityBadge(lead.severity)}<h3 style="margin-top:10px">${esc(lead.title)} →</h3><p>${esc(lead.conclusion || lead.description)}</p></a>`).join('') : '<p class="muted">No findings reported.</p>'}</section><section class="reportsection"><h2>Scope and limitations</h2><dl class="facts"><div><dt>Source</dt><dd>ZIP uploaded Oct ${activeRun === 'previous' ? '16' : '18'}, 2026</dd></div><div><dt>Coverage</dt><dd>${run.coverage === false ? 'Incomplete' : 'Not recorded'}</dd></div></dl>${run.leads.some(lead => lead.reason) ? `<ul>${run.leads.filter(lead => lead.reason).map(lead => `<li>${esc(lead.reason)}</li>`).join('')}</ul>` : ''}<p class="meta" style="margin-top:20px">Findings reflect the saved investigation. Some evidence excerpts may be unavailable or truncated.</p></section><section class="reportsection"><h2>Execution</h2><dl class="facts execution-grid"><div><dt>Status</dt><dd>${runStatus(run)}</dd></div><div><dt>Duration</dt><dd>${run.time}</dd></div>${run.usage ? `<div><dt>Budget used</dt><dd>${Math.round(run.usage.points_used / run.usage.point_limit * 100)}%</dd></div><div><dt>Estimated provider cost</dt><dd>$${run.usage.estimated_cost_usd.toFixed(2)}</dd></div><div><dt>Provider cost</dt><dd>Not available</dd></div>` : ''}</dl><details class="technical"><summary>Additional results</summary><p>3 rejected hypotheses · 2 deferred proposals. These are excluded from finding counts.</p></details></section><footer class="notice">Fictional fixture · Not an assessment of a real application</footer></article></div>`;
    document.getElementById('print').onclick = () => window.print();
}

function notify(message) {
    clearTimeout(toastTimer);
    document.getElementById('toast').textContent = message;
    toastTimer = setTimeout(() => document.getElementById('toast').textContent = '', 4000);
}

function setState(value) {
    state = value;
    scenario.value = value;
    const live = ['running', 'reconnecting'].includes(value);
    document.getElementById('advance').hidden = !live && value !== 'queued';
    document.getElementById('prototype-note').textContent = live ? 'Fictional data · planned live updates' : value === 'running-current' ? 'Fictional data · current backend data only' : 'Fictional data · no uploads or scans are executed';
}

function render() {
    const [view, first, second] = location.hash.slice(1).split('/');
    if (view === 'new') newProject();
    else if (view === 'project') project();
    else if (view === 'scan') { activeRun = ['current', 'previous'].includes(first) ? first : 'current'; scanTab = ['activity', 'findings'].includes(second) ? second : first === 'activity' ? 'activity' : 'findings'; scan(); }
    else if (view === 'finding') { activeRun = ['current', 'previous'].includes(first) ? first : 'current'; finding(second || first); }
    else if (view === 'report') { activeRun = first === 'previous' ? 'previous' : 'current'; report(); }
    else projects();
    document.title = `Lailaps · ${view === 'project' ? projectName : view === 'scan' ? 'Scan' : view === 'finding' ? 'Finding' : view === 'report' ? 'Report' : view === 'new' ? 'New project' : 'Projects'}`;
}

document.addEventListener('click', async event => {
    const chosen = event.target.closest('[data-project]');
    if (chosen) { selectedProject = chosen.dataset.project; if (location.hash === '#project') render(); }
    if (event.target.closest('[data-start]')) document.getElementById('start-dialog').showModal();
    if (event.target.closest('[data-upload]')) document.getElementById('upload-dialog').showModal();
    if (event.target.closest('[data-retry]')) { setState('preparing'); render(); notify('Prototype scenario changed — no setup was started.'); }
    const close = event.target.closest('[data-close]');
    if (close) document.getElementById(close.dataset.close).close();
    const copy = event.target.closest('[data-copy]');
    if (copy) { try { const target = document.getElementById(copy.dataset.copy); const text = target.classList.contains('numbered-code') ? Array.from(target.querySelectorAll('.source-line>span:last-child')).map(line => line.textContent).join('\n') : target.textContent; await navigator.clipboard.writeText(text); notify('Copied'); } catch { notify('Clipboard unavailable. Select the text to copy.'); } }
    if (event.target.closest('[data-reset-filters]')) { query = ''; severity = verdict = 'all'; render(); }
    const exportButton = event.target.closest('[data-export]');
    if (exportButton) {
        const run = runData();
        const format = exportButton.dataset.export;
        const data = format === 'json' ? JSON.stringify({ prototype: true, project: projectName, status: run.status, usage: run.usage, leads: run.leads }, null, 2) : `# ${projectName}\n\nFictional prototype report\n\nStatus: ${run.status}\n\n` + run.leads.map(lead => `## ${lead.title}\n\n${titles[lead.status]} · ${lead.severity || 'Not assessed'}\n\n${lead.description}\n\n${lead.conclusion || lead.reason || ''}\n\n${lead.recommendation || ''}`).join('\n\n');
        const url = URL.createObjectURL(new Blob([data], { type: format === 'json' ? 'application/json' : 'text/markdown' }));
        const link = document.createElement('a'); link.href = url; link.download = `lailaps-fictional-report.${format}`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        notify('Exported fictional prototype data.');
    }
});

document.getElementById('confirm-start').onclick = () => {
    document.getElementById('start-dialog').close();
    setState('queued'); liveStep = 0; query = ''; severity = verdict = 'all'; selectedProject = 'portal';
    if (location.hash === '#scan/current/activity') render(); else location.hash = 'scan/current/activity';
    notify('Prototype only — no scan was started.');
};

document.getElementById('version-form').addEventListener('submit', event => {
    event.preventDefault();
    const file = document.getElementById('version-file').files[0];
    if (!file?.name.toLowerCase().endsWith('.zip')) { document.getElementById('version-error').textContent = 'Choose a ZIP archive.'; return; }
    document.getElementById('upload-dialog').close();
    notify('Prototype only — no upload. Existing scan results are retained.');
});

scenario.onchange = () => {
    setState(scenario.value); selectedProject = 'portal'; liveStep = 0; query = ''; severity = verdict = 'all';
    if (['empty', 'first', 'preparing', 'setup-failed'].includes(state)) location.hash = state === 'empty' ? 'projects' : 'project';
    else if (location.hash.startsWith('#finding')) location.hash = 'scan/current/findings';
    render();
};

document.getElementById('advance').onclick = () => {
    if (state === 'queued') setState('running');
    else if (state === 'reconnecting') { setState('running'); liveStep = Math.min(3, liveStep + 1); }
    else if (liveStep < 3) liveStep++;
    else setState('completed');
    render();
};

document.getElementById('theme').onclick = () => {
    const dark = document.documentElement.classList.toggle('dark');
    document.getElementById('theme').textContent = dark ? 'Light mode' : 'Dark mode';
};

window.addEventListener('hashchange', () => { render(); content.focus({ preventScroll: true }); window.scrollTo(0, 0); });
setState(state);
render();
