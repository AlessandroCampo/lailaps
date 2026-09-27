# Diagnosi offline del recall Reader globale YesWiki

Analisi del 21 settembre 2026. Nessuna run agentica, chiamata provider o verifica HTTP
avviata. Nessuna modifica al runtime. Sorgente locale verificato sul commit
`7325759547611def210a731b103878bd696c419c`.

## Conclusione

Il 25% e' una baseline debole, ma non misura da solo il valore delle 80 lead.
Esistono sia problemi di copertura dei percorsi sia casi concreti di sink letti e
non inoltrati. Non emerge una causa unica attribuibile a budget, modello o Recon.
Le priorita' sono: correggere il criterio di scarto dei sospetti, evitare che il
checkpoint promuova supposizioni a mitigazioni provate, e riconciliare i percorsi
indipendenti prima di dichiarare chiuso un incarico ampio.

La sistematicita' statistica non e' dimostrata: questi sono meccanismi osservati,
alcuni ripetuti dentro la stessa run. Non sono repetition indipendenti identiche.

## Fonti e limiti

- Parent: `storage/app/runs/yeswiki/reader-global/yeswiki-reader-global-20260920-225733/yeswiki-reader-global-20260920-225733-outcome.json`.
- Tutti i 16 outcome figli referenziati da `report.assignments`, con diagnostici per
  manifest e `source_observations`; transcript approfonditi soprattutto per aree 4,
  5 e 6. Non e' stata eseguita una validazione completa delle 80 lead.
- Manifest locali: `agent/pentest-agent/benchmarks/targets/yeswiki/manifests/`.
- Confronto storico: `plans/REVIEW-recon-reader-yeswiki-20260920-194109.md`.
- Il runtime attuale e' un workspace con modifiche preesistenti: la lettura del
  codice spiega i meccanismi disponibili, non certifica da sola il prompt esatto
  inviato in ogni richiesta storica. I comportamenti attribuiti alla run sono
  supportati dai suoi transcript/outcome.

I manifest sono il ground truth operativo locale, non una nuova verifica
indipendente delle advisory o della sfruttabilita' di ciascun caso.

## Metriche da leggere correttamente

Il catalogo contiene 12 casi vulnerabili e un controllo negativo. I match positivi
aggregati sono 3/12: 52763, 52771, 52774. Il caso tagrss non-exploitable NON e' una
quarta vulnerabilita' trovata: viene emesso come suspected e il diagnostico registra
`disposition_correct=false`. Nel Reader-only e' una pista demandabile al Confirmer,
ma non un successo del controllo negativo.

Gli anchor risultano raggiunti per 8/12 casi vulnerabili. Il passaggio apparente
anchor -> lead e' 3/8, ma questo denominatore sovrastima i sink davvero letti:
il matcher considera anche un'intersezione parziale con un range ampio.

Il match automatico non e' sempre semanticamente preciso: area 4 lead-5, una CSRF
su deletePage, viene associata alla CVE SQLi 52771. Esiste pero' una vera lead SQLi
nell'area 12, lead-1: questa anomalia non cambia il 3/12 aggregato. Serve cautela
nel confrontare recall per assignment o cambiamenti piccoli dello score.

Tempo: il parent registra 11.791,131 secondi, circa 3h16m31s. La simulazione rolling
riportata dall'utente e' circa 2h15, non sotto due ore. Il vantaggio di concorrenza e
copertura e' concreto, ma questa run non dimostra ancora una scansione sotto 2h.
Cambiare in 8 o 16 slot richiede misure: latenze, contesa e rate limit possono variare.

## I nove miss

| Caso | Osservazione nella run | Diagnosi supportata |
|---|---|---|
| 52762, SSTI semantic template | Area 6 legge SemanticTransformer integralmente; riconosce template admin -> Twig e possibile SSTI, poi torna a cercare XSS | Sink riconosciuto e non inoltrato; privilegio e focus XSS usati come filtro improprio |
| 52778, CalcField | Area 5 legge il file almeno due volte, ricostruisce tokenizzazione/regexp e conclude math-only; Reviewer conserva la conclusione | Scarto esplicito dopo lettura. Non prova automaticamente che RCE sia fattibile; il manifest comprende anche regex DoS, non analizzato nel ragionamento citato |
| 52773, revision time XSS | show.php 1-86 letto nelle aree 2 e 6; area 8 acquisisce anche 46-50. Area 6 discute solo Format(body), non il campo time | Sink presente nel materiale letto, senza lead corrispondente; attenzione diretta a confermare un altro percorso |
| 52766, erase comments | Aree 4 e 14: solo search_source 49-61 e 55-60 | Non e' prova di sink di cancellazione letto: la chiamata delete e' a riga 82, perfino fuori dall'anchor 1-80 del manifest |
| 52775, reactions SQLi | Area 4 legge fino a 329 e poi 334; query vulnerabile nel ramo finale di deleteUserReaction, a riga 356 | Anchor sovrapposto, ma operazione decisiva non acquisita nelle letture registrate; focus su ACL/CSRF |
| 52770, numeric filters SQLi | Area 5 cerca SearchManager per ACL e legge 1000-1079; non 428-444 | Componente raggiunta, ramo di costruzione SQL non esplorato |
| 52767, signature bypass | HttpSignatureService compare solo in search_source 32-36 e 66-70 | Non letta la verifica 110-145; copertura ActivityPub insufficiente |
| 52769, keyId SSRF | Stesse osservazioni della riga precedente; non 80-110 | Un unico percorso federato trascurato spiega due CVE, non due errori indipendenti |
| 52772, field input templates XSS | Nessun reach dei due anchor inputs/text.twig e inputs/textarea.twig | Lettura concentrata su fields/ e presentation/templates; percorso input dei metadati form non coperto |

Le osservazioni sorgente non equivalgono sempre al contenuto effettivamente letto
con attenzione dal modello. Gli estratti search non dimostrano la revisione di
un'intera funzione; la registrazione di un file non dimostra una decisione di sicurezza.

## Evidenze decisive dai transcript

Tutti i riferimenti seguenti sono nella directory `storage/app/runs/yeswiki/reader-area/`.

### SSTI: filtro sui privilegi e sul tipo di finding

`yeswiki-reader-area-20260920-235630-2/...-logs.php:63` legge SemanticTransformer.
A riga 66 il Reader riconosce che il template e' `bn_sem_template` e dice che la
SSTI potrebbe non essere una vulnerabilita' se riservata agli amministratori.
A riga 210 la chiama esplicitamente possibile SSTI/RCE, ma conclude
"probably admin-only -> not a good XSS lead for low privilege".

Questa e' una mancata emissione ben documentata. Admin applicativo e autorita'
sul processo server non sono automaticamente equivalenti. La domanda da passare
al Confirmer era proprio quale autorita' aggiuntiva conceda il template e quale
sandbox esista. La Recon la rende indirettamente meno visibile intitolando
l'incarico "Rendering e template (XSS surface)".

### CalcField: una conclusione negativa diventa memoria stabile

`yeswiki-reader-area-20260920-235630/...-logs.php:21-63`: prima lettura, analisi del
tokenizer e della regexp ricorsiva, conclusione "mitigated" e admin "trusted actor".
A riga 163 il Reviewer riprende la mitigazione; a 484 e 515 la incorpora nei
checkpoint. A 1564 il Reader dice che CalcField non e' ancora stato esaminato,
lo rilegge a 1575 e lo esclude nuovamente a 1594. A 1647 il Reviewer consolida
la valutazione math-only.

Questo dimostra una rilettura e uno scarto ripetuto, non due esperimenti
indipendenti. La seconda lettura puo' essere influenzata dalla memoria precedente.
Non dedurre "c'e' eval quindi deve essere una lead": qui sono osservati controlli
reali. Il difetto metodologico e' estendere l'analisi dell'alfabeto ammesso a una
chiusura generale, senza distinguere RCE, correttezza del parser e costo della
regexp. Prima di considerare il mancato RCE un errore certo va riesaminato anche
l'oracle locale: il manifest raggruppa code execution e denial of service.

### show.php: leggere per confermare restringe l'attenzione

`yeswiki-reader-area-20260920-235630-2/...-logs.php:270`: lettura completa di show.php.
Il commento successivo conferma `Format(body, 'wakka')` per la lead markdown gia'
emessa. Il checkpoint a 319 conserva questa informazione. Il ramo di revisione
archiviata e il suo input hidden con `echo $time` a riga 49 non diventano una lead.

Non attribuisco lo stesso ragionamento alle altre aree senza transcript equivalente.
La presenza dello stesso sink in piu' letture e' comunque un indizio forte che
allargare solamente la mappa non risolva tutto.

### Bazar: la chiusura non riconcilia l'ampiezza reale

L'area 5 termina `discovery_complete` con circa 88k EP ancora disponibili.
Nel finale il Reader tenta una ricerca di SQL raw; il Reviewer riconosce che il
comando rg precedente e' fallito, ma poi dichiara `close_area` senza recuperare
quel controllo. La chiusura riassume soprattutto i percorsi gia' percorsi, pur
lasciando fuori numeric filters, signature verification e input templates.

Il budget residuo non e' un obbligo di spenderlo: mostra pero' che qui l'aumento
del cap non correggerebbe direttamente la decisione di completezza.

## Responsabilita' di harness, strategia e modello

1. **Recon: copertura nominale troppo grossolana in Bazar.** Un incarico copre
   controllers/fields/services/handlers, ma il focus e' CRUD e validazione campi.
   ActivityPub non emerge come comportamento autonomo; input templates non e'
   esplicito. Un path di directory ampio non assegna automaticamente tutte le
   responsabilita' che contiene. Conservare gli incarichi funzionali e separare
   soltanto i comportamenti indipendenti osservati, senza una matrice CWE x file.
2. **Reader: soglia di inoltro incoerente.** Alcune piste speculative vengono
   accettate, mentre SSTI viene fermata per privilegio e CalcField viene trattato
   come problema risolto. Non e' evidenza di un Reader uniformemente troppo prudente.
3. **Reviewer: conferma il focus corrente invece di controllare le omissioni.**
   Negli esempi consolida mitigazioni e ordina altre letture su piste gia' emesse.
   Il prompt corrente gia' vieta la validazione semantica del sink e delega al
   Confirmer l'exploitability: aggiungere un altro lungo avvertimento non basta.
4. **Memoria: selezione delle informazioni, non solo dimensione del contesto.**
   CalcField viene dimenticato come lettura e ricordato come mitigazione. La memoria
   dovrebbe preservare il perimetro della conclusione e il dubbio residuo.
5. **Framing dei prompt.** Il codice attuale sostituisce READER_PROMPT con
   READER_ASSIGNMENTS_PROMPT nell'esperimento (`_role_system_prompt`). Il prompt
   ordinario dice esplicitamente che privilegi/autenticazione non eliminano una
   pista; quello assignments non conserva quella frase. E' un punto locale da
   armonizzare, con verifica del prompt effettivo nel prossimo confronto.
6. **Contesto del Reviewer.** Il codice gli passa una history Reader portabile.
   Nei log ragiona spesso in prima persona come se continuasse l'indagine e parla
   di fare altre letture pur essendo tool-free. E' compatibile con ancoraggio alla
   traiettoria, ma non dimostra che cambiare il formato della history migliori il
   recall. Non proporrei un rework del contesto come primo intervento.

Il precedente caso EraseSpamedComments, documentato nella review della run 194109,
mostrava davvero l'assunzione "presumably admin-only" dopo la lettura operativa.
Nella nuova run manca invece la lettura del ramo decisivo: non vanno confusi.

## Valore delle lead fuori benchmark

Le 80 lead sono output acquisiti, non 80 root cause distinte e non 80 vulnerabilita'
confermate. Il campione letto mostra valore concreto, oltre ai match CVE:

- Area 5 lead-7: asimmetria CREATE/UPDATE su write_acl. Il codice di
  EntryManager::assignRestrictedFields conferma che con previousData vuoto il valore
  POST non vuoto non viene sostituito. Buona pista statica; servono form e attore
  appropriati per confermarne l'impatto.
- Area 8 lead-1: pointimage usa un tag POST e SavePage(..., true), con bypass ACL.
  Operazione e influenza sono concrete; restano da verificare gating delle azioni
  e raggiungibilita' del flusso nella configurazione target.
- Area 12 lead-3 e area 4 lead-12: isAuthorized ammette bearer valido in alternativa
  all'ACL della route; CiController scrive configurazione senza guard admin locale.
  Pista concreta condizionata all'esistenza di un bearer non-admin. Non contare due
  volte lo stesso problema perche' trovato da due Reader.
- Area 1 lead-3: resize di immagini con filename nel percorso e derivazione del
  percorso di destinazione. Pista plausibile ma fortemente condizionata alla
  semantica del router e ai vincoli sul file sorgente; non chiamarla arbitrary
  executable file write gia' dimostrata.

Sono presenti anche difetti nella qualita' dell'insieme:

- Area 3 lead-1 e lead-3 entrambe session fixation; ulteriori duplicati cross-area
  su CSRF commenti, configurazione/archivi e SQLi deletePage.
- Area 5 lead-5 sostiene assenza di ACL entry-level, ma la lettura successiva
  riconosce Guard::checkAcls su cache miss. La lead acquisita non diventa per questo
  automaticamente falsa in ogni variante, ma la sua premessa principale e' superata.
- Il controllo negativo tagrss viene leadato; i titoli XSS raw-HTML/by-design
  richiedono valutazione della policy applicativa e dell'autorita' concessa agli autori.

Non e' corretto stimare una precisione percentuale da questo campione scelto per
diagnosi. Sono gia' sufficienti esempi per escludere sia "tutte inutili" sia
"80 lead = alta precisione".

## P0 proposto, non implementato

1. **Misura offline affidabile.** Conservare questa matrice e distinguere incontro
   del file, lettura dell'operazione e lead sulla stessa root cause. Riesaminare i
   match ambigui e gli anchor EraseSpamedComments/ReactionManager. Il benchmark
   rimane separato dai prompt operativi: nessun elenco di CVE da suggerire ai Reader.
2. **Correzione locale del contratto Reader/Reviewer.** Privilegio come condizione,
   non scarto; filtro efficace soltanto per il flusso e la proprieta' effettivamente
   osservati. Checkpoint narrativo che separi controllo osservato, inferenza e dubbio.
   Nessun nuovo DTO o agente. Quando una pista e' gia' sufficiente, inoltrarla;
   non usare la tranche successiva per completare la validazione di una lead acquisita.
3. **Chiusura rispetto all'incarico intero.** Reviewer confronta la domanda assegnata
   con percorsi effettivamente esaminati e omissioni emerse, non solo l'ultimo
   next_focus. Recuperare una ricerca fallita quando lascia una domanda concreta;
   non aggiungere conteggi deterministici di tool call o quote di lettura.
4. **Recon funzionale piu' precisa dove serve.** Distinguere, quando osservati,
   federazione/verifica firme, query/filtering e definizione/rendering dei form dal
   CRUD ordinario. Rimuovere etichette che restringono impropriamente il tipo di
   vulnerabilita' ricercata. Non aumentare indiscriminatamente il numero di aree.

Per isolare gli effetti: prima confronto con Golden Recon congelata modificando
soltanto Reader/Reviewer; poi confronto della mappa Recon. Mantenere modello,
provider osservato, cap, snapshot e configurazione confrontabili. Non includere
simultaneamente upgrade del modello, aumento budget e repetitions nello stesso A/B.

L'esperimento utile deve migliorare il recall delle root cause note senza ottenere
il risultato solo gonfiando piste deboli. Riportare: CVE uniche, miss con sink letto,
nuove root cause plausibili fuori benchmark, duplicati, piste contraddette, EP totali
e tempo. Un passaggio 3 -> 4 CVE da solo non dimostra un miglioramento stabile.

## P1+: modello, repetitions e scheduling

- Il vantaggio degli altri modelli indicato dall'utente e' un'ipotesi empirica da
  preservare; questa analisi non ha confrontato quei modelli. Un upgrade puo'
  aiutare anche se gli errori sono favoriti dalla harness: le cause non sono esclusive.
- Per misurare varianza Reader: stessa Golden Recon, stesso modello e impostazioni,
  repetition indipendenti. Per misurare il sistema finale: ripetere Recon+Reader e
  confrontare l'unione deduplicata. Sono due domande diverse.
- Usare recall per run e marginale della seconda/terza repetition, stabilita' dei
  singoli miss e crescita delle root cause fuori benchmark. Non assumere che due
  repetition raddoppino il recall o che i miss siano indipendenti. Le aree diverse
  della run attuale non sono repetition identiche.
- Confrontare poi repetition, modello migliore e budget maggiore a spesa totale
  comparabile. Le repetitions sono promettenti se recuperano sink/percorsi diversi;
  sono poco efficaci sui medesimi falsi assunti ripetuti.
- Rolling scheduling e 8/16 slot riguardano il wall time. Non correggono la decisione
  locale di scartare un sink. Il cambio Reviewer -> ReaderCheckpoint gia' proposto
  altrove e' soprattutto un esperimento economico: non dimostra di risolvere questi miss.

Nessuno di questi esperimenti a pagamento e' stato eseguito in questo task.
