# Codex Handoff — Staged budget unlock (P0.2) + Prompt reinforcement route HTTP (P0.1)

## Contesto e diagnosi (perché questo rework)

Audit sulle run yeswiki-injection del 24–28/08/2026 (storage/app/runs/yeswiki/injection):
30 lead hanno raggiunto `statically_validated`, solo 4 sono state confermate dinamicamente
(13%). **13 dei 26 fallimenti non hanno eseguito una singola richiesta HTTP dal Worker.**
Nessun lead è fallito con un test completato e `RejectedDecision` corretto: la conferma
dinamica non "fallisce", non viene mai tentata fino in fondo.

Cause principali osservate:

1. **Envelope condivisa**: l'envelope da 350k punti per lead è consumata da
   Confirmer + Worker + Judge senza isolamento. Casi osservati: Confirmer che consuma
   294k/350k (84%) lasciando al Worker ~50k punti (glm53flash lead-1, fallito); Worker che
   esaurisce l'envelope alla richiesta 15/37 mentre fa analisi statica (qwen lead-3,
   log: `[budget] worker 15/37 ... rimangono ruolo 0, run 0 pt`).
2. **CandidateHandoff senza ancota operativa**: nei lead falliti `attackable_routes` è
   vuoto o contiene template senza operandi (`GET /api/forms/{formId}/entries/{output}`);
   nei lead confermati contiene la URL pronta all'uso con payload. Correlazione perfetta
   sullo stesso sink: run 20260826-203230 (route concreta → confermato in 3 HTTP) vs
   20260824-205013 (template → 16 HTTP a caso tra form id / field name, tutte fallite).
3. Spreco di slot: una lead chiusa staticamente dal Confirmer consuma comunque uno dei 3
   slot envelope, mentre lead successive valide restano `unfunded`.

## Filosofia del rework (vincolo di design)

- **Non riservare budget per ruoli che potrebbero non entrare in scena**: una lead chiusa
  dal Confirmer (not_exploitable/unreachable) o una run senza sink non deve pagare/riservare
  budget Worker/Giudice.
- **Worker mai affamato quando serve**: il budget Worker+Judge esiste, integro, dal
  momento esatto in cui il Confirmer produce un `CandidateHandoff` accettato.

Il Reader resta sul pool discovery condiviso (le epoch producono 0..n lead; un budget
per-lead tasserebbe l'esplorazione). Gli sblocchi avvengono sulle due transizioni di
lifecycle già esistenti.

---

## Parte A — Budget: staged unlock per lead

### Design

Sostituire l'envelope unica per lead con **due tranche sequenziali per lead**:

| Stadio | Sblocco | Chi consuma | Limite (default, anchor-equivalent) |
|---|---|---|---|
| Tranche Confirmer | Ammissione della lead ( siti attuali di `_open_lead_envelope` ) | Confirmer, self-checkpoint, terminalizzazione Confirmer, compression Confirmer | `confirmer_stage_point_limit = 150000` |
| Tranche Worker | **Solo** `CandidateHandoff` accettato (milestone `statically_validated`) | Worker, Dynamic Judge, `retry_worker`/estensioni, compression Worker | `worker_stage_point_limit = 100000` |

Regole:

1. **Slot scarso = stage Worker**, non ammissione lead. `max_dynamic_pipelines = 4`
   (sostituisce `max_lead_envelopes`) conta le pipeline che hanno sbloccato lo stage
   Worker. Ogni lead ammessa ha sempre diritto al triage Confirmer (bounded dal pool
   discovery del Reader e dalla propria tranche). Il 5° candidate trova lo stage Worker
   chiuso: lead conservata `suspected`/`statically_validated` con lifecycle `unfunded` e
   funding status dedicato (es. `unfunded_dynamic`), reason
   "Numero massimo di pipeline dinamiche raggiunto".
2. **Cap assoluto computabile**, niente euristiche separate:
   `absolute_limit = discovery_limit + max_dynamic_pipelines × (confirmer_stage + worker_stage)`
   → 350k + 4×250k = **1.35M** con i default (oggi 1.4M). `economic_point_limit` resta la
   safety net per categoria con la semantità attuale. L'overshoot terminale resta
   contabilizzato come oggi.
3. **Weighting per modello invariato**: le tranche sono anchor-equivalent e vengono
   scalate con lo stesso meccanismo esistente (`role_scale` / `_role_anchor_points` con
   `model_economic_weights(model)[0].uncached_input`). Non farlo significherebbe tagliare
   silenziosamente il potere d'acquisto cambiando worker model.
4. **Enrichment statico post-Worker** (`needs_confirmer_evidence` → il Confirmer riprende
   la stessa lead): il lavoro Confirmer carica sempre la tranche Confirmer della lead. Se
   la tranche Confirmer è esaurita, l'enrichment non è ammesso e la lead resta nello stato
   attuale (`judge_stopped`/`keep_suspected`): il cap non esprime verdetto tecnico
   (principio già in ARCHITECTURE.md, invariato).
5. **Semantica di esaurimento invariata**: tranche Worker esaurita → `judge_stopped`;
   tranche Confirmer esaurita senza handoff → `reviewer_stopped`. Nessun nuovo verdetto
   tecnico derivato dal budget. I request limit restano guardrail anti-loop orthogonal
   (non toccarli).
6. Overdraft legacy (`ACTIVE_LEAD_COMPLETION_OVERDRAFT_LIMIT`): resta disabilitato in
   modalità staged, esattamente come oggi in modalità envelope.

### Anchor di codice

- `agent/pentest-agent/src/pentest_agent/budget.py`
  - `BudgetBroker` (riga ~118): sostituire `lead_envelope_limit`/`max_lead_envelopes`/
    `lead_envelopes: dict[str, EconomicUsage]` con struttura per-stadio, es.
    `lead_stages: dict[str, LeadStages]` dove `LeadStages` ha usage confirmer + usage
    worker + flag `worker_unlocked`.
  - `envelope_mode` (~216), `absolute_limit` (~223), `open_lead_envelope` (~233),
    `lead_envelope_remaining` (~246), `category_remaining_points` (~311): riscrittura per
    stadi. `category_remaining_points(budget_scope_id=lead_id)` diventa
    stage-sensitive: il Confirmer vede solo la tranche Confirmer residua, Worker/Judge
    solo la tranche Worker residua ( dopo lo sblocco ).
- `agent/pentest-agent/src/pentest_agent/triple_agent.py`
  - Costruzione broker `from_limits` (~2792–2818): nuovi parametri, weighting per ruolo.
  - `_open_lead_envelope` (~3207): diventa unlock stage Confirmer; telemetria + eventi.
  - Siti di apertura envelope all'ammissione lead: ~5230, ~5276, ~6327 (`_refine`) —
    ora aprono SOLO lo stage Confirmer, senza limite `max_dynamic_pipelines`.
  - `_grant_confirmer_tranche` (~5845): admission contro la tranche Confirmer.
  - `_grant_worker_tranche` (~6528): richiede `worker_unlocked`; il primo
    `retry_worker` e le estensioni attingono dalla tranche Worker.
  - `_confirm` (~6557): primo ingresso (resume=False) è il punto di sblocco dello stage
    Worker. Se lo sblocco è negato (slot esauriti): `mark_unfunded` con reason pipeline
    dinamiche e NON avviare il Worker; la lead resta `statically_validated`.
  - Il gate retry del Judge (`triple_agent.py` ~6681–6685): il check
    `category_remaining_points(budget_scope_id=lead_id) <= 0` continua a funzionare se
    `category_remaining_points` è stage-sensitive (per il percorso Worker misura la
    tranche Worker).
- `agent/pentest-agent/src/pentest_agent/ledger.py`
  - `mark_unfunded` e la promozione candidate (~250–262, milestone
    `statically_validated` a ~261): nessun cambio di verdetto, solo funding status. Il
    campo finding `funding_status` ammette i nuovi valori `confirmer_funded`,
    `worker_funded`, `unfunded_dynamic` ( oltre a `not_selected` storico ).
- `agent/pentest-agent/src/pentest_agent/config.py`
  - Sostituire `lead_envelope_point_limit`/`max_lead_envelopes` (~106–107) con
    `confirmer_stage_point_limit=150000`, `worker_stage_point_limit=100000`,
    `max_dynamic_pipelines=4`. Aggiornare la validazione (~325–333: la somma dei limiti
    ruolo vs cap, e il vincolo discovery>0). Espongono env vars come gli altri
    (`CONFIRMER_STAGE_POINT_LIMIT`, `WORKER_STAGE_POINT_LIMIT`, `MAX_DYNAMIC_PIPELINES`).
  - Aggiornare `agent/pentest-agent/.env.example` (rimuovere `LEAD_ENVELOPE_POINT_LIMIT`/
    `MAX_LEAD_ENVELOPES`, aggiungere i tre nuovi).
- Telemetria (`deps/telemetry` + snapshot in report):
  - `lead_usage`: sostituire `funding_status`/`lead_envelope_limit_points` con i campi
    per-stadio (`confirmer_stage_points_used/remaining`, `worker_stage_points_used/
    remaining`, `funding_status` nei nuovi valori).
  - Eventi: `lead_envelope_opened/denied` → `lead_stage_unlocked`
    `{lead_id, stage: confirmer|worker}` e `lead_stage_denied`
    `{lead_id, stage: worker, reason}`.
  - Budget snapshot di categoria (`economic_points_*`): invariato nel significato; il
    breakdown per lead riflette gli stadi ( sostituire i campi envelope in
    `budget snapshot`/telemetry citati in ARCHITECTURE.md ).
  - Verificare con grep che `BenchmarkEvaluator.php` / `BenchmarkRun.php` /
    `PentestRun.php` non dipendano dai valori rimossi ( oggi leggono
    `funding_status`/`lead_envelope_limit_points` dal finding: mantenere
    `funding_status` come nome campo con i nuovi valori è la via compatibile ).

### Test

- Riscrivere `agent/pentest-agent/tests/test_economic_budget.py`:
  1. ammissione lead senza limite da `max_dynamic_pipelines` (stage Confirmer sempre
     aperto per lead ammesse);
  2. tranche Worker sbloccata solo dall'esplicito unlock; Worker/Judge non vedono residuo
     Confirmer e viceversa;
  3. il 5° unlock Worker è negato → lead `unfunded_dynamic` senza toccare i lifecycle
     tecnici;
  4. `absolute_limit = discovery + N×(confirmer_stage+worker_stage)`;
  5. anchor-weighting applicato a entrambe le tranche (cambio worker model non riduce le
     richieste ottenibili);
  6. enrichment Confirmer post-sblocco carica la tranche Confirmer; tranche Confirmer
     esaurita → enrichment non ammesso, lead non cambia verdetto.
- Aggiornare `tests/test_triple_agent.py` (flusso funding: candidate accettato con slot
  pieni → `_confirm` non avvia il Worker; unlock idempotente per lead; `retry_worker`
  attinge dalla tranche Worker) e i test config/validation toccati.

---

## Parte B — Prompt reinforcement: route HTTP ancorata o barriera dichiarata

### Principio: anchor-o-barriera (sempre soddisfacibile, niente hard gate)

Non sempre una route HTTP è derivabile staticamente (sink second-order che richiedono
creazione di contenuto, parametri solo runtime). Quindi nessun gate deterministico nuovo:
il Confirmer deve produrre **o** la prima richiesta HTTP concreta **o** una dichiarazione
esplicita di barriera. Il Worker, se non trova né l'uno né l'altra, chiede subito
`needs_info` invece di autoprodursi la route discovery (meccanica esistente).

Nota di plumbing già verificata: il payload assegnato al Worker (`_worker_assignment`,
`triple_agent.py` ~3074) include già l'intero `confirmation_plan` via
`_candidate_plan_with_recipe` (~368, copia dict) e quindi anche `attackable_routes`.
**Non serve toccare il contratto né la proiezione**: è lavoro di prompt e description.

### Modifiche esatte

1. `agent/pentest-agent/src/pentest_agent/triple_agent.py` — `CONFIRMER_PROMPT`
   (riga ~1109). Inserire dopo il paragrafo "CandidateHandoff contiene tre campi piatti…":

   ```
   Quando la route per raggiungere il sink e' ricavabile staticamente, il piano deve
   iniziare con la prima richiesta HTTP concreta: path esatto, parametro, payload iniziale
   e oracle differenziale. Compila attackable_routes con route pronte all'uso che
   incorporano i valori noti staticamente (identificatore di form, nome del campo, valore
   che produce un match), mai template con placeholder come {formId}. Se la route non e'
   derivabile staticamente, dichiaralo esplicitamente nel piano come barriera: cosa manca
   e il percorso piu' corto alla prima probe HTTP. Il Worker deve sapere se puo' partire
   da una richiesta gia' ancorata o deve prima costruire il setup.
   ```

2. `triple_agent.py` — `WORKER_PROMPT` (riga ~1003). Estendere il paragrafo
   probe-first con:

   ```
   Se il verification_plan non contiene una prima richiesta HTTP gia' ancorata (route +
   parametro) e non dichiara una barriera esplicita che giustifica il setup preliminare,
   non avviare route discovery statica autonoma: proponi subito needs_info con
   info_request preciso sulla route o sul parametro mancante. La catena
   source-propagation-sink e' gia' validata dal Confirmer: non ricostruirla rileggendo
   il codice prima della prima HTTP.
   ```

3. `agent/pentest-agent/src/pentest_agent/models.py`:
   - `attackable_routes` (~453): aggiungere la description
     `"Route concrete pronte all'uso: path con parametri e valori noti ricavati staticamente (es. /api/forms/90/entries/json?query=bf_amount==7). Mai template con placeholder. Se la route non e' derivabile staticamente, lasciare vuoto e dichiarare la barriera nel verification_plan."`
   - `verification_plan` (~456): estendere la description con
     `"Inizia con la prima richiesta HTTP concreta (path, parametro, payload, oracle) quando ricavabile staticamente; altrimenti dichiara esplicitamente la barriera e il percorso piu' corto alla prima probe."`

### Fuori scope (P1, non implementare ora)

- Soft gate con retry strutturato su `attackable_routes` vuoto senza barriera dichiarata:
  solo se le metriche non si muovono dopo questo intervento.
- Health-check del worker model prima di aprire lo stage Worker.
- Normalizzazione orchestrator dei riferimenti a request_id inesistenti nel verdetto Worker.

---

## Invariabili (non rompere)

- Il budget non produce mai verdetti tecnici: esaurimento → `judge_stopped` /
  `reviewer_stopped` / `unfunded`, mai `blocked` per solo esaurimento economico.
- Reader/Recon/Reviewer/Handoff restano sul pool discovery condiviso con quote soft.
- Request limit (32/24 worker, 12→32→64 confirmer, ecc.): guardrail anti-loop, non budget.
- La validazione terminale Confirmer esistente (nucleo statico + primo test HTTP
  descrivibile) non cambia: il Confirmer non può "sgamare" uno stage Worker con handoff
  prematuri.
- Regola output-agentici di AGENTS.md: nessun nuovo DTO annidato; qui si aggiungono solo
  prompt text, field description e contabilità per-stadio.

## ARCHITECTURE.md (obbligatorio, modifica strutturale al budget)

Aggiornare: sezione "Budget e richieste" (allocatore P0, descrizione envelope/pool, cap
assoluto 1.35M computabile), "Policy operativa corrente (schema 9)" (sblocchi a stadio,
slot = pipeline dinamiche), i paragrafi Confirmer/Worker che citano l'envelope, il
paragrafo telemetria (eventi `lead_stage_*`, campi per-stadio in lead_usage/budget
snapshot) e la frase "Dopo l'apertura di tre envelope…" con la nuova semantità di slot.

## Ordine di implementazione e validazione

1. Prima la Parte A (budget), poi la Parte B (prompt): così le run di confronto misurano
   il prompt già a budget sane e i due effetti restano separati.
2. Test: `uv run pytest` nella directory `agent/pentest-agent` (suite esistente,
   inclusi i test economici riscritti).
3. Validazione empirica su benchmark yeswiki-injection, confrontando con le run del
   24–28/08: metriche già presenti in telemetria/evaluator —
   `conversions.static_to_dynamic`, `% lead Worker con 0 HTTP`
   (`lead_usage.http_requests`), `time_to_first_worker_http_seconds`,
   `model_requests_before_first_worker_http`, disposition `judge_stopped` per esaurimento
   tranche Worker vs Confirmer (nuovi campi per-stadio).
