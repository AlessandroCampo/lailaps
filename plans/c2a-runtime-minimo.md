# C2a — Runtime minimo: Laravel → TypeScript → risultato

Data: 9 ottobre 2026. Piano per implementazione autonoma; pseudocodice, non codice pronto da copiare. Nessun runtime nuovo implementato da questo documento.

## 1. Obiettivo e scope

**Dal browser avvio una run; un job Laravel lancia Node, il runtime attraversa AI SDK con un modello simulato, esegue una lettura reale, salva un checkpoint e rende il risultato consultabile a DB. Posso interromperlo senza perdere il checkpoint.**

P0: un processo TS per run, un modello simulato, un tool read-only, eventi essenziali, usage/tempi, checkpoint e gestione di stop/errori. Il runtime non ha ORM né queue propria. Laravel gestisce account, autorizzazione, job, lifecycle e DB.

C2b aggiunge laboratorio applicativo e Doctor minimo prima di C3. Shell generica, provisioning Docker e comandi nel target non servono per verificare C2a: leggere una fixture non esegue il codice del progetto. La futura shell gira nel laboratorio, non nel processo Node fidato.

## 2. Comunicazione concreta

```text
Browser --POST /audits--> Laravel crea AuditRun e accoda un job
                              |
                              | avvia: node agent/runtime/dist/main.js
                              | stdin: un documento JSON RunSpec, poi EOF
                              v
                         Runtime TypeScript
                              |
                              +-- stdout: un evento JSON per riga --> job --> DB
                              +-- stderr: diagnostica -------------> transcript
                              +-- file: checkpoint/outcome --------> riconciliazione DB

Browser --GET /audits/{id}--> Laravel legge il DB e mostra progresso/risultato
Browser --POST /audits/{id}/cancel--> flag DB --> job --> marker stop --> AbortController
```

stdin/stdout/stderr sono canali del processo, letti e scritti durante la sua esecuzione. Il server HTTP resta Laravel. Il job continua anche se il browser chiude la pagina. In locale PHP worker e Node vedono la stessa directory privata della run; con container servirà un mount esplicito in C2b.

Il job deve usare Process::start(), non un lancio in background senza supervisore. Puoi usare Symfony Process, già usato da Laravel e dall'attuale ExecuteAuditRun. Comando come array di argomenti, senza costruire una stringa shell. [Symfony Process](https://symfony.com/doc/current/components/process.html), [Laravel Processes](https://laravel.com/framework/docs/processes).

## 3. Requisiti minimi

| Requisito | Implementazione minima | Completato quando |
|---|---|---|
| Identità e avvio | AuditRun collegata a progetto/revisione; job con una sola attempt e claim atomico della run queued. | Consegna duplicata del job non avvia due processi per la stessa run. |
| Contratto di processo | RunSpec su stdin; stdout NDJSON; diagnostica su stderr. | Evento spezzato in due chunk e più eventi nello stesso chunk vengono letti correttamente. |
| Tool loop reale | AI SDK con mock di modello; un tool di lettura confinato alla revisione. | Il modello chiama il tool, riceve il contenuto e genera il riepilogo. |
| Accounting e tempi | Una registrazione per chiamata; token/cache/EP e durata, con origine dichiarata. | Nessun doppio conteggio; usage simulato identificato, valori mancanti distinti da zero. |
| Checkpoint e proiezione | TS scrive atomicamente l'outcome; Laravel proietta snapshot cumulativi a DB usando una sequenza crescente. | Riaprire la pagina mostra lo stesso risultato; una proiezione fallita si recupera dall'ultimo outcome valido. |
| Stop ed errori | AbortController, deadline, marker di cancellazione e stop forzato dopo grace period. | Stop/timeout/provider error conservano il lavoro già salvato e non producono completed. |

Su AuditRun bastano lifecycle, progetto/revisione, cancel_requested, last_seq, artifact_path e un campo JSON result. Non serve una tabella per tutti gli eventi. Il report TS è autorevole per output/usage; Laravel è autorevole per il lifecycle del processo e della persistenza.

## 4. Contratti

Esempio di RunSpec interno, costruito e validato da Laravel:

```json
{
  "version": 1,
  "run_id": "run-123",
  "source_root": "C:/private/revisions/rev-42",
  "run_dir": "C:/private/runs/run-123",
  "model_mode": "mock",
  "max_requests": 3,
  "max_output_tokens": 200,
  "timeout_seconds": 30
}
```

Non è uno schema da mostrare al modello. Percorsi e limiti provengono dal server; il modello vede istruzioni e il piccolo schema del tool. Provider credential, quando servirà, tramite ambiente privato del processo, non in argv/stdout. Non ereditare indiscriminatamente tutte le credenziali del processo Laravel.

Evento: una riga UTF-8 completa, delimitata da newline. ID e sequenza assegnati dal runtime; payload normale, non il DTO esposto al modello:

```json
{"version":1,"run_id":"run-123","seq":1,"type":"started","at":"2026-10-09T10:00:00Z","payload":{}}
{"version":1,"run_id":"run-123","seq":2,"type":"tool_started","at":"2026-10-09T10:00:01Z","payload":{"call_id":"call-1","tool":"read_source"}}
{"version":1,"run_id":"run-123","seq":3,"type":"checkpoint","at":"2026-10-09T10:00:02Z","payload":{"step":1}}
```

Tipi sufficienti: started, tool_started, tool_finished, checkpoint, finished. Il checkpoint completo è nell'outcome a percorso noto, non nella riga evento. Lo schema del file può avere run_id, checkpoint_seq e report con stato runtime, summary, usage cumulativo, clock e ultimi risultati. SAVE_CHECKPOINT riserva la sequenza dell'evento, salva atomicamente l'outcome con quella checkpoint_seq, poi emette l'evento corrispondente; serializza le scritture. Il transcript Laravel conserva eventi e diagnostica senza diventare una seconda sorgente di report.

## 5. Pseudocodice Laravel

Controller: autorizza progetto/revisione, crea la run queued e dispatch del job dopo il commit della transazione. Un endpoint cancel autorizzato imposta cancel_requested. La pagina può fare polling del DB ogni secondo; SSE/WebSocket non sono necessari per questo gate.

Nel job, Symfony Process; le funzioni in maiuscolo sono responsabilità da implementare, non API del framework:

```php
$spec = BUILD_VALIDATED_SPEC($run);
// claim atomico queued -> running; job tries=1, niente retry automatico dell'audit
if (! CLAIM_QUEUED_RUN($run)) return;
$buffer = '';

$process = new Process(
    [config('runtime.node'), base_path('agent/runtime/dist/main.js')],
    base_path(), SAFE_RUNTIME_ENV(), json_encode($spec),
    $spec['timeout_seconds'] + 5 // supervisore concede tempo per finalizzare
);

try {
    $process->start(function ($type, $chunk) use (&$buffer, $run) {
        APPEND_TRANSCRIPT($run, $type, $chunk);
        if ($type === Process::OUT) {
            FEED_NDJSON($buffer, $chunk, fn ($event) => ACCEPT_EVENT($run, $event));
        }
    });

    while ($process->isRunning()) {
        if (CANCEL_REQUESTED($run)) {
            WRITE_CANCEL_MARKER_ONCE($spec['run_dir']);
            FORCE_STOP_AFTER_GRACE_IF_STILL_RUNNING($process, 3);
        }
        $process->checkTimeout();
        usleep(100_000);
    }
} catch (Throwable $error) {
    RECORD_TECHNICAL_FAILURE($run, $error);
} finally {
    if ($process->isRunning()) $process->stop(3);
    DRAIN_AND_VALIDATE_REMAINING_OUTPUT($process, $buffer);
    RECONCILE_LAST_VALID_OUTCOME($run); // non inventare report se manca
    FINALIZE_LIFECYCLE($run, $process->getExitCode());
}
```

Parser: `$buffer .= $chunk`; finché contiene `\n`, estrai una riga completa e decodifica JSON. Mantieni il frammento restante per il chunk successivo. Un frammento residuo a EOF è errore di protocollo. Limita lunghezza della riga/buffer; stdout di librerie non deve contenere console.log diagnostici.

ACCEPT_EVENT controlla versione, run_id e sequenza; per checkpoint legge il file nel percorso già deciso dal server. Applica lo snapshot **cumulativo** solo se checkpoint_seq > last_seq, in transazione. Non incrementare i contatori a DB a ogni evento: una consegna duplicata li gonfierebbe. Gap di sequenza sono diagnostici e si riconciliano dal file; seq inferiore o uguale non riapplica una proiezione.

Non segnare completed soltanto perché arriva finished: servono exit 0, outcome terminale valido, run non cancellata e proiezione riuscita. In caso di errore di proiezione conserva l'artifact e segnala il problema; non rilanciare l'agente per rifare un update SQL. Timeout del job e retry_after della queue devono essere maggiori del timeout del processo più grace/finalizzazione, per evitare worker ucciso o doppia esecuzione prematura.

## 6. Pseudocodice TypeScript e AI SDK

Gli esempi seguono **AI SDK 7**; versione stable osservata il 9 ottobre: ai 7.0.137. Blocca le versioni nel lockfile. Nella v7 i nomi sono isStepCount, responseMessages e MockLanguageModelV4; molti esempi v6 usano nomi differenti. [Tool calling](https://ai-sdk.dev/docs/ai-sdk-core/tools-and-tool-calling), [Testing](https://ai-sdk.dev/docs/ai-sdk-core/testing).

Il modello restituisce testo oppure una richiesta di tool. Con execute, AI SDK valida l'input, invoca la funzione TS e produce il messaggio di risultato. Può anche continuare automaticamente per più step. Qui usiamo **una chiamata per iterazione**, per salvare checkpoint e verificare i limiti prima della successiva. Non serve implementare il protocollo dei messaggi tool a mano.

```ts
import { generateText, tool, isStepCount, type ModelMessage } from 'ai';
import { z } from 'zod';

const spec = VALIDATE(await READ_STDIN_TO_EOF());
const controller = new AbortController();
const stopWatchers = WATCH_DEADLINE_AND_CANCEL_MARKER(spec, controller);
const model = MAKE_SCRIPTED_MOCK(); // stessa interfaccia LanguageModel dello SDK
const messages: ModelMessage[] = [{ role: 'user', content: 'Leggi README.md e riassumilo.' }];
const state = INITIAL_REPORT(spec); // usage sintetico, durata monotonic + UTC

const tools = {
  read_source: tool({
    description: 'Legge un file della revisione assegnata.',
    inputSchema: z.object({ path: z.string() }),
    execute: async ({ path }, { toolCallId }) => {
      controller.signal.throwIfAborted();
      EMIT('tool_started', { call_id: toolCallId, tool: 'read_source' });
      const text = await READ_BOUNDED_INSIDE_SOURCE_ROOT(spec, path, controller.signal);
      EMIT('tool_finished', { call_id: toolCallId, tool: 'read_source' });
      return text; // testo semplice; ref/hash/clock restano metadati del runtime
    },
  }),
};

try {
  EMIT('started', {});
  for (let step = 0; step < spec.max_requests; step++) {
    controller.signal.throwIfAborted();
    CHECK_BUDGET_BEFORE_REQUEST(state); // limite richieste + EP già consumati
    RECORD_REQUEST_STARTED(state);
    const result = await generateText({
      model, messages, tools,
      stopWhen: isStepCount(1), // esegue i tool dello step; non richiama il modello
      maxRetries: 0,
      maxOutputTokens: spec.max_output_tokens,
      abortSignal: controller.signal,
    });

    RECORD_USAGE_ONCE(state, result.usage);
    messages.push(...result.responseMessages);
    UPDATE_REPORT(state, result);
    await SAVE_CHECKPOINT(state, { step }); // file atomico, poi evento
    ASSERT_STEP_USABLE(result); // finishReason inatteso/errore: run incompleta

    if (result.finishReason === 'stop' && result.toolCalls.length === 0) {
      state.status = 'completed';
      break;
    }
  }
  if (state.status !== 'completed') state.status = 'budget_exhausted';
} catch (error) {
  state.status = CLASSIFY_FAILURE_OR_ABORT(error, controller.signal.reason);
  RECORD_MISSING_USAGE_IF_REQUEST_INTERRUPTED(state);
} finally {
  stopWatchers();
  await SAVE_FINAL_OUTCOME_OR_REPORT_FATAL_WRITE_ERROR(state);
  await EMIT_AND_FLUSH('finished', { status: state.status });
  SET_EXIT_CODE(state); // 0 solo completed; altro exit -> Laravel verifica outcome
}
```

READ_BOUNDED_INSIDE_SOURCE_ROOT risolve il path reale e verifica appartenenza alla revisione, inclusi symlink, oltre al limite di output. Non basta vietare la stringa `..`. Un percorso non consentito restituisce un risultato di errore utilizzabile; errore di persistenza è fatale. In C2a nessuna shell o esecuzione del sorgente sul processo Node.

WATCH_DEADLINE_AND_CANCEL_MARKER usa timer non bloccanti e abort(reason=timeout/cancelled). Le funzioni tool devono rispettare l'abort; un AbortSignal da solo non ferma un comando esterno già avviato. La cancellazione tramite file funziona anche su Windows senza affidarsi a SIGTERM. Il supervisore attende la chiusura cooperativa e forza lo stop dopo la grace. Per processi/container dei futuri tool servirà cleanup esplicito.

EMIT produce solo JSON + newline con seq crescente. Gestisci il drain di stdout e la chiusura della pipe; niente process.exit immediato che può perdere gli ultimi eventi. Salvataggio finale fallito non produce finished/completed di successo: stderr + exit non-zero, preservando l'ultimo checkpoint valido.

ASSERT_STEP_USABLE distingue risposte troncate, errori e tool call non eseguite dai due esiti attesi: tool eseguito oppure risposta finale. Un errore recuperabile del tool può essere testo restituito al modello; non è un errore del supervisore. Nessun errore tecnico diventa completed soltanto perché manca una tool call.

Non delegare le scritture indispensabili soltanto a callback di telemetria: nella v7 alcuni callback catturano silenziosamente gli errori. Nel loop esplicito SAVE_CHECKPOINT è atteso prima della prossima chiamata. [Tool execution lifecycle callbacks](https://ai-sdk.dev/docs/ai-sdk-core/tools-and-tool-calling#tool-execution-lifecycle-callbacks).

## 7. Mock, accounting e ordine di lavoro

MAKE_SCRIPTED_MOCK usa MockLanguageModelV4 da ai/test, secondo il contratto della versione installata. Script minimo:

```text
Chiamata 1 -> tool-call: read_source({path: "README.md"}), ID call-1
Chiamata 2 -> testo: "La fixture descrive un'applicazione di esempio."
Entrambe -> usage sintetico noto, finishReason coerente.
```

Non sostituire generateText con un if che scrive direttamente il risultato: perderesti il test dell'integrazione SDK/tool/history. Il mock simula il provider, il tool e la persistenza restano reali. Aggiungi scenari di ritardo abortibile ed errore provider senza credenziali API.

Accounting: le risposte riuscite registrano usage una volta; non sommare anche il totale SDK alla somma dei singoli step. Conserva input, output e cache quando disponibili, requests started/completed e richieste senza usage. Un'interruzione può aver consumato token non restituiti: segnala unknown, non costo zero. EP da una sola tabella di pesi/prezzi versionata, compatibile con C0. Limiti request/output/deadline contengono il consumo; un cap EP è verificato ai boundary e deve dichiarare eventuale overshoot dell'ultima risposta. Costo USD stimato separato da costo provider. Qui tutto l'usage del modello è simulated, non una misura di performance reale.

Ordine piccolo:

1. Processo Node legge stdin, salva un risultato fisso ed emette due righe. Laravel lo avvia da job e legge stdout.
2. Parser corretto, result a DB e riapertura della pagina.
3. Sostituisci il risultato fisso con il mock AI SDK e read_source reale.
4. Checkpoint per step, usage e tempi.
5. Cancel, timeout e failure injection; riconciliazione dal file.

Parti da main.ts, run.ts e protocol.ts, più i test mirati; tool e mock possono restare in run.ts finché piccoli. Compila TS in dist e lancia Node sul JS; ai e zod sono dipendenze runtime, TypeScript e tipi Node sono tooling. Non serve installare un provider reale per il mock.

Gate C2a: fixture letta realmente; due chiamate mock con usage atteso; checkpoint/riepilogo a DB collegati alla run; nessuna duplicazione su replay degli eventi; stop/timeout/errori preservano il checkpoint; DB failure non causa nuove chiamate. Test parser con chunk spezzati/multipli, accesso fuori root, consegna duplicata del job, output finale troncato e finalizzazione fallita. Nessun benchmark LLM è richiesto per questo gate.

P1/C2b+: stream di token live, compaction, runtime HTTP autonomo, queue TS, resume completo, RAG e provisioning non appartengono al bootstrap C2a. C2b costruisce il laboratorio e il Doctor sul percorso di esecuzione già verificato.
