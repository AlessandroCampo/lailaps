import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync, gunzipSync } from 'node:zlib';
import { execFileSync } from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const selected = {
  yeswiki: 'yeswiki-global-20260917-090105',
  bookstack: 'bookstack-global-glm-limited-20261007-002549',
};
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const json = value => `${JSON.stringify(value, null, 2)}\n`;
const portable = path => path.replaceAll('\\', '/');
const count = value => Array.isArray(value) ? value.length : null;
const sum = object => Object.values(object ?? {}).reduce((total, n) => total + (typeof n === 'number' ? n : 0), 0);

export function ratio(numerator, denominator) {
  return typeof numerator === 'number' && Number.isFinite(numerator)
    && typeof denominator === 'number' && Number.isFinite(denominator) && denominator > 0
    ? numerator / denominator : null;
}

export function secondsBetween(start, end) {
  if (!start || !end) return null;
  const seconds = (Date.parse(end) - Date.parse(start)) / 1000;
  return Number.isFinite(seconds) && seconds > 0 ? seconds : null;
}

// Human logs lack call IDs and may interleave agents. Index blocks; never invent call/result pairs.
export function indexTranscript(text) {
  const lines = text.split('\n');
  const records = [];
  const prefixCounts = {};
  for (let i = 0; i < lines.length; i++) {
    const match = lines[i].match(/^\[([^\]]+)\]\s*(.*)/);
    if (!match) continue;
    if (records.length) records.at(-1).line_end = i;
    const tag = match[1];
    prefixCounts[tag] = (prefixCounts[tag] ?? 0) + 1;
    records.push({ line_start: i + 1, line_end: lines.length, tag,
      ...(tag.endsWith(':tool') ? { tool_name: match[2].match(/^([\w]+)/)?.[1] ?? null } : {}),
      preview: match[2].slice(0, 160) });
  }
  return { schema: 'lailaps.c0.trace-index', version: 1, line_numbering: '1-based, inclusive, decompressed UTF-8 transcript',
    pairing: 'unavailable: concurrent human log without authoritative call IDs',
    prefix_counts: prefixCounts, records };
}

export function extractTerminalOutputs(transcript, trace = indexTranscript(transcript)) {
  const lines = transcript.split('\n');
  return trace.records.filter(x => x.tag.endsWith(':terminal-structured-output')).map(x => {
    const text = [lines[x.line_start - 1].replace(/^\[[^\]]+\]\s*/, ''), ...lines.slice(x.line_start, x.line_end)].join('\n').trim();
    let payload;
    try { payload = JSON.parse(text); } catch { payload = text; }
    return { tag: x.tag, line_start: x.line_start, line_end: x.line_end, payload };
  });
}

export function normalize(outcome, transcript, sourceMetadata = {}) {
  const r = outcome.report;
  const t = r.telemetry ?? {};
  const passes = r.passes ?? [];
  const trace = indexTranscript(transcript);
  const passClocks = passes.map(p => ({ pass_id: p.pass_id, started_at: p.started_at ?? null,
    finished_at: p.finished_at ?? null, reported_span_seconds: secondsBetween(p.started_at, p.finished_at) }));
  const passSpan = passClocks.length && passClocks.every(p => p.reported_span_seconds !== null)
    ? passClocks.reduce((total, p) => total + p.reported_span_seconds, 0) : null;
  const findings = ['confirmed', 'suspected', 'rejected_inconclusive'].flatMap(disposition =>
    (r[disposition] ?? []).map(f => ({ finding_id: f.finding_id ?? f.lead_id, lead_id: f.lead_id,
      reference_disposition: disposition, title: f.title, locations: f.locations ?? [],
      milestones: f.milestones ?? null, origin_area_id: f.origin_area_id ?? null })));
  const leadCount = t.lead_count ?? null;
  const ep = t.economic_points_used ?? null;
  const toolRecords = trace.records.filter(x => x.tag.endsWith(':tool')).length;
  const upstreamPresent = Object.hasOwn(t, 'upstream_errors_by_role');
  const upstream = upstreamPresent ? sum(t.upstream_errors_by_role) : null;
  const retries = trace.records.filter(x => x.tag.endsWith(':validation-retry')).length;
  const exitCodes = [...transcript.matchAll(/Agente terminato con codice (\d+)/g)].map(x => Number(x[1]));
  const timeout = transcript.match(/superato il timeout di (\d+)s/);
  const warnings = [
    'Historical Python checkout, exact prompts, dependency versions and image digests are not archived in these run artifacts.',
    'Source/runtime fixture snapshot is not included. Recorded target commits identify source intent, not the exact runtime state.',
    'Child-local elapsed_seconds and per-lead durations are not global wall clocks; concurrent durations must not be summed as wall time.',
    'Human log tool/result records have no reliable call IDs. Complete historical tool arguments/results remain in source-transcript.log.gz.',
  ];
  if (timeout) warnings.push('Supervisor timeout conflicts with final pass timestamps; process duration is unavailable. Pass-span throughput is diagnostic only.');
  if (toolRecords !== t.number_of_tool_calls) warnings.push('Observed human log tool records differ from reported global tool calls; neither is silently substituted for the other.');
  const b = outcome.benchmark ?? {};
  if (b.cost?.economic_points !== ep || b.cost?.model_requests !== t.model_requests) {
    warnings.push('Historical benchmark cost totals differ from global telemetry; derived global metrics use report.telemetry only.');
  }
  const firstPassReported = t.confirmer_first_pass_validation_rate;
  if (typeof firstPassReported === 'number' && firstPassReported > 1) warnings.push('Reported confirmer_first_pass_validation_rate exceeds 1; normalized value uses reported counts, while the original is preserved.');
  if (b.cost?.duration_seconds === 0) warnings.push('Benchmark duration_seconds=0 is a missing historical measurement, not an instantaneous execution.');
  if (typeof ep === 'number' && typeof t.economic_point_limit === 'number' && typeof t.economic_points_remaining === 'number'
      && Math.abs(t.economic_point_limit - ep - t.economic_points_remaining) > 0.01) {
    warnings.push('Reported budget limit, used and remaining do not reconcile; historical grants/caps are preserved without repairing them.');
  }
  if (t.pricing?.pricing_schema_version !== t.pricing_schema_version) warnings.push('Embedded pricing version differs from telemetry pricing_schema_version; compare EP within each reference or reprice both runs with an explicitly shared table.');
  if (passes.some(p => p.report?.global_pipeline?.budget?.category_recon_used_points !== undefined
      && p.report.global_pipeline.budget.category_recon_used_points !== t.category_recon_used_points)) {
    warnings.push('Global root recon budget usage differs from the recorded pipeline budget usage; both are preserved.');
  }
  return {
    schema: 'lailaps.c0.reference', version: 1, target: b.target_id, run_id: outcome.run_id,
    ground_truth_policy: 'python_llm_output_parity',
    source: { ...sourceMetadata, report_schema_version: r.schema_version, mode: r.mode,
      database_record: 'not_found_in_read_only_lookup', historical_runtime_revision: null },
    lifecycle: { outcome: r.outcome, termination_reason: r.termination_reason,
      command_failed: r.command_failed ?? null, process_exit_codes: exitCodes,
      supervisor_timeout_limit_seconds: timeout ? Number(timeout[1]) : null,
      benchmark_status: b.status, artifact_state: b.artifact_state, run_state: b.run_state,
      adjudication_state: b.adjudication_state, fatal_errors: r.fatal_errors ?? [], blockers: r.blockers ?? [] },
    timing: { run_started_at: outcome.started_at, agent_console_started_at_without_timezone: transcript.match(/^Avviata: (.+)$/m)?.[1]?.trim() ?? null,
      passes: passClocks, reported_pass_span_seconds: passSpan,
      run_start_to_last_pass_end_seconds: secondsBetween(outcome.started_at, passes.at(-1)?.finished_at),
      actual_process_wall_seconds: null, cbm_index_seconds: typeof t.cbm_index_duration_ms === 'number' ? t.cbm_index_duration_ms / 1000 : null,
      reported_first_reader_lead_seconds: t.first_reader_lead_seconds ?? null,
      reported_first_reader_lead_clock: 'historical telemetry; exact clock origin not independently verified',
      agent_scope_clocks: passes.flatMap(p => Object.entries(p.report?.global_pipeline?.agents ?? {}).map(([scope, a]) => ({ pass_id: p.pass_id, scope, ...a }))),
      lead_usage: t.lead_usage ?? {},
      duration_comparison_eligibility: timeout ? 'diagnostic_only_conflicting_clocks' : 'reported_pass_span_proxy_process_wall_unavailable' },
    totals: { leads: leadCount, confirmed: count(r.confirmed), suspected: count(r.suspected),
      rejected_inconclusive: count(r.rejected_inconclusive), model_requests: t.model_requests ?? null,
      tool_calls_reported: t.number_of_tool_calls ?? null, tool_call_records_observed: toolRecords,
      input_tokens: t.total_model_input_tokens ?? null, output_tokens: t.total_model_output_tokens ?? null,
      cached_input_tokens: t.cached_model_input_tokens ?? null, economic_points: ep,
      provider_cost_usd: t.provider_cost_usd ?? null, estimated_cost_usd: t.estimated_cost_usd ?? null },
    derived: { leads_per_reported_pass_minute: ratio(leadCount, passSpan === null ? null : passSpan / 60),
      leads_per_ep: ratio(leadCount, ep), leads_per_million_ep: ratio(leadCount === null ? null : leadCount * 1e6, ep),
      ep_per_lead: ratio(ep, leadCount), useful_findings_per_million_ep: ratio((count(r.confirmed) ?? 0) + (count(r.suspected) ?? 0), ep === null ? null : ep / 1e6),
      tokens_per_lead: ratio(typeof t.total_model_input_tokens === 'number' && typeof t.total_model_output_tokens === 'number'
        ? t.total_model_input_tokens + t.total_model_output_tokens : null, leadCount),
      cached_input_fraction: ratio(t.cached_model_input_tokens, t.total_model_input_tokens),
      confirmer_validation_success_fraction: ratio(t.confirmer_first_pass_validations, t.confirmer_terminal_validation_attempts) },
    errors: { global_tool_error_fraction: null, global_tool_error_fraction_reason: 'No exhaustive global tool error counter with the same denominator.',
      exploration_error_fraction: { numerator: t.exploration_error_results ?? null, denominator: t.exploration_tool_results ?? null,
        value: ratio(t.exploration_error_results, t.exploration_tool_results), scope: 'historical exploration classification only; not all tools' },
      output_retry_request_fraction: { numerator: t.output_retry_requests ?? null, denominator: t.model_requests ?? null,
        value: ratio(t.output_retry_requests, t.model_requests) },
      upstream_events_per_model_request: { numerator: upstream, denominator: t.model_requests ?? null, value: ratio(upstream, t.model_requests),
        note: 'Incident density; deadline/provider counters may overlap and are not added together.' },
      validation_retry_log_records: retries, fatal_error_records: count(r.fatal_errors) },
    models: r.models ?? [], role_usage: t.role_usage ?? {},
    reported_telemetry: t,
    pass_configuration: passes.map(p => ({ pass_id: p.pass_id, depth_requested: r.depth_requested,
      reader_checkpoint_strategy: p.report?.reader_checkpoint_strategy ?? null,
      global_pipeline: p.report?.global_pipeline ?? null })),
    benchmark_summary: Object.fromEntries(Object.entries(b).filter(([k]) => !['by_category', 'cost', 'cases', 'unmatched_findings', 'ambiguous_findings'].includes(k))),
    recorded_target_comparisons: Object.entries(b.by_category ?? {}).map(([category, result]) => ({ category, ...result.comparison })),
    reference_findings: findings, limitations: warnings,
  };
}

function inventory(target) {
  const directory = join(root, 'storage/app/runs', target, 'global');
  return readdirSync(directory).flatMap(id => {
    const path = join(directory, id, `${id}-outcome.json`);
    if (!existsSync(path)) return [{ run_id: id, eligible: false, reason: 'outcome_missing' }];
    const o = JSON.parse(readFileSync(path, 'utf8'));
    return [{ run_id: id, started_at: o.started_at ?? null, mode: o.report?.mode ?? null,
      eligible: o.report?.mode === 'global' && typeof o.report?.telemetry === 'object',
      outcome_sha256: hash(readFileSync(path)) }];
  }).sort((a, b) => (Date.parse(b.started_at) || 0) - (Date.parse(a.started_at) || 0));
}

function freeze(destination, databasePath) {
  if (!destination || !databasePath) throw new Error('Usage: freeze <new-output-directory> <read-only-db-snapshot.json>');
  const output = resolve(destination);
  if (existsSync(output)) throw new Error('Destination already exists: frozen references are never overwritten.');
  const db = JSON.parse(readFileSync(databasePath, 'utf8'));
  if (!db.available || Object.keys(db.selected ?? {}).length) throw new Error('This freeze expects a successful DB lookup with no selected records; inspect the DB snapshot.');
  const files = {};
  const sources = [];
  const metrics = [];
  const selections = {};
  const add = (name, bytes) => { files[name] = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes); };
  for (const [target, id] of Object.entries(selected)) {
    const candidates = inventory(target);
    if (candidates.find(c => c.eligible)?.run_id !== id) throw new Error(`Latest eligible ${target} run changed; review selection before freezing.`);
    selections[target] = { selected_run_id: id, rule: 'latest started_at in target/global with report.mode=global and agent telemetry; include failed runs, exclude preflight/stage replays', candidates };
    const base = join(root, 'storage/app/runs', target, 'global', id, id);
    const outcomeBytes = readFileSync(`${base}-outcome.json`);
    const transcriptBytes = readFileSync(`${base}-logs.php`);
    const outcome = JSON.parse(outcomeBytes.toString('utf8'));
    const transcript = transcriptBytes.toString('utf8');
    for (const [suffix, bytes, archive] of [['-outcome.json', outcomeBytes, 'source-outcome.json.gz'], ['-logs.php', transcriptBytes, 'source-transcript.log.gz']]) {
      sources.push({ target, run_id: id, source_path: portable(relative(root, `${base}${suffix}`)),
        archive_path: `${target}/${archive}`, bytes: bytes.length, sha256: hash(bytes),
        observed_mtime: statSync(`${base}${suffix}`).mtime.toISOString() });
      add(`${target}/${archive}`, gzipSync(bytes, { level: 9 }));
    }
    const normalized = normalize(outcome, transcript, {
      outcome_sha256: hash(outcomeBytes), transcript_sha256: hash(transcriptBytes),
      observed_outcome_mtime: statSync(`${base}-outcome.json`).mtime.toISOString(),
      observed_transcript_mtime: statSync(`${base}-logs.php`).mtime.toISOString(),
    });
    metrics.push(normalized);
    add(`${target}/metrics.json`, json(normalized));
    const trace = indexTranscript(transcript);
    const terminalRecords = extractTerminalOutputs(transcript, trace);
    add(`${target}/trace-index.json`, json(trace));
    add(`${target}/outputs.json`, json({ schema: 'lailaps.c0.outputs', version: 1, run_id: id,
      confirmed: outcome.report.confirmed, suspected: outcome.report.suspected,
      rejected_inconclusive: outcome.report.rejected_inconclusive,
      passes: outcome.report.passes.map(p => ({ pass_id: p.pass_id,
        recon: p.report.category_recon_summary ?? p.report.coverage?.category_recon ?? null,
        structured_outputs: p.report.structured_outputs ?? null,
        reader_area_checkpoints: p.report.coverage?.reader_area_checkpoints ?? null,
        role_checkpoints: p.report.role_checkpoints ?? null })),
      terminal_log_records: terminalRecords,
      other_outputs: 'Complete narrative, tool results, notebooks, coverage and evidence remain in source archives.' }));
  }
  const readme = documentation(metrics);
  add('README.md', readme);
  const manifest = { schema: 'lailaps.c0.freeze', version: 1, frozen_at: new Date().toISOString(),
    ground_truth_policy: 'Python LLM outputs are the operational reference for TS parity, regardless of external correctness.',
    current_checkout_at_extraction: { head: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(),
      dirty: execFileSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' }).trim().length > 0,
      note: 'Extraction context only; not the historical runtime revision.' },
    database_lookup: { available: db.available, selected_ids: Object.keys(db.selected), candidate_count: db.candidates?.length ?? null,
      queried_audit_ids: Object.values(selected), record_policy: 'Only selected runs are retained; no DB writes or historical recalculation.' },
    selection: selections, sources,
    files: Object.fromEntries(Object.entries(files).map(([name, bytes]) => [name, { bytes: bytes.length, sha256: hash(bytes) }])) };
  // Ensure no source changed during extraction before publishing the snapshot.
  for (const source of sources) if (hash(readFileSync(join(root, source.source_path))) !== source.sha256) throw new Error('Source changed during extraction.');
  mkdirSync(output, { recursive: true });
  for (const [name, bytes] of Object.entries(files)) { mkdirSync(dirname(join(output, name)), { recursive: true }); writeFileSync(join(output, name), bytes); }
  writeFileSync(join(output, 'manifest.json'), json(manifest));
  verify(output);
  console.log(json({ frozen_directory: output, runs: metrics.map(m => ({ target: m.target, run_id: m.run_id, totals: m.totals, derived: m.derived })) }));
}

export function verify(directory) {
  const manifest = JSON.parse(readFileSync(join(directory, 'manifest.json'), 'utf8'));
  for (const [name, expected] of Object.entries(manifest.files)) {
    const bytes = readFileSync(join(directory, name));
    if (bytes.length !== expected.bytes || hash(bytes) !== expected.sha256) throw new Error(`Artifact integrity failure: ${name}`);
  }
  for (const source of manifest.sources) {
    const bytes = gunzipSync(readFileSync(join(directory, source.archive_path)));
    if (bytes.length !== source.bytes || hash(bytes) !== source.sha256) throw new Error(`Source archive integrity failure: ${source.archive_path}`);
    if (source.archive_path.endsWith('source-outcome.json.gz')) {
      const o = JSON.parse(bytes.toString('utf8'));
      const transcript = gunzipSync(readFileSync(join(directory, source.target, 'source-transcript.log.gz'))).toString('utf8');
      const m = JSON.parse(readFileSync(join(directory, source.target, 'metrics.json'), 'utf8'));
      const rebuilt = normalize(o, transcript, m.source);
      for (const field of ['totals', 'derived', 'timing', 'errors', 'reference_findings', 'benchmark_summary', 'reported_telemetry']) {
        if (JSON.stringify(rebuilt[field]) !== JSON.stringify(m[field])) throw new Error(`Projection mismatch: ${source.target}/${field}`);
      }
      const outputs = JSON.parse(readFileSync(join(directory, source.target, 'outputs.json'), 'utf8'));
      for (const field of ['confirmed', 'suspected', 'rejected_inconclusive']) {
        if (JSON.stringify(outputs[field]) !== JSON.stringify(o.report[field])) throw new Error(`Output mismatch: ${source.target}/${field}`);
      }
      if (JSON.stringify(outputs.terminal_log_records) !== JSON.stringify(extractTerminalOutputs(transcript))) throw new Error(`Terminal output mismatch: ${source.target}`);
    }
  }
}

function documentation(metrics) {
  const rows = metrics.map(m => `| ${m.target} | ${m.run_id} | ${m.totals.leads} | ${m.totals.confirmed}/${m.totals.suspected}/${m.totals.rejected_inconclusive} | ${(m.timing.reported_pass_span_seconds / 60).toFixed(2)} | ${m.totals.economic_points.toFixed(3)} | ${m.derived.leads_per_million_ep.toFixed(3)} |`).join('\n');
  return `# Baseline Python C0 — 7 ottobre 2026

## Perimetro congelato

Solo l'ultima run globale end-to-end disponibile di YesWiki e BookStack. Selezione per started_at dell'outcome, includendo run fallite; preflight e replay Recon/Reader/Confirmer esclusi. Inventario e motivazione in manifest.json. DB raggiungibile ma nessuna delle due audit_id presente; fonte autorevole: file storici. Nessun ricalcolo del benchmark, nessun nuovo agent loop, nessuna modifica al DB o cancellazione dello storico.

Gli output LLM Python sono la **ground truth operativa di parità**, come richiesto: i verdict non vengono ricorretti rispetto a CVE/oracle. Le due configurazioni agentiche possono differire. Il benchmark catalogo storico è conservato come misura aggiuntiva, senza sostituire le disposizioni LLM.

| Target | Run | Lead ammesse/riportate | Confermati/sospetti/respinti-inconclusivi | Intervallo pass, min | EP globali | Lead/M EP |
|---|---|---:|---:|---:|---:|---:|
${rows}

## File e portabilità

- manifest.json: selezione, checksum SHA-256, sorgenti, esito lookup DB, contesto di estrazione.
- <target>/metrics.json: totali globali, metriche derivate con denominatori, timing, errori, modelli, budget/configurazione, pricing, provider, telemetria originale, target commit registrati, elenco finding.
- <target>/outputs.json: finding completi, Recon, checkpoint e output strutturati disponibili; fallback terminale estratto dai log. I messaggi :output sono spesso narrativa intermedia, non verdict.
- <target>/trace-index.json: indice per ruolo/tag/tool con righe nel transcript decompresso. Non associa chiamate e risultati concorrenti senza call ID.
- <target>/source-outcome.json.gz e source-transcript.log.gz: byte originali completi, compressi senza modifiche; includono benchmark/casi/coverage/ledger, diagnostica, tool call, risultati, output narrativi. Aprirli come dati; il vecchio suffisso logs.php non implica che vada eseguito.

Il pacchetto è autonomo e fuori da storage/ e dal core Python. Va mantenuto nel repository o nel futuro archivio di baseline: i file sotto storage/ sono ignorati da Git. Non richiede Python, Laravel, DB, provider LLM o file originali per la verifica. Non è un input da passare al modello auditato: contiene le risposte di riferimento.

## Significato delle metriche

La normalizzazione legge solo report.telemetry globale; non somma pass, categorie, ruoli e lead, che sono proiezioni sovrapposte. Tutta la telemetria originale resta consultabile. Lead = lead_count riportato (incluse respinte), non numero di vulnerabilità utili. BookStack conserva anche raw_leads=5, una non ammessa e lead_count=4: sono denominatori diversi.

- Lead/minuto = lead_count / (somma degli intervalli started_at→finished_at dei pass / 60). Campo esplicito leads_per_reported_pass_minute: **proxy diagnostico**, non durata certificata del processo.
- Lead/EP = lead_count / economic_points_used; Lead/M EP moltiplica per 1.000.000. Anche EP/lead e finding utili/M EP sono presenti. Comparare per target; EP dipendono dal pricing/configurazione storica, non sono unità invarianti tra reference diverse.
- Tempi: inizio run, avvio console senza timezone, clock pass/scope, tempo prima lead riportato, durata e prima HTTP per lead, indice CBM. Durate concorrenti non si sommano come wall time. elapsed_seconds degli output sono locali al rispettivo scope.
- Error rate: exploration_error_results/exploration_tool_results per il solo Reader/exploration; output_retry_requests/model_requests per retry; upstream events/model_requests come densità di incidenti. Missing = null; zero solo se registrato. Tool error rate globale = null: manca un contatore esaustivo compatibile. Fatal/errori di processo separati dai verdict LLM.
- Rate normalizzati vengono ricalcolati dai contatori disponibili, senza correggere quelli originali. Esempio BookStack: first_pass_validation_rate riportato 4; conteggi 4/4 danno 1. Tokens/lead viene ricalcolato dai token globali.

## Limiti storici espliciti

**YesWiki**: log exit 124 e timeout configurato 7.200 s; report pass terminato oltre quattro ore dopo l'avvio. Log modificato circa due ore dopo avvio console, outcome modificato successivamente: gli mtime sono osservazioni del filesystem, non timestamp autorevoli di esecuzione. Actual process wall = null. Non usare l'intervallo pass come soglia di performance vincolante. Benchmark cost: 248 request, 286 tool, 628.553,627 EP; telemetria globale: 716 request, 773 tool, 1.695.092,843 EP. Entrambi congelati; metriche globali usano la seconda. Budget limit/used/remaining non riconciliati. Output strutturati nativi non presenti: Recon/coverage e terminal log preservati.

**BookStack**: exit 1, partial_failure; tre fatal per output retry/request limit. Intervallo pass circa 22,75 min; è il miglior proxy disponibile, non un timer indipendente del processo. Benchmark e global EP concordano. Root category_recon_used_points e budget del pipeline non concordano: conservati entrambi. Ledger HTTP root vuoto non significa assenza di test HTTP: osservare lead_usage e transcript.

Per entrambe benchmark.cost.duration_seconds=0 è privo di misura utilizzabile. Mancano revisione storica esatta del runtime, prompt, lockfile storici, digest immagini e snapshot completo del laboratorio. Si conservano commit target registrati nel benchmark; il checkout corrente non viene presentato come codice che ha prodotto queste run. La baseline conserva la qualità raggiunta negli output; non ricostruisce automaticamente l'ambiente storico.

## Contratto per il confronto TS successivo

P0: associare semanticamente ogni ipotesi Python al risultato TS usando file/operazione/confine di sicurezza e narrativa, senza richiedere stessi ID, testo o ordine. Per ogni riferimento riportare matched/missing/changed, disposizione e milestone, prove e ragione. Una nuova lead non compensa automaticamente la perdita di una lead di riferimento; lead extra vanno rendicontate separatamente. Usare i verdict Python come riferimento, anche se discutibili. Distinguere suspected→confirmed (possibile miglioramento) da confirmed→suspected/missing (regressione da spiegare); anche rifiuti/inconclusivi restano visibili.

Confrontare separatamente Recon/Reader/investigatore quando gli output sono disponibili, poi end-to-end. Mostrare qualità, costo, EP, durata ed errori come dimensioni separate: un numero unico nasconderebbe regressioni. Modelli, pricing, budget o ambiente diversi identificano un confronto operativo; non provano causalità di una singola eliminazione. Per YesWiki il tempo storico è solo diagnostico: nessuna soglia temporale viene inventata. Non è richiesta validazione esterna dei verdict in questa prima fase.

Gate C0: esattamente due run selezionate; archivi byte-identici agli originali via SHA-256; normalizzazione riproducibile offline; finding/disposizioni identici all'outcome; denominatori e dati mancanti espliciti. Gate TS futuro: nessuna perdita non spiegata dei risultati di riferimento; comparator da costruire nel nuovo progetto. Questo snapshot non misura variabilità statistica né certifica la parità TS.

P1: benchmark appaiati/ripetizioni per determinare variabilità e tolleranze di tempo/costo; sorgenti/laboratorio realmente immutabili per le nuove run; ulteriori stack e adjudication esterna solo nella fase di qualità successiva.

## Verifica offline

Dal root del repository, con Node:

\`\`\`powershell
node --test scripts/freeze-python-c0.test.mjs
node scripts/freeze-python-c0.mjs verify benchmarks/baselines/python-c0-20261007
\`\`\`

La verifica controlla manifest, byte originali decompressi e proiezioni ricostruite, senza accedere al DB o lanciare agenti. Manifest.json non è autofirmato: checksum rilevano cambiamenti accidentali; versionare il pacchetto per fissare il riferimento. Il comando freeze rifiuta directory esistenti e run diventate obsolete; una nuova baseline richiede una nuova directory e una revisione esplicita della selezione.
`;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const [command, destination, databasePath] = process.argv.slice(2);
  if (command === 'freeze') freeze(destination, databasePath);
  else if (command === 'verify' && destination) { verify(resolve(destination)); console.log('C0 integrity and offline projection checks passed.'); }
  else throw new Error('Usage: freeze-python-c0.mjs freeze <new-directory> <db-snapshot> | verify <directory>');
}
