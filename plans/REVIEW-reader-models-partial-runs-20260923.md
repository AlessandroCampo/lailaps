# Reader: confronto run parziali, modelli e varianza

Analisi offline del 23 settembre 2026. Tre agenti GPT-6 Luna hanno estratto evidenze
dai log; aggregati, configurazioni e conclusioni principali verificati dal coordinatore.
Nessuna nuova run, chiamata provider dell'audit, prova HTTP o modifica al runtime.

## Giudizio

Esiste diversita' utile fra traiettorie e modelli, ma non e' possibile attribuire una
percentuale dei miss a varianza, capacita' del modello o harness da questi dati.
Il recupero benchmark nuovo piu' netto e' la SSRF keyId di ActivityPub con DeepSeek
v4.1. I problemi di checkpoint, memoria e scarto per privilegio applicativo restano
visibili con modelli diversi. Un modello diverso puo' recuperare piste, ma non ha
risolto questi difetti nel campione.

## Metodo e perimetro

Confronto con `yeswiki-reader-global-20260921-075801` (Reader + self-checkpoint) e,
per l'unione storica delle CVE, con `yeswiki-reader-global-20260920-225733` (Reviewer).
Percorsi parent: `storage/app/runs/yeswiki/reader-global/{run_id}/{run_id}-outcome.json`.
Percorsi child: `storage/app/runs/yeswiki/reader-area/{child_id}/{child_id}-outcome.json`
e il corrispondente `-logs.php`.

I parent incompleti non aggregano tutti i child. I fixture persistiti sotto
`storage/framework/lailaps-reader-global/{parent}/fixtures/*.json` forniscono la
mappatura autorevole child -> assignment; sono stati letti anche gli outcome child
parziali non ancora nel parent. Gli aggregati sotto sono lo snapshot acquisito
durante l'analisi, non un risultato finale per le run attive.

Sono confronti ReaderLead, non vulnerabilita' confermate. I match CVE qui richiedono
coerenza di root cause e sink; non basta l'overlap delle righe del matcher. Le letture
via run_workspace_command possono non comparire nelle source_observations: assenza
di un anchor registrato non prova assenza di lettura, per questo sono stati consultati
i transcript. Non e' stata validata la precisione di tutte le lead.

## Quali run sono effettivamente presenti

| Parent (suffisso) | Reader osservato | Stato dello snapshot | Lead figli | EP figli |
|---|---|---|---:|---:|
| 20260921-075801, baseline | DeepSeek v4-flash-0731 | 16 aree: 15 complete, 1 budget | 45 | 2.320.524 |
| 20260922-195503 | v4-flash-0731 aree 1-8; **v4.1-flash aree 9-16** | 13 complete, 2 fatal, 1 partial | 39 | 3.115.939 |
| 20260922-202622 | DeepSeek v4.1-flash | 9 aree avviate: 2 complete, 3 timeout esterni, 4 partial | 18 | 2.519.431 |
| 20260923-193458 | **xiaomi/mimo-v2.6-pro** | 13 fixture: 8 budget, 1 model_unavailable, 3 partial, 1 senza report operativo | 57 | 4.385.688 |
| 20260923-203135 | xiaomi/mimo-v2.6-pro | 5 aree avviate: 1 budget, 4 partial | 20 | 1.922.678 |

Esiste anche `20260922-202536`, tentativo senza risultati operativi, escluso.
Il modello chiamato dall'utente "minmax" e' Xiaomi MiMo, non MiniMax.
La run "stesso modello" non lo mantiene per tutte le aree: il confronto di varianza
piu' pulito e' limitato alle prime otto.

Tutti i parent sostanziali riusano la stessa Golden Recon
`01M309MYBKSARVPA2HWVTYT450`: il payload category_recon e' identico alla baseline,
come lo snapshot sorgente `7325759547611def210a731b103878bd696c419c`.
La strategia e' reader_checkpoint. Le nuove partenze sono rolling (si vede dall'avvio
dell'area 5 mentre altre delle prime quattro sono ancora attive), diversamente dalle
tranche rigide della baseline. Alcune run si sovrappongono temporalmente.

Non sono certificati identici il runtime storico e tutti i prompt. Anche il provider
varia: per esempio area1 baseline StreamLake, repeat Relace, v4.1 DeepInfra/Together,
MiMo Xiaomi/InferenceNet/OpenInference. Il confronto non e' un esperimento controllato
sulla sola capacita' del modello o sul puro sampling.

Il processo PHP del comando MiMo risulta attivo durante l'ispezione; esistono anche
processi Docker associati alla prima run MiMo. Non sono stati fermati. Il campo
`running` degli artifact del giorno precedente e' invece uno stato salvato rimasto
parziale, non prova di attivita' attuale.

## Confronto delle prime otto aree

| Serie | Lead | EP | Richieste Reader incl. checkpoint | CVE note sostenute |
|---|---:|---:|---:|---|
| Baseline checkpoint | 20 | 1.356.344 | 527 | 52771 |
| Repeat v4, aree 1-8 | 27 | 1.385.530 | 539 | 52771 |
| v4.1, aree 1-8 parziali | 17 | 2.469.113 | 839 | **52769** |
| MiMo prima run, aree 1-8 | 47 | 3.665.067 | 355 | 52771 |

Le esposizioni non sono equivalenti: MiMo include un failure nell'area 3; v4.1 ha
timeout e interruzioni. La tabella e' descrittiva, non una classifica normalizzata.

Il repeat v4 produce +35% output con circa +2,2% EP nelle stesse otto aree, ma nessuna
nuova CVE. E' un segnale di diversita' delle piste fuori catalogo, non una stima di
precisione o di root cause uniche. L'area rendering passa da 1 a 9 lead, mentre
l'area contenuti/revisioni passa da 3 a 0: la variabilita' delle traiettorie e' evidente.

MiMo produce 47 contro 20 lead ma spende circa 2,70 volte gli EP. I pesi registrati
Reader sono v4=(0,2133 uncached, 0,02133 cached, 0,2 output),
v4.1=(0,4; 0,008; 0,32), MiMo=(1; 0,02; 2). A parita' di cap economico MiMo dispone
quindi di meno lavoro in token/turni: non e' una prova pura di capacita'. Non confrontare
i soli lead count, e non concludere che MiMo sia piu' economico o abbia piu' recall.
Il costo provider v4.1 risulta incompleto: evitata una classifica in dollari.

## Guadagno CVE effettivo

- Repeat misto 195503: 52771, presente nell'area4 v4 e nuovamente nell'area12 v4.1.
- v4.1 202622: **52769 keyId SSRF**, area4 lead-3. Legge Signature header -> keyId
  -> HttpClient GET nell'inbox pubblico. Il contenuto coincide con il manifest.
  Le altre lead ActivityPub/Webfinger non sono automaticamente altri match CVE.
- Entrambe le MiMo: 52771 nelle rispettive aree4. Il tag recuperato dal DB non
  esclude una SQLi second-order: la creazione del tag resta un unknown appropriato
  per il Confirmer. Si conta la pista, non una conferma di exploit.

L'unione baseline checkpoint + nuove run passa da **1/12 a 2/12** (52771, 52769).
Includendo la prima run Reviewer gia' analizzata, l'unione storica passa da **3/12
a 4/12** (52763, 52771, 52774, 52769). Questi sono ritrovamenti osservati nel campione,
non recall finale delle run interrotte. L'aumento di 8,3 punti sul catalogo non e'
la percentuale dei miss attribuibile alla varianza.

Restano esclusi dal conteggio: leak ACL di RecentChanges scambiato per 52763 dal
vecchio matcher; SQLi listusers (MiMo area2) che e' un altro sink; SQLi deletepage.php
(MiMo area8), sorella ma distinta dall'API del manifest; XSS dei template fields/
che non coincide automaticamente con la CVE dei template inputs/.

## Problemi che attraversano i modelli

### 1. Il checkpoint viene scambiato per una lead acquisita: prova forte con v4.1

Child `yeswiki-reader-area-20260922-202629-3`, log:
- 681/687: riconosce area_leads vuoto, ma ipotizza che le lead narrative siano gia'
  state emesse e consumate;
- 923/966/1084: considera gia' acquisite session fixation e login CSRF;
- nel finale sceglie area_closed per non riemettere le stesse domande;
- outcome finale: **zero lead**, discovery_complete, 212.717 EP, circa 287k residui.

Non e' un cutoff e non dipende dalla validita' finale di quelle piste: e' un errore
sullo stato di acquisizione. Ripete il problema verificato nell'area6 della baseline.
Con MiMo la confusione fra checkpoint e ReaderLead e' visibile in
`193924-logs.php:220-234` e `201003-logs.php:514-540`; alcune lead vengono poi emesse.
Questo dimostra attrito e ragionamento sprecato, non autorizza a contare ogni esitazione
come una lead perduta. Lo stesso vale per il repeat v4 area5 e area6.

### 2. Admin applicativo usato per declassare SSTI

- Repeat v4 area6, child `20260922-202005`, log 236-241: legge SemanticTransformer,
  collega bn_sem_template a renderFromStringNoEscape e lo declassa per autore admin
  e focus XSS; nessuna lead SSTI nell'outcome.
- MiMo area6, child `20260923-200351`, log 818: legge il servizio via sed;
  879/915: template admin-defined, JSON output, pista giudicata meno chiara; 962
  conserva il ramo aperto, poi il child termina a budget senza lead SSTI.

Meccanismo simile osservato gia' con il Reviewer nella prima run. La conclusione
non e' "ogni template admin e' vulnerabile", ma che la precondizione admin non
risolve il confine tra autorita' applicativa e processo server. Questo sospetto
non si corregge semplicemente ripetendo lo stesso framing.

### 3. Sink letto per un'altra proprieta' o declassato

Repeat v4 area6 `202005-logs.php:53-55` riconosce precisamente GET time -> hidden
input raw, poi lo declassa per write access; 160/195 lo mantiene secondario. Nessuna
lead 52773. Il manifest prevede proprio una pagina leggibile/editabile: la condizione
non e' una confutazione. MiMo legge show.php integralmente nelle aree2 e6 senza
produrre la lead corrispondente entro il budget; non attribuiamo automaticamente
lo stesso ragionamento senza un passo testuale equivalente.

### 4. CalcField: conclusione ripetuta e memoria incoerente

v4 e MiMo leggono tokenizer, floatval e regexp e lo considerano math-only.
MiMo `193924-logs.php:22-95` lo esamina, ma a 1428-1445 lo tratta come non esaminato.
Episodi analoghi nelle run precedenti. La memoria perde il fatto della lettura mentre
conserva una conclusione globale.

Non e' una dimostrazione che la conclusione "niente code injection" sia falsa:
esistono controlli reali e il manifest include anche regex DoS. Il problema verificato
e' l'estensione della conclusione e la ripetizione del lavoro; l'oracle CalcField va
riesaminato prima di usarlo come prova decisiva di inferiorita' di un modello.

### 5. Copertura nominale invariata

Stessa Recon ampia, stessi incarichi: i percorsi numeric filters, input templates,
signature verification restano poco o diversamente esplorati. v4.1 raggiunge davvero
keyId, ma non emette il distinto bypass di verifica firma 52767. Non classificare
come errore del modello le aree mai avviate o troncate; classificare invece il
ragionamento su un sink quando il transcript lo mostra.

## Cosa migliora e cosa non dimostra capacita' superiore

v4.1 recupera keyId SSRF e produce piste utili su Webfinger e restore cross-page.
Questo prova che il catalogo non e' irraggiungibile dalla mappa corrente, non che
v4.1 abbia globalmente recall superiore. Il fallimento di emissione su autenticazione
rimane grave e completamente osservabile.

MiMo articola piu' varianti operative: tempTag multilinea nell'upload, gestione
cross-page degli allegati, gruppi/UserField, flussi commento con ACL sulla risorsa
sbagliata. Sono lead plausibili da preservare, non vulnerabilita' tutte validate.
Recupera anche unserialize amministrativo nell'area5: quindi non applica un veto
uniforme a ogni operazione privilegiata, mentre resta debole sulla SSTI.

Controesempio alla qualita' uniforme: primo MiMo area1 lead2 e area8 lead9 descrivono
api_delete/api_patch come possibili bypass, pur osservando una chiamata a isAuthorized()
senza i due argomenti richiesti. La chiamata e' in
`targets/yeswiki/tools/bazar/handlers/page/api_delete.php:10`, firma in
`targets/yeswiki/includes/services/ApiService.php:24`. L'ArgumentCountError interrompe
normalmente il percorso prima della cancellazione: ipotizzare "anche se chiamata
correttamente" non dimostra il comportamento del codice reale. La narrativa della
lead riconosce il problema, ma il titolo e la conclusione di bypass restano troppo forti.

## Interruzioni e infrastruttura

v4.1 aree1,2,4 hanno durata parent ~7200 s e technical_failure: timeout dell'assignment,
non semplice chiusura semantica o prova di incapacita'. I log includono ReadTimeout
di 240s (es. child area5 `212623-logs.php:2606`). Nel repeat misto aree9/14 terminano
fatal_error con conflitti Docker toolbox 409; non contare zero lead come misura
della capacita' di v4.1. MiMo area3 prima run termina model_unavailable con errori
di protocollo/tool; la seconda run produce invece sei lead di autenticazione nello
snapshot, mostrando che quel primo zero non misurava il recall del modello.

Questi fattori, i provider diversi, il cambio modello a meta' parent e le run
sovrapposte impediscono una classifica robusta di latenza o costo per capacita'.

## Decisione proposta

Aggiornamento di priorita': il piano `P0-reader-methodology-cross-model-20260923.md`
include nel P0 il confronto cross-model mirato e pone la revisione del metodo/prompt
prima di un eventuale Reviewer delle omissioni. Nessun esperimento avviato.

Il P0 sul contratto checkpoint/ledger e' rafforzato dai dati: ora abbiamo un caso
finale a zero lead con v4.1, non soltanto DeepSeek v4. Correggere anche il criterio
"admin/write access -> pista debole" e la conservazione dei residui prima di spendere
molto in global repetitions. Non introdurre nuovi agenti per correggere questo bug.

Per isolare il contributo del modello, usare dopo il P0 un piccolo insieme di
incarichi discriminanti identici, senza CVE o anchor rivelati al Reader, con reader
model esplicito per tutta la run, provider e runtime registrati, confronto a pari
EP e indicazione dei turni/token effettivamente ottenuti. Autenticazione, API, Bazar
e rendering sono candidati utili. Le run devono finire normalmente prima di farne
una classifica; failures vanno riportati separatamente.

Poi confrontare repliche identiche e modello diverso sul guadagno marginale di
root cause deduplicate, CVE note e piste plausibili fuori catalogo. Le ripetizioni
di questi esperimenti riusano Golden Recon: non misurano ancora la varianza di
Recon+Reader globali ripetuti. Il campione giustifica sperimentare la diversita',
ma non stimare percentuali causali o promettere che tre repetition risolvano i miss.
