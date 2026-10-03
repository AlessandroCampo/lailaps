# Piano P0 — benchmark isolato del deduper su ReaderLead nel DB

Implementato e verificato offline. Consegna e comandi effettivi:
[P0-benchmark-deduper-db-delivery-20260929.md](P0-benchmark-deduper-db-delivery-20260929.md).
Il manifest DB conserva riferimenti/hash agli originali e alla copia congelata
su file, anziché duplicare tutti i payload nel parent: il corpus Cacti reale
supera `max_allowed_packet` se reinserito interamente in un singolo record.

## Scopo

Aggiungere `benchmark:deduper`, dato progetto e run Reader, per recuperare dal DB tutte le ReaderLead strutturate valide della run, deduplicarle con il runtime P0 esistente e persistire verdetti e canoniche nel DB. Nessuna nuova discovery, Reader, evaluator, Confirmer o Worker viene avviata da questo comando.

Sono esclusi dal corpus `AreaEnrichmentLead`, `ReconArea`, task e accordo/promozione delle aree. La provenienza da un assignment nato da enrichment non esclude invece le sue vere `ReaderLead`: sono comunque lead Reader della run selezionata.

Il deduper decide sovrapposizione e novelty relativa al corpus selezionato; pass non significa vulnerabilità confermata o ground truth. Non cambia il contratto del modello: pass/block minimali, solo partial produce un nuovo payload.

## Evidenza nel codice attuale

- `BenchmarkStageArtifact` / `benchmark_stage_artifacts` hanno già ruolo, output_type, run_id, parent_artifact_id, status, accepted, is_canonical, payload, usage, metrics e configuration_signature. La tabella può ospitare questa fase senza nuove tabelle o migrazioni nel P0.
- `BenchmarkReaderGlobal` persiste le lead con il **run_id del child**, non del parent globale. `ReaderGlobalRun.payload.assignments` identifica i child; ogni lead è figlia del relativo `ReaderRun`. Non basta quindi `where(run_id, parent_run_id)`.
- Gli output storici DB non conservano sempre `output_id` o tempo esatto di acquisizione. Serve una proiezione con identità DB stabile, non una ricostruzione arbitraria degli ID del vecchio ledger.
- `reader-normalize` e `Deduper` hanno già schede compatte, originali recuperabili, cap, runner, budget, circuiti e ripresa. Riutilizzarli, senza un secondo loop inferenziale PHP.
- `BenchmarkConfirmer` usa già subject DB. La selezione generica corrente trova ReaderLead valide, anche senza promozione del deduper; il percorso `--dedup-artifact` calcola completezza ma non la impone prima di selezionare. Il nuovo percorso deve avere un gate esplicito e selezione scoped.

## 1. Interfaccia del comando

Interfaccia proposta:

```powershell
php artisan benchmark:deduper yeswiki <reader-run-id> --roles-config=plans/deduper-role-config.example.json --preflight-only
php artisan benchmark:deduper yeswiki <reader-run-id> --roles-config=plans/deduper-role-config.example.json
php artisan benchmark:deduper yeswiki <reader-run-id> --dedup-run=<deduper-execution-id> --resume-failed
```

Stesso comando per Cacti. `reader-run-id` può identificare un `ReaderRun` singolo oppure il `ReaderGlobalRun` parent dei test del mattino.

- Senza `--dedup-run`, crea una nuova esecuzione isolata, stampa ID, corpus e funding esplicito. Usa GLM 5.3 Flash / InferenceNet con i default P0 esistenti. Il budget viene dalla configurazione del deduper; nessun budget Reader viene ricaricato o riutilizzato implicitamente.
- Con `--dedup-run`, riapre quella specifica esecuzione e il suo envelope. Non crea una nuova directory o una nuova riserva di funding. Configurazione e input devono essere compatibili; senza override usa la configurazione persistita.
- `--resume-failed` richiede `--dedup-run`: riusa i successi, riprova tecnici/pending e non ritenta automaticamente gli inconclusivi semantici.
- `--preflight-only` congela/verifica input e simula tutti pass, con zero inferenza. Non pubblica promozioni DB.
- Eventuali override individuali già standardizzati restano incompatibili con `--roles-config`; non aggiungere un secondo insieme di parametri per timeout/retry/budget.
- Exit code 0 completato, 2 incompleto/sospeso, 1 input/integrità fatali. Un corpus vuoto, run assente o progetto incompatibile è un errore esplicito.

Il lancio paid resta manuale; sviluppo e accettazione non lo eseguono autonomamente.

## 2. Selezione e congelamento del corpus

1. Risolvere il parent Reader nel DB per progetto, run_id e output_type, senza selezionare “l'ultima run” né usare il Recon condiviso per trovare tutti i child del progetto.
2. Per una run globale usare esclusivamente gli assignment registrati in quel parent; per una run singola usare esclusivamente il ReaderRun selezionato. Verificare child, progetto e unico source_commit.
3. Selezionare tutte le righe originali `role=reader`, `output_type=ReaderLead`, `status=valid`, appartenenti ai ReaderRun risolti. Non filtrare per score, CVE, categoria o accepted del deduper storico. Conservare il flag accepted originale come metadata, senza reinterpretarlo o modificarlo. Una lead strutturata valida resta utilizzabile anche se il suo ReaderRun ha avuto un errore tecnico successivo.
4. Escludere prodotti sintetici/canonici di altre esecuzioni e ogni enrichment/task. Nessuna selezione per anchor o limite silenzioso sul numero di lead.
5. Salvare manifest immutabile: ID artifact DB, ReaderRun parent, source commit, payload/source_refs e content hash, ordine, criterio di ordinamento, configurazione/versioni e provenienza della run.
6. Usare ID qualificato `origin_run_id:artifact_db_id`; mantenere la mappa verso gli ID DB e qualificare i source_ref_id solo nella proiezione. Gli originali DB restano identici.
7. Se il tempo di acquisizione originale è disponibile nei metadata durevoli, usarlo; altrimenti congelare un ordine deterministico `created_at, id`, dichiarando `ordering_basis=db_persistence`. Non presentarlo come ordine esatto delle discovery. La ripresa usa sempre l'ordine congelato.

P0 opera sulle run già persistite/stabili. Se il parent globale non è ancora disponibile nel DB o manca un child dichiarato, segnala il problema senza inseguire run attive o ampliare il corpus. Non rimporta automaticamente file nel DB. Un parent terminale incompleto può fornire un corpus congelato parziale: questa limitazione rimane esplicita nel report e non diventa completezza della discovery globale.

## 3. Esecuzione: riuso del runtime

Il comando Laravel prepara l'input lead-only e usa `BenchmarkStageProcessRunner` per invocare `reader-normalize`, in una directory propria della nuova esecuzione. Nessun `mark_dispatched`; la normalizzazione non avvia alcun downstream.

Prima dell'inferenza esegue il preflight conservativo sull'intero corpus congelato. Se supera 64.000 token, salva stato e report e termina con 2 senza chiamate al provider. Il precedente corpus Cacti superava il cap: l'esclusione degli enrichment non autorizza ad assumere che il corpus lead-only entri; va misurato nuovamente. Non aggiungere paginazione, tagli o aumenti automatici.

Il modello vede payload Reader, schede compatte ed evidenze registrate, senza score, label, oracle, matched_case_ids o diagnosi del benchmark. Restano tutti i limiti e le verifiche P0: recupero raggruppato, originali integrali per block/partial, pass da compact_index, una sola risorsa extra per retry/riparazione, circuito persistente, cap e riserve prudenziali. Non introdurre accesso libero al repository per questo test.

Il ledger Python resta proprietario dei tentativi/costi. Laravel importa le decisioni durevoli a ogni checkpoint di avanzamento, alla conclusione e prima di una ripresa, compresi gli esiti di exit 2. Una transazione breve riconcilia ciascun checkpoint; nessuna transazione DB resta aperta durante l'inferenza. Una sola invocazione per execution ID può essere attiva.

Se il processo termina prima dell'import, i file già salvati permettono la riconciliazione senza inferenza aggiuntiva. Se manca lo stato finale, la run DB resta incompleta; non diventa completata dal solo termine del subprocess.

## 4. Persistenza: originali, verdetti, canoniche separati

Usare la tabella esistente con tre tipi di record, evitando modifiche alle righe Reader storiche e alle label manuali:

| Record | Ruolo / output_type | Contenuto |
|---|---|---|
| Esecuzione | `deduper / DeduperRun` | Parent Reader, input manifest/hash, ordine/versioni/config, budget esplicito, stato/stop reason, accounting e path del ledger |
| Verdetto | `deduper / DeduperDecision` | Artifact originale, stato tecnico/semantico, decisione pass/block/partial quando valida, related IDs, canonical ID, fingerprint, comparison_basis, riferimenti ai tentativi |
| Prodotto canonico | `deduper / ReaderLead` | Payload effettivo per il downstream, source_refs, original_artifact_ids, canonical ID e relazione di provenienza |

Verdetti e canoniche hanno `parent_artifact_id=DeduperRun.id` e `run_id=deduper_execution_id`. Le relazioni multiple agli originali e ai related sono mappe/lista di ID DB in metadata; il singolo parent non viene usato per fingere una lineage multipla. Il modello non vede questi wrapper DB.

Prima delle chiamate registrare un verdetto pending per ogni lead; poi conservare versioni/tentativi precedenti e indicare quale decisione è corrente. Import idempotente per execution + decision identity e execution + canonical identity, protetto dalla transazione/lock del parent; nessuna duplicazione su restart o import ripetuto. Non duplicare accounting cumulativa della run dentro ogni prodotto e non attribuire costo Reader alle copie canoniche.

| Esito | Verdetto DB | Effetto sulla proiezione canonica |
|---|---|---|
| pass completed | `decision=pass` | Payload Reader invariato; canonica deterministica che conserva riferimento/hash dell'originale |
| block completed | `decision=block`, related original/canonical IDs | Originale e verdetto restano nel DB; nessuna nuova lead promossa per la proposta bloccata |
| partial completed | `decision=partial`, mode e related IDs | Nuova lead con il payload restituito dal modello, evidenze e lineage orchestrate |
| inconclusive / failed_technical / pending | Stato separato; nessun pass inventato | Prodotto provvisorio conservato, escluso dalla selezione Confirmer |

Le copie pass sono proiezioni DB, non nuovi output del modello. Un block significa sovrapposizione, non falso positivo. I verdetti non modificano `label`, `matched_case_id`, `accepted` o `is_canonical` delle lead Reader originali.

Le lead dei test globali hanno spesso `category=global` nel DB, mentre il Confirmer attuale richiede una categoria supportata dal catalogo. Conservare lo scope originale e normalizzare la categoria di handoff dall'eventuale `owasp_category` con il mapping del catalogo. Se non risolvibile, il successivo comando Confirmer richiede una categoria esplicita compatibile tramite il flag esistente; non sceglie arbitrariamente il primo manifest. Questo problema non esclude lead dal corpus del deduper e non introduce una chiamata di classificazione aggiuntiva.

Per partial rispettare la modalità esistente: `merge` sostituisce nella proiezione le canoniche citate; `residual` mantiene quelle precedenti e aggiunge la parte nuova. Una precedente lead pass poi assorbita da merge non deve arrivare insieme alla canonica risultante. La query downstream usa quindi **canoniche finali correnti**, non tutti i verdetti storici pass/partial. Canoniche superate rimangono tracciabili ma non selezionabili.

Una riparazione che cambia la proiezione invalida e ricalcola in ordine le decisioni dipendenti usando il runner esistente. Non modifica run storiche né risultati Confirmer già registrati.

## 5. Contratto minimo per il successivo test Confirmer

Aggiungere al comando esistente soltanto una selezione DB esplicita:

```powershell
php artisan benchmark:confirmer yeswiki --dedup-run=<deduper-execution-id> --path=<source-path>
```

Questa modalità:

- verifica progetto, commit, versione compatibile e **tutte le decisioni completate** del corpus congelato;
- seleziona solamente prodotti `deduper/ReaderLead` della specifica esecuzione, `status=valid`, `accepted=true`, `is_canonical=true`, derivati da pass/partial correnti;
- esclude raw ReaderLead, block, inconclusivi, errori, pending e canoniche assorbite;
- rifiuta combinazioni con `--artifact`, `--dataset` e `--dedup-artifact`, così un filtro non può aggirare lo scope del nuovo gate;
- usa `ConfirmerBenchmarkDataset::modelSubject` per passare payload ed evidenze nel formato ReaderLead già previsto. Nessun nuovo loop Confirmer è necessario.

Le promozioni restano provvisorie/escluse fino alla completezza del DeduperRun. Anche il percorso file `--dedup-artifact` deve applicare il gate v2 prima della selezione; evitare due regole diverse di completezza. La modalità legacy senza `--dedup-run` non viene trasformata in una selezione automatica della “ultima deduplica”.

Non creare oracle da pass/partial e non copiare automaticamente etichette di correttezza su payload modificati. Il successivo test Confirmer può misurare funzionamento, decisioni e costi; metriche di accuratezza richiedono ground truth revisionata separatamente.

## 6. Sequenza di implementazione e accettazione

1. Selettore DB scoped e input manifest: parent globale/singolo, child e snapshot, lead-only, identità e ordine stabili.
2. `BenchmarkDeduper` + piccolo import nel registry esistente: run, tutti i verdetti e canoniche finali; preflight, accounting, incompletezza e resume.
3. Selezione `--dedup-run` nel Confirmer e gate condiviso di completezza; nessun lancio Confirmer nel test del deduper.
4. Aggiornare `ARCHITECTURE.md` con flusso DB e semantica delle promozioni, più esempi CLI e report lead-only.

Test offline mirati con DB di test e runner stub:

- parent globale recupera tutte e sole le lead dei suoi child; singola run, altro progetto/snapshot/run esclusi;
- AreaEnrichmentLead/ReconArea assenti da input, conti, indice e prodotti; ReaderLead nate da assignment di enrichment incluse;
- pass/block/partial merge e residual salvati, originali e hash immutati;
- merge successivo rimuove dal filtro la vecchia pass e conserva lineage;
- tutti i pending/tecnici/inconclusivi sono visibili nel DB e non alimentano Confirmer;
- cap/context/budget/circuito incompleti impediscono Confirmer e non diventano pass;
- import ripetuto e successo riaperto: zero nuove righe duplicate e zero inferenza; errore→successo con tentativi/costi precedenti e invalidazione dei dipendenti;
- morte tra ledger e import: riconciliazione degli esiti già salvati senza rifinanziamento;
- label/oracle/score non arrivano al deduper e nessun verdetto diventa ground truth.

Eseguire soltanto suite mirate PHP/Python e smoke container senza rete inferenziale. Lo smoke paid e i due test reali sui corpus DB vengono consegnati come comandi da lanciare manualmente.

## P1 escluso

Promozione/accordo di aree ed enrichment, integrazione nel dispatch online, corpus cross-run, UI di confronto, nuove tabelle relazionali o infrastruttura di retrieval. Nessuna di queste estensioni serve per isolare il deduper sulle lead già persistite.
