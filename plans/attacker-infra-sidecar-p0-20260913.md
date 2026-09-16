# P0 — Sidecar "attacker-infra" per la conferma dinamica della classe supply-chain

Data: 2026-09-13
Stato: proposta (da implementare)
Origine: analisi run `yeswiki-global-20260912-190606` (lead A03 a03-lead-2/lead-5/lead-6) e discussione successiva.

## 1. Contesto e obiettivo

I finding di classe supply-chain (OWASP A03) terminano oggi tutti in chiusura statica:
il confirmer li giudica correttamente "non controllabili nel threat model corrente"
perché la sandbox non offre nessuna leva per **essere l'origine che l'app si fida di
consumare**. Gli attacchi supply-chain non inviano input malevolo all'app: controllano
l'upstream (repository di update, CDN font, pacchetti). Per confermarli dinamicamente
occorre infrastruttura attaccante dentro l'ambiente benchmark.

Il pattern esiste già: `yeswiki-callback` ([compose.yml](../agent/pentest-agent/benchmarks/targets/yeswiki/runtime/compose.yml)
servizio `yeswiki-callback`) è un sidecar Python che logga richieste e serve JSON fisso,
usato dalle truth SSRF/ActivityPub di A07. È però passivo: non serve contenuti arbitrari.

Obiettivo P0: rendere confermabile dinamicamente la catena più preziosa emersa dalle run —
**updater con MD5 autoreferenziale (`Package.php`) → estrazione nel webroot →
`MigrationService` che esegue PHP del pacchetto** — trasformandola da
"statically validated" a conferma dinamica con precondizione dichiarata.

## 2. Scope

### Dentro P0

1. Nuovo sidecar `yeswiki-attacker` nell'ambiente benchmark yeswiki: file server
   deterministico con API di registrazione contenuti (file arbitrari + builder di
   pacchetti ZIP con `.md5` coerente).
2. Nuovo tool worker `attacker_infra` per registrare contenuti e leggere gli eventi,
   disponibile solo se l'harness configura l'URL del sidecar.
3. Brief confirmer + worker assignment: la simulazione di origine avvelenata è in scope;
   il verdetto deve dichiarare la precondizione nella narrativa.
4. Test unitari del sidecar e del tool + smoke manuale end-to-end della catena updater.

### Fuori P0 (già catalogati)

- **P1** `network_override` (hosts/DNS nel container target, auto-revert): variante
  default-origin (compromissione dell'origine reale senza toccare la config) e caso
  MITM/TLS dei font (`ThemeManager`, `CURLOPT_SSL_VERIFYPEER=false`).
- **P2** verdetto `confirmed_with_preconditions` a ledger/judge (oggi la precondizione
  vive solo nella narrativa), aggregazione in catene nel report, truth A03 nel manifest
  per rendere misurabile la classe al benchmark.

## 3. Decisioni di design

| Decisione | Scelta | Motivo / alternativa scartata |
|---|---|---|
| Estendere `yeswiki-callback` vs nuovo servizio | **Nuovo servizio `yeswiki-attacker`** | L'oracle A07 dipende dal comportamento esatto del callback (JSON fisso, `/events`): non si tocca. Il nuovo sidecar ha un contratto diverso (API di controllo). |
| Come il worker raggiunge il sidecar | **Tool dedicato `attacker_infra`** | `http_call`/`http_form_call` sono target-bound per contratto (`application_path` relativa al base URL; vedi `PlannedHttpRequest`). Aprirli a host arbitrari allarga la superficie e indebolisce il contratto usato dall'oracle. Il tool dedicato rende la policy banale e il brief auto-documentante. |
| Modifica schema `ConfirmationRecipeCard` | **No**: i passi supply-chain vivono in `action` narrativa + `expected_observation` | La card è già tool-neutral e tollerante (REGOLA OUTPUT AGENTICI: tipizzare solo ciò che serve al controllo di flusso). La formalizzazione di un campo `attacker_infra` è P2, solo se servirà replay deterministico delle recipe. |
| Verdetto | `confirmed` con precondizione obbligatoria in `reason`/narrativa | Il tier formale `confirmed_with_preconditions` è P2; P0 impone la dichiarazione a livello di prompt. |
| Contenuto del sidecar | **API di registrazione a runtime**, non fixture statiche | Il worker deve craftare pacchetti con nomi/file specifici della lead; fixture prebake non scalano. La determinism è preservata: il sidecar non ha stato derivante dalle richieste dell'app. |
| Disponibilità del tool | Solo se `ATTACKER_INFRA_URL` è configurata | Gli ambienti non-benchmark (audit reali) non espongono il tool; stesso pattern di gating di `worker_browser_enabled`. |

## 4. Semantica del sidecar e del tool

### API del sidecar (server HTTP interno alla rete benchmark)

- `POST /_attacker/files` — registra `{path, content_type, body_b64}` e lo serve a
  `GET {path}`. Primitiva generica.
- `POST /_attacker/package` — registra `{path, entries: {nome_file: contenuto},
  auto_md5: true}`: costruisce lo ZIP in memoria, lo serve a `GET {path}` e serve anche
  `GET {path}.md5` con l'MD5 dello ZIP servito. È la primitiva shaped-per-updater:
  il controllo di integrità passa per costruzione, che è esattamente ciò che la
  dimostrazione deve mostrare.
- `GET /events` — log di ogni richiesta servita (metodo, path, headers), stesso stile
  del callback: è l'evidenza che l'app è davvero venuta a consumare l'origine avvelenata.
- `POST /_attacker/reset` — svuota registrazioni e log (chiamato dall'harness tra lead,
  non dal worker).

### Tool worker `attacker_infra`

- `attacker_infra(action="register_file", path=..., content_type=..., body=...)`
- `attacker_infra(action="register_package", path=..., entries={...}, auto_md5=true)`
  → restituisce l'URL interno (es. `http://yeswiki-attacker:8080/repo/x.zip`) da passare
  all'app.
- `attacker_infra(action="events")` → log delle richieste servite.

Il trigger dell'update e la verifica del dropped file usano i tool esistenti
(`http_form_call` da admin su `{{update}}`/UpdateAction; `http_call` GET del file nel
webroot; `run_target_command`/`query_database` per marker della migration).

## 5. Modifiche file per file

| File | Modifica | Stima |
|---|---|---|
| `benchmarks/targets/yeswiki/runtime/attacker_server.py` (nuovo) | Server Python stdlib (nessuna dipendenza): routing delle 4 API + serving. ~90 righe, testabile con pytest via thread. | P0 |
| `benchmarks/targets/yeswiki/runtime/compose.yml` | Nuovo servizio `yeswiki-attacker` (`python:3.12-alpine`, mount dello script, healthcheck su `/events`, nessuna porta host necessaria: è raggiungibile solo dai servizi della rete benchmark). Il callback resta intatto. | P0 |
| `src/pentest_agent/tools/runtime_tools.py` (o modulo dedicato) | Tool `attacker_infra` con client httpx verso `settings.attacker_infra_url`; assente dalla toolset se l'URL non è configurato. | P0 |
| `src/pentest_agent/config.py` + `.env.example` | `ATTACKER_INFRA_URL` (default vuota = tool off). | P0 |
| `src/pentest_agent/deps.py` | Registrazione del tool nella toolset worker (`WORKER_RUNTIME_TOOLS`) condizionata al setting. | P0 |
| `src/pentest_agent/triple_agent.py` | (a) brief confirmer: la simulazione di origine avvelenata è in scope quando il tool è disponibile; classificare come "controllabile" i sink supply-chain la cui origine è sostituibile. (b) worker assignment: descrizione del tool + obbligo di dichiarare la precondizione nel verdetto. | P0 |
| `tests/test_attacker_infra.py` (nuovo) | Unit: sidecar (registrazione file/package, auto_md5, events, reset), tool (chiamate corrette, gating su URL assente). | P0 |
| `ARCHITECTURE.md` | Paragrafo: ambiente benchmark con sidecar attacker-infra, tool worker, semantica della conferma condizionata. (Tool aggiunto a un agente = incluso per regola AGENTS.md.) | P0 |
| `agent/docker-compose.yml` | Eventuale env pass-through di `ATTACKER_INFRA_URL` al container agente. | P0 |

## 6. Scenario di riferimento (catena updater → migrations)

Il caso di verifica end-to-end che il P0 deve sbloccare, con i mezzi esistenti:

1. Worker registra il pacchetto: `attacker_infra(register_package, path="/repo/yeswiki_doryphore.zip",
   entries={"files/pwned.php": "<?php echo 'lailaps-pwn'; ?>",
            "migrations/20260913000000_LailapsMigration.php": "<php class ... scrive marker su file/DB>"})`.
2. Da admin (`http_form_call`, credenziali nell'assignment) punta il repository address
   del wiki verso `http://yeswiki-attacker:8080/repo/` e triggera l'update.
3. L'app scarica ZIP+`.md5` dalla stessa origine → `checkIntegrity()` passa per
   costruzione → `ZipArchive::extractTo` scrive nel webroot → `MigrationService`
   esegue la migration del pacchetto.
4. Segnali di successo: `http_call` GET su `/files/pwned.php` risponde con il marker;
   marker della migration via `run_target_command`/`query_database`;
   `attacker_infra(events)` dimostra che l'app ha consumato l'origine avvelenata.
5. Verdetto: confirmed con `reason` che dichiara la precondizione
   ("repository address controllato dall'attaccante via config admin; il difetto
   dimostrato è l'assenza di verifica indipendente del canale").

## 7. Piano di verifica

1. **Unit**: sidecar (le 4 API, MD5 coerente, reset), tool (payload, errori, gating).
2. **Regressione**: suite `pentest-agent` esistente; nessun cambiamento atteso fuori dai
   nuovi file. Le truth A07 che dipendono dal callback devono restare verdi (compose non
   toccato su quel servizio).
3. **Smoke manuale** (l'unico test e2e, proporzionato): `docker compose up` del runtime
   yeswiki + container agente con `ATTACKER_INFRA_URL` impostata; esecuzione della
   sequenza del §6; verifica dei tre segnali. Documentare l'esito nel run artifact.
4. **Verifica prompt**: una run benchmark A03 (o replay confermer con lo stesso target)
   per confermare che il confirmer smette di classificare i sink updater come
   "non controllabili".

## 8. Rischi e mitigazioni

- **Determinismo benchmark**: il sidecar è stateless rispetto alle richieste dell'app;
  il reset tra lead è a carico dell'harness. Il contenuto servito deriva solo dalle
  registrazioni esplicite del worker (tracciate in telemetry/http_log).
- **Abuso del tool**: il worker potrebbe tentare di usare `attacker_infra` per casi
  fuori supply-chain; il brief lo vincola alla simulazione di origini attendibili e la
  recipe (narrativa) resta arbitrata dal confirmer/judge.
- **Isolamento**: nessuna porta pubblicata sull'host; il sidecar è raggiungibile solo
  dentro la rete compose del benchmark. Il tool non esiste fuori dal benchmark.
- **Gonfiaggio dei verdetti**: la precondizione in narrativa è obbligatoria nel P0;
  il tier formale (P2) arriva prima di esporre questi finding a clienti.
- **`yeswiki-app` senza shell** (osservato in run 190529): irrilevante per il P0 perché
  il dropped file arriva via updater, non via shell; la verifica usa HTTP target-bound.

## 9. Sequenza implementazione

1. `attacker_server.py` + test unit (nessuna dipendenza dal resto).
2. Servizio compose + healthcheck; verificare raggiungibilità da `yeswiki-app` (script
   PHP one-liner via `run_target_command` o check manuale).
3. Settings + tool + gating + test unit.
4. Brief confirmer/worker (iterare il wording dopo la prima run A03).
5. Smoke §6 + fix.
6. ARCHITECTURE.md + questo piano aggiornato con l'esito.

## 10. Esito atteso (misurabile)

Sulla prossima run globale A03 (o replay confermer dedicato): la catena
updater→migrations passa da `suspected/statically_validated` a `confirmed` con
precondizione dichiarata, con transazioni HTTP a favore dell'origine avvelenata
visibili negli events del sidecar. Nessuna regressione su A01/A05/A07.
