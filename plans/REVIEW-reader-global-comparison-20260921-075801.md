# Confronto Reader globale YesWiki: 225733 vs 075801

Analisi offline del 21 settembre 2026. Nessun loop agentico, HTTP o chiamata provider.
Fonti: outcome parent e 16 outcome figli di ciascuna run; transcript mirati delle
aree 5, 6, 12 e 14 della seconda run. Non e' una validazione completa delle 125 lead.

Parent sotto `storage/app/runs/yeswiki/reader-global/`:
- `yeswiki-reader-global-20260920-225733` (A).
- `yeswiki-reader-global-20260921-075801` (B).

## Risultato

B e' un miglioramento economico e di tempo, con regressione del recall benchmark.
Non e' una repetition identica: cambia la strategia di checkpoint. Una sola coppia
non separa causalmente l'effetto della strategia dalla varianza del modello.

| Misura | A | B |
|---|---:|---:|
| Wall time parent | 11.791,131 s / 3h16m31s | 6.780,17 s / 1h53m00s |
| Economic points | 4.751.194 | 2.320.524 |
| Lead acquisite | 80 | 45 |
| Lead / 100k EP | 1,684 | 1,939 |
| Assignment complete | 4/16 | 15/16 |
| Recall automatico casi vulnerabili | 3/12 = 25% | 2/12 = 16,7% |
| Recall sostenuto dalle lead abbinate, dopo lettura | 3/12 = 25% | 1/12 = 8,3% |
| CVE con anchor overlap | 8/12 | 7/12 |
| File unici nelle source_observations | 596 | 501 |
| EP Reader | 2.915.708 | 2.232.599 |
| EP Reviewer | 1.835.486 | 87.925 |
| Richieste modello totali | 1.850 | 962 |
| Costo provider registrato, somma figli | $2,6732 | $0,6497 |

Wall time -42,5%; EP -51,2%; lead -43,8%; lead/EP +15,2%.
I costi provider sono contabilizzazione degli artifact, non verifica della fattura.
I file osservati comprendono estratti di ricerca: non misurano funzioni revisionate.
La tabella finale dell'utente contiene 14 lead nelle sole aree 13-16; il totale e' 45.

B ha 15 discovery_complete e un economic_budget_exhausted (area 5); nessun
semantic_stop. Complete certifica una decisione del lifecycle, non assenza di miss.

## Confrontabilita'

Golden Recon identica `01M309MYBKSARVPA2HWVTYT450`, payload category_recon identico,
snapshot `7325759547611def210a731b103878bd696c419c`, concorrenza 4 e cap 500k/area.
Reader osservato in entrambe: deepseek/deepseek-v4-flash-0731.
Reviewer osservato: z-ai/glm-5.3-flash.

B registra reader_checkpoint_strategy=reader_checkpoint e 79 self-checkpoint.
A usa le exploration review del Reviewer. Il Reviewer rimane in B per altri
compiti, inclusa novelty: non e' stato eliminato. I punti dei self-checkpoint
ricadono sul Reader, quindi confrontare solo il costo Reviewer e' fuorviante.
Il risparmio totale rimane sostanziale. Non sono stati certificati come identici
tutti i prompt, il codice runtime storico e le route provider.

## Correzione del recall automatico

B associa CVE-2026-52763 (SQL injection tramite period/minDate) all'area 2 lead-3:
"RecentChanges rivela tag, autore e timestamp di pagine private".
Description, suspicious_operation, initial_evidence e unknowns descrivono
esclusivamente assenza di ACL, non SQL injection. Il match e' spurio.

Resta una corrispondenza sostenuta dal contenuto: area 4 lead-1, SQLi second-order
deletePage, CVE-2026-52771. Nelle 45 lead esaminate per titolo e nelle ricerche
mirate della narrativa non emerge un recupero delle CVE perdute sotto altro titolo.
Il valore 1/12 e' una correzione offline dei match, non un nuovo evaluator eseguito
ne' una conferma dinamica della lead.

A conservava invece vere lead su 52763, 52771 e 52774. L'unione dei match
semanticamente sostenuti A+B resta 3/12: B non aggiunge una nuova CVE nota.
La sparizione della lead sul controllo negativo tagrss non dimostra una corretta
disposition not_exploitable: assenza di emissione e confutazione sono cose diverse.

## Conferme e regressioni nella ricerca

- SSTI 52762: in A SemanticTransformer letto e declassato; in B non raggiunto.
- Widget XSS 52774: in A lead reale; in B solo search_source 25-29 nell'area 2,
  2-6 e 11-15 nell'area 14. Anchor overlap non equivale a riesame del sink.
- Revision time XSS 52773: ancora anchor raggiunto e nessuna lead. Resta un miss
  ricorrente, pur senza attribuire automaticamente lo stesso ragionamento a ogni area.
- CalcField 52778: area 5 legge integralmente, conclude regex/floatval mitigano
  l'eval, poi verso fine budget tratta di nuovo CalcField come ramo da esplorare.
  Conferma scarto e perdita di memoria delle letture; non dimostra da sola un RCE.
- Reaction SQLi 52775: B acquisisce nell'area 11 search_source 353-359, che include
  questa volta la query vulnerabile a 356. Nessuna lead: qui il sink e' nel materiale
  acquisito, mentre in A la lettura dell'area 4 si fermava prima della query.
- ActivityPub 52767: nuovi estratti 128-132 (area 5) e 119-123 (area 16) fanno salire
  il diagnostico ad anchor_reached, ma non dimostrano lettura della verifica completa.
- Numeric filters e input templates restano senza copertura decisiva.

La Recon identica non ha recuperato le omissioni. B chiude piu' incarichi con
meno approfondimento complessivo; questo puo' essere utile, ma non giustifica
l'equivalenza complete -> copertura adeguata.

## Difetto concreto del self-checkpoint

Transcript: `storage/app/runs/yeswiki/reader-area/yeswiki-reader-area-20260921-081338-2/yeswiki-reader-area-20260921-081338-2-logs.php`.

- Righe 237-253: durante il checkpoint tool-free il Reader vorrebbe emettere una
  ReaderLead, vede solo continue/area_closed e decide di descriverla nel summary.
- Righe 588-590: riconosce che la pista XSS riflesso via fallback $_REQUEST non e'
  stata emessa come ReaderLead, ma conclude "already acquired" perche' una nota
  precedente la chiama lead rafforzata.
- Righe 598-603: chiude. Nell'outcome dell'area esiste solo lead-1 su LinkField.

La pista non acquisita non e' automaticamente valida, ma il difetto di handoff e'
indipendente dalla sua validita': la decisione usa un falso stato di emissione.
L'area 6 termina con 79k EP consumati e circa 421k residui: aumentare il cap non
corregge questo episodio. Non proporre di parsare il summary per creare lead:
occorre tornare al turno operativo con una direttiva di emissione esplicita.

Area 5 mostra confusione analoga intorno alle righe 2494-2512 del suo transcript
`yeswiki-reader-area-20260921-081338-logs.php`: il Reader si domanda se emettere la
pista unserialize nel checkpoint_summary. La run finisce con sei lead, nessuna
sull'unserialize. Il passaggio checkpoint -> emissione va reso inequivocabile.

## Valore fuori benchmark

La seconda run aggiunge piste potenzialmente utili: CSV formula injection,
aceditor textarea breakout, toc raw output e percorsi JSONP/GET mutanti.
Richiedono triage e deduplica: la loro presenza non compensa numericamente i miss
CVE e non va ignorata solo perche' fuori catalogo.

Un progresso qualitativo concreto e' l'area 5 che considera la condizione
htmlPurifierActivated: HtmlPurifierService.php:37/70 puo' disattivare la pulizia.
La configurazione effettiva del target e' da distinguere dal default dichiarato
nella lead. L'area 6 della stessa run continua invece a trattare la pulizia al
salvataggio come mitigazione dei template. Questo segnala conclusioni locali
incoerenti tra Reader isolati, non prova da solo che serva un nuovo sistema di memoria.

Non tutte le 35 lead in meno sono regressioni: area 3 ora raggruppa CSRF e session
fixation in un solo output. Esistono ancora duplicati cross-area, ad esempio GET
ajaxedit nelle aree 8 e 16. La produttivita' per numero di lead non misura root cause
distinte ne' precisione. Non e' stato assegnato un tasso di validita' alle 45 lead.

## P0 e P1 proposti, non implementati

P0:
1. Correggere/riaggiudicare il match RecentChanges prima di usare lo score per
   confrontare strategie. Uguale file/sink SQL non significa uguale root cause.
2. Chiarire il confine self-checkpoint: il summary non emette lead; una pista
   sufficiente ancora non acquisita richiede continue con next_step di emissione
   al ritorno operativo, non ulteriori verifiche ne' area_closed.
3. Conservare stato di acquisizione autorevole separato dalla narrativa e verificare
   con test offline il passaggio checkpoint -> turno Reader -> acquisizione.
   Il modello continua a interpretare la semantica; non introdurre parser di
   vulnerabilita' nel testo libero o euristiche di stagnazione.
4. Confermare l'ampiezza dell'incarico al momento della chiusura, senza trasformare
   una mitigazione parziale in esclusione dell'intero componente.

P1:
- Repliche controllate A/B per distinguere effetto checkpoint e varianza. Questa
  coppia non dimostra che il Reviewer migliore peggiori/migliori causalmente il recall.
- Ripetizioni Recon+Reader e modello migliore dopo stabilizzazione P0. L'unione
  attuale non recupera nuove CVE, quindi non e' ancora evidenza a favore del gain
  benchmark delle repetitions; resta diversita' di piste fuori catalogo.
- Non scartare reader_checkpoint: il vantaggio economico e' reale. Non promuoverlo
  pero' come equivalente qualitativo al Reviewer sulla base dei soli complete.
