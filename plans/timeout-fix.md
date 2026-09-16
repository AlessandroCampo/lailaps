# P0 — Handoff provenance e timeout resilienti

## Summary

Il log analizzato proviene dall’Handoff Reader, non dal Confirmer. La validazione richiede `source_ref_ids` pur esponendoli come opzionali e il fallback già produce fatti senza ref: è un vincolo incoerente dell’harness. Inoltre la deadline globale di 240 s può essere interamente consumata dal primo tentativo HTTP da 120 s, rendendo i retry configurati inutilizzabili.

## Key Changes

- Separare l’output model-facing dell’Handoff Reader dal payload persistito:
  - il modello emette solo `topic`, `fact` e `unknowns`;
  - l’orchestratore aggiunge automaticamente le source ref raccolte durante l’episodio handoff;
  - se non esistono ref disponibili, conserva il fatto senza generare retry o spam di validazione.
- Rimuovere il validator che trasforma l’assenza di ref in `ModelRetry`; mantenere la validazione soltanto degli ID eventualmente presenti in payload legacy/compatibili.
- Rendere esplicito nel prompt che i fatti non dimostrabili vanno in `unknowns`, senza chiedere al modello di gestire ID opachi.
- Introdurre un timeout per singolo tentativo modello di 90 s e un budget complessivo di 270 s per turno: fino a tre tentativi inclusi, con backoff solo entro il budget residuo.
- Registrare telemetria e checkpoint distinti per `attempt_timeout` e `turn_deadline_exhausted`, includendo durata e tentativo, così il log non simula più un generico `ReadTimeout` del provider.
- Se l’Handoff Reader esaurisce timeout/retry, mantenere il `CategoryHandoff` deterministico, aggiungere un `unknown` tecnico e proseguire con la categoria successiva; non trasformare la run in `incomplete`.
- Aggiornare `ARCHITECTURE.md`, poiché cambiano contratto model-facing dell’handoff e gestione strutturale delle deadline.

## Test Plan

- Handoff con fatti senza ref: accettato, senza validation retry.
- Handoff con letture sorgente: l’orchestratore associa le ref raccolte ai fatti persistiti.
- Fallback deterministico: conserva contesto senza ref e segnala l’assenza di narrativa agentica.
- Un tentativo bloccato oltre 90 s: viene cancellato e ritentato entro 270 s.
- Tre timeout: telemetria/checkpoint distinguono tentativi e scadenza finale.
- Timeout dell’handoff: la run continua con handoff deterministico e la categoria successiva riceve il contesto disponibile.
- Timeout di un ruolo investigativo: conserva il comportamento di recovery esistente, senza duplicare tool call o history.

## Assumptions

- P0 non modifica i criteri di evidenza per finding, CandidateHandoff o Confirmer.
- Le ref auto-associate all’handoff sono provenance dell’episodio, non una dichiarazione che ogni frase corrisponda a una singola riga precisa.
- P1+: metriche aggregate per modello/provider e tuning differenziato delle deadline per ruolo, solo dopo dati reali sui nuovi eventi.
