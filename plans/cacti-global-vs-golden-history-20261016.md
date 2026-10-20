# Cacti: full scan del 16 ottobre e precedenti con la stessa Golden Recon

Analisi offline degli artifact; nessuna nuova chiamata LLM, nessuna modifica agli outcome o al motore.

La run nuova dimostra che il downstream esegue esperimenti e conserva risultati, ma non dimostra ancora un miglioramento misurato del recall. Il confronto storico disponibile riguarda due sessioni Reader-only. Inoltre lo score nuovo contiene abbinamenti al catalogo incompatibili con le ipotesi investigate: il 34,22% va trattato come un risultato automatico da correggere/adjudicare.

## Campione verificato

- [29 settembre — Reader globale](../storage/app/runs/cacti/reader-global/cacti-reader-global-20260929-084906/cacti-reader-global-20260929-084906-outcome.json).
- [1 ottobre — Reader globale](../storage/app/runs/cacti/reader-global/cacti-reader-global-20261001-190037/cacti-reader-global-20261001-190037-outcome.json).
- [16 ottobre — full scan](../storage/app/runs/cacti/global/cacti-global-20261016-203045/cacti-global-20261016-203045-outcome.json).

Tutti usano `01M3NYRPZBBXE8AQM1J9XZR3TZ`, snapshot `6482af547c204199e829b7a0df0b7a13db3e0a58`. I payload `category_recon` delle due sessioni storiche sono esattamente uguali alla Recon del pass nuovo: 15 aree. La nuova run registra zero richieste ed EP incrementali Recon. Non risultano altri full scan storici con quel selettore negli outcome Cacti esaminati.

Il modello Reader è sempre `xiaomi/mimo-v2.6-pro`; il reasoning storico è **high**, quello nuovo **medium**. Anche budget, concorrenza, ammissione delle lead e downstream differiscono: stessa Recon non equivale a esperimento con una sola variabile modificata.

## Reader sulle stesse 15 aree iniziali

Per il 29 settembre questa tabella somma soltanto gli assignment con `origin=golden`: esclude i 17 enrichment eseguiti successivamente. Il 1 ottobre non ha eseguito gli enrichment proposti; la run nuova ha avviato tutti i 15 Reader iniziali.

| Metrica | 29 settembre | 1 ottobre | 16 ottobre |
|---|---:|---:|---:|
| Reasoning Reader | high | high | medium |
| Concorrenza Reader | 3 | 3 | 4 |
| Budget | 500k EP per area | 500k EP per area | 2M EP aggregati |
| EP Reader consumati | 5.568.039 | 6.978.758 | 2.005.957 |
| Richieste Reader | 922 | 1.186 | 539 |
| Token input + output Reader | 89.658.471 | 149.235.950 | 26.831.429 |
| Lead registrate/ammesse | 140 | 201 | 27 ammesse |
| Aree dichiarate complete | 4/15 | 5/15 | 9/15 |
| Aree con failure tecnica | 5/15 | 0/15 | 4/15 (`model_unavailable`) |
| Altre aree incomplete | 6/15 | 10/15 | 2/15 (cap economico) |

Il Reader nuovo consuma il **71,3% di EP in meno** del 1 ottobre, ma il suo cap aggregato è molto più restrittivo del precedente budget per area. Il dato favorevole è che chiude semanticamente più aree, 9 contro 5. Le chiusure sono decisioni del modello sulla mappa assegnata, non prova di copertura esaustiva.

Non emerge un guadagno dimostrato dal volume delle lead: i conteggi storici non hanno una validazione downstream equivalente e possono contenere duplicati o ipotesi non sfruttabili. Nella run nuova ci sono 38 emissioni `ReaderLead`, ma soltanto 27 ammissioni. Le altre 11 sono errori di qualificazione: 6 ripetizioni della stessa pista CLI con una reference invalida; 5 emissioni senza reference autorevoli, relative alla pista `Net_Ping` e a quella su `reports_tree_has_graphs`. Non sono 38 ipotesi indipendenti.

La pista su `lib/reports.php` torna ripetutamente negli output respinti dell'area 13. Il benchmark finale la segna comunque `not_reached`: qui “not reached” è la classificazione dell'evidenza persistita dall'evaluator, non dimostra che il modello non abbia mai nominato il file o il comportamento.

Rispetto al 1 ottobre la stabilità Reader peggiora: quattro aree finiscono `model_unavailable`, con 11 deadline Reader registrate. Rispetto al 29 settembre il campione iniziale passa invece da cinque a quattro failure tecniche. Non attribuire questi errori a un provider specifico senza ulteriori evidenze.

## Cosa aggiunge il full scan

| Indicatore del 16 ottobre | Valore |
|---|---:|
| Lead ammesse | 27 |
| Investigazioni arrivate al lavoro del modello | 20 |
| Investigazioni fallite preparando il toolbox | 7 |
| Promozioni statiche `CandidateHandoff` | 13 |
| Finding conservati `confirmed` | 10 |
| Finding nel bucket `suspected` | 10 |
| Chiusure statiche | 6 |
| Rigetti dinamici | 1 |
| EP fase statica | 370.193 |
| EP fase dinamica | 868.963 |
| EP complessivi, incluso Reader | 3.245.113 |
| Richieste modello complessive | 1.241 |
| Durata dalla creazione della run alla fine del pass | 2h 28m 01s |
| Durata del pass investigativo | 2h 19m 40s |

Tra i dieci sospetti ci sono due piste promosse staticamente, una bloccata prima della promozione e sette indagini non avviate con successo. I sei `closed` e il `rejected` sono nel bucket tecnico `rejected_inconclusive`: non sono sette rigetti dinamici.

Il maggior consumo dinamico per singola lead è **259.617 EP** su **1.000.000 disponibili**, nell'investigazione 10. Il cap dinamico non è il collo di bottiglia osservato e non c'è evidenza in questa run per raddoppiarlo.

La coda introduce attese consistenti con due investigator: media 30m 34s, mediana 32m 43s, massimo 67m 06s tra ammissione e passaggio a `running`. Queste durate misurano la coda, non il lavoro attivo. Le ultime sette indagini falliscono prima delle richieste modello.

Le durate complessive storiche sono 4h 26m 43s il 29 settembre e 3h 13m 31s il 1 ottobre. La prima comprende 17 enrichment aggiuntivi; entrambe sono Reader-only. Il tempo inferiore del nuovo full scan è favorevole come costo operativo osservato, ma non consente una conclusione causale sull'efficienza del solo Reader.

## Catalogo: drift delle ipotesi e problemi di scoring

| Caso | 29 settembre, diagnostica Reader | 1 ottobre, diagnostica Reader | 16 ottobre, stage automatico |
|---|---|---|---|
| CVE-2026-39938 | matched lead | matched lead | statico |
| CVE-2026-39899 | matched lead | matched lead | sospetto |
| CVE-2026-40941 | ipotesi diversa sullo stesso anchor | anchor letto senza lead | anchor raggiunto |
| CVE-2026-39893 | solo file | ipotesi diversa sullo stesso anchor | solo file |
| CVE-2026-39955 | anchor letto senza lead | solo file | dinamico, abbinamento contestabile |
| CVE-2026-39951 | anchor letto senza lead | matched lead | non raggiunto secondo l'evaluator |
| CVE-2026-46531 | anchor letto senza lead | anchor letto senza lead | anchor raggiunto |
| CVE-2026-39902 | anchor letto senza lead | anchor letto senza lead | solo file |
| CVE-2026-39900 | ipotesi diversa sullo stesso anchor | anchor letto senza lead | statico, abbinamento contestabile |

I match automatici con una lead passano da 2 a 3 a 4, ma **almeno due dei quattro abbinamenti nuovi non corrispondono al root cause del caso**. Le diagnostiche storiche non equivalgono a un'adjudication indipendente; la tabella non è un confronto di score normalizzati con un unico evaluator.

### CVE-2026-39955: SQL injection associata a due errori di autorizzazione

Nel [manifest injection](../agent/pentest-agent/benchmarks/targets/cacti/manifests/a05-injection.json), il caso riguarda la validazione regexp non ancorata nell'azione `save` di `graph_view.php`, con input che raggiunge SQL. L'outcome lo abbina alle investigazioni **5 e 9**:

- la 5 verifica l'enumerazione di alberi negati attraverso `action=ajax_search`;
- la 9 verifica una JOIN errata che fa dipendere i permessi dell'utente da un gruppo con ID collidente, anche senza membership.

Titoli, evidenza iniziale e narrativa dinamica descrivono problemi di autorizzazione distinti dalla SQL injection del manifest. Questo non invalida automaticamente i due finding come risultati fuori catalogo; invalida l'uso di quell'abbinamento come prova di conferma della CVE SQL injection. L'evaluator assegna invece 5 punti al caso.

### CVE-2026-39900: parametro tab associato a una pista Referer respinta

Nel [manifest XSS](../agent/pentest-agent/benchmarks/targets/cacti/manifests/a05-xss.json), il caso riguarda `tab` nel contesto JavaScript dei profili. L'outcome lo abbina all'investigazione **16**, che riguarda `HTTP_REFERER` persistito in sessione e poi riflesso nel pulsante Return. L'investigator respinge questa pista dopo aver verificato `sanitize_uri`. L'evaluator accredita comunque 3 punti statici al caso `tab`: sia l'input sia il verdetto finale rendono questo credito non utilizzabile come misura di recall della CVE.

Non ho ricalcolato né sovrascritto lo score. **15,40/45 e 34,22% restano i valori registrati, non un recall validato.** Non basta sottrarre gli otto punti contestati per ottenere un nuovo score: servono adjudication coerente e ricalcolo degli stage.

Il discovery yield 56 accredita 12 finding, 10 dinamici e 2 statici; 9 sono classificati fuori catalogo e ci sono 16 adjudication pendenti. È una misura degli stati registrati, non un conteggio di root cause indipendenti verificati. Anche la deduplica non prova indipendenza: 13 delle 27 lead ammesse hanno un'identità canonica non valida e passano con fallback.

## Perché la run è failed

Dieci record fatal corrispondono a tre gruppi di problemi; due record descrivono lo stesso errore dell'investigazione 19.

1. **Investigazione 18, fine 22:29:15:** `InvestigatorOutputFailure: Nessun payload completo da riparare`. La causa SDK è `Please return text or include your response in a tool call`, poi output retry esauriti. Questo è un turno senza output utilizzabile, diverso dal precedente caso con un aggiornamento narrativo completo. La promozione statica sopravvive in `suspected`.
2. **Investigazione 19, fine 22:40:06:** source reference non valida durante la conservazione delle reference, con seconda failure nel terminale. L'outcome conserva la reference su `package_import.php`, righe 1–290. Il guard che fallisce controlla esistenza del file e tipo della riga iniziale; non è dimostrato un ID inventato. Va verificato il ciclo di vita del workspace prima di attribuire la causa al modello. Il punto finale nel messaggio d'errore è aggiunto dal codice e non prova una reference con punteggiatura errata.
3. **Investigazioni 21–27, dalle 22:40:29 alle 22:40:55:** `Container target inesistente` preparando il toolbox. I sette task non arrivano al lavoro del modello. I Reader continuano fino alle 22:58. Gli artifact non dimostrano chi abbia rimosso il container o se si tratti di TTL: non lo attribuisco automaticamente alla scadenza.

I finding precedenti restano persistiti. `downstream_complete=true` significa che la coda è stata drenata, anche con task falliti; non che tutte le lead siano state investigate correttamente. `environment_state=valid` descrive il setup iniziale e non esclude la perdita successiva del target.

## Contabilità e limiti del confronto

Per la spesa nuova ho usato `report.telemetry.role_usage` e `economic_points_used`. `benchmark.cost.economic_points` contiene invece 2.616.489 EP: non coincide con i 3.245.113 EP dell'accounting della run. Anche `first_reader_lead_seconds` aggregato è 8.269 secondi, mentre gli eventi collocano la prima lead a circa 720 secondi dal primo Reader, o 1.225 secondi dalla creazione della run. Non uso questi contatori aggregati come cronometro o costo completo.

I costi provider nuovi sono incompleti (`provider_cost_usd=null`, `cost_accounting_complete=false`): la stima aggregata di circa 0,006 USD non rappresenta il costo della run. Nessun confronto affidabile in dollari viene dedotto da quel valore.

## Priorità suggerite, non implementate

**P0:** verificare perché workspace e target diventano indisponibili prima del drain; correggere/adjudicare gli abbinamenti del benchmark; gestire il turno Investigator privo di output come caso distinto dalla riparazione di un payload esistente; diagnosticare le emissioni Reader ripetute che falliscono sulla stessa reference.

**P1:** un confronto controllato Reader con stessa Recon, cap aggregato, reasoning e criteri di valutazione, seguito dall'adjudication dei risultati fuori catalogo. La run attuale giustifica queste verifiche, non un aumento dei cap Investigator.
