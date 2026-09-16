# Handoff P0 — analisi statica affidabile e copertura security per stack

Data: 15 settembre 2026. Stato: pronto per implementazione; nessuna modifica al
runtime applicata da questo task. Documento indipendente dal piano budget/history.

## Obiettivo

Ripristinare il contributo Semgrep alla discovery e dimostrare che le regole security
previste per lo stack vengono caricate, eseguite e consegnano i risultati alla Recon
e al Reader. Evitare che un errore del sensore venga rappresentato come assenza di
superfici interessanti.

“Completo per stack” deve significare **copertura dichiarata e verificata delle
famiglie supportate**, non garanzia di trovare tutte le vulnerabilità. Parser del
linguaggio, disponibilità di regole per framework e ricostruzione dei flussi sono
capacità distinte. Una falla nei permessi fra fork e repository privato può restare
invisibile alle regole e richiedere indagine del Reader.

Semgrep resta advisory: un sink censito è un punto da leggere, non un finding
confermato. Gli errori di ragionamento Reader/Reviewer e il budget sono trattati in
`global-final-analysis-20260915.md` e `p0-continuity-budget-worker-20260915.md`.

## 1. Causa degli errori, ricostruita

### A. Corpus modificato senza aggiornare il manifest

File coinvolti sotto `agent/pentest-agent/`:

- `rules/community/default.yml`
- `rules/ruleset.lock.json`
- `scripts/update_semgrep_rules.py`
- `src/pentest_agent/surface_context.py`, `_ruleset_manifest` / `_semgrep_configs`

| Revisione | SHA256 del corpus | Manifest coerente |
|---|---|---|
| `b04db39` | `af036071eddae65eb2014447ab23c56320ff52a5d591244eea316a280a21b264` | sì |
| `5d51f54` e checkout letto | `9eb2efc8277f8f2c4ee8e2decfe086b834cfa5e4bbefae3266cb6e30df30d4df` | no: contiene ancora il precedente |

Il diff fra le due revisioni modifica una regola di esempio Slack webhook intorno
alla riga 1646: aggiunge un commento e sostituisce i punti dell'URL di esclusione
con `[.]`. Il manifest non viene aggiornato. L'intento dichiarato dal commento è
evitare di distribuire un URL dall'aspetto di credenziale.

Questa modifica cambia i byte e quindi il checksum. Il controllo di integrità
funziona correttamente: rifiuta il corpus e passa al fallback locale. La causa è
la coppia snapshot/manifest incoerente già in Git, non Go, non il target e non la
semplice conversione LF/CRLF. Non è necessario fare ipotesi sul provider.

Attenzione nella correzione: il commento parla di regex, ma il campo resta
`pattern-not`; verificare la semantica effettiva con l'engine fissato. Non limitarsi
a cambiare l'hash di un corpus non verificato. La patch necessaria e il nuovo hash
devono essere consegnati insieme.

### B. Assunzione errata sugli identificatori restituiti da Semgrep

`_is_security_rule` accetta il prefisso `lailaps.` oppure metadata security/CWE/OWASP.
Il comando reale non disabilita la riscrittura degli ID: per un file di regole
sotto `/app/rules/`, Semgrep può restituire `app.rules.lailaps.surface.sql`.
Le regole locali SQL, filesystem e diverse altre dichiarano soltanto `family`:
non superano nessuna delle condizioni e i risultati vengono eliminati.

È un comportamento documentato della CLI: gli ID locali vengono prefissati con
il percorso del file di regole. [Documentazione ufficiale sugli ID locali](https://docs.semgrep.dev/running-rules).

Prova già eseguita senza rete, sul solo file Go sintetico contenente chiamate
`db.Query(...)` e `os.ReadFile(...)`, nell'immagine locale dell'agente:

| Comando | Risultati Semgrep | Conservati dal filtro |
|---|---:|---:|
| Opzioni correnti | 2, con prefisso `app.rules.` | 0 |
| Con `--no-rewrite-rule-ids` | 2, con prefisso `lailaps.` | 2 |

Entrambe le prove terminano con exit code 0 e zero errori. Il flag è presente
anche nell'help dell'engine installato. **Questa è la soluzione minima preferita**,
da verificare anche sul corpus community e sugli ID duplicati prima di applicarla.
Non aggiungere un parser di prefissi o più fallback se il flag nativo basta.
[Riferimento CLI](https://docs.semgrep.dev/cli-reference).

### C. I controlli esistenti non coprono il contratto reale

- I mock in `tests/test_surface_context.py` usano ID `lailaps.*` già puliti.
- Il test con Semgrep reale controlla soltanto regole dynamic-code e risultati
  grezzi, senza attraversare l'intero filtro e la proiezione al Reader.
- Le regole dynamic-code dichiarano `category: security`: sopravvivono anche
  quando il prefisso è inatteso. Questo è coerente con i pochi segnali YesWiki.
- `status=ready` deriva dal solo exit code; il corpus può essere assente o rifiutato.
- `raw_signal_count` è calcolato dopo il filtro: non è davvero un conteggio grezzo.
- Gli errori della scansione sono ridotti al numero: nei dump Gitea ci sono due
  errori, ma il loro dettaglio non consente di attribuirgli l'assenza dei segnali.

Il problema è quindi integrità + contratto CLI + test incompleti + stato troppo
ottimista. Non è sufficiente aggiornare il ruleset senza correggere questo percorso.

## 2. Quali regole usare

### Base P0: riutilizzare il corpus già distribuito

Il corpus locale letto contiene 1.074 regole, di cui 1.044 classificate security
con il criterio metadata attuale. Conteggi dichiarati per alcune lingue:

| Linguaggio dichiarato | Regole security nel corpus |
|---|---:|
| Go | 84 |
| PHP | 39 |
| Python | 219 |
| Java | 118 |
| JavaScript | 152 |
| TypeScript | 150 |
| Ruby | 74 |

I conteggi sono ottenuti leggendo il YAML, non eseguendo tutte le regole. Le regole
multilingua compaiono in più righe; gli alias vanno normalizzati nella matrice
finale. Non trattarli come regole compatibili, eseguite o efficaci già dimostrate.
Nel corpus 28 regole dichiarano `options.interfile`: verificarne l'applicabilità
all'engine disponibile, senza contabilizzare come copertura effettiva capacità non attive.

Partire dal ripristino di questa base, mantenere le regole locali di censimento
dei sink e integrare **solo le lacune concrete** con regole security del Registry
ufficiale per lingua/framework. Il Registry organizza pacchetti proprio per questi
criteri e consente più configurazioni nella stessa scansione.
[Uso dei ruleset](https://docs.semgrep.dev/running-rules).

Non usare il nome `p/default` come attestazione di completezza. Non scaricare tutti
i pacchetti indiscriminatamente e non introdurre un selettore LLM dei ruleset.
Conservare un corpus multilingua fissato: Semgrep applica le regole alle lingue
pertinenti; verificare questo comportamento nei test. I pacchetti aggiuntivi vengono
risolti durante aggiornamento/build, mai scaricati durante l'audit.

### Matrice di copertura richiesta

Produrre una tabella breve nel README dell'agente o vicino al manifest esistente:

`linguaggio/framework → famiglie → regole effettive → engine → fixture verificata → lacune`

Verificare almeno Go e PHP, i due stack delle run, e i linguaggi web comuni già
presenti nel corpus: JS/TS, Python, Java, Ruby, C# e Kotlin. Per gli altri linguaggi
non dichiarare supporto verificato senza una prova nell'engine fissato.

Famiglie da controllare dove pertinenti:

- query e SQL injection;
- esecuzione comandi e codice dinamico;
- filesystem, path traversal, upload ed estrazione archivi;
- richieste HTTP in uscita, SSRF, redirect e validazione TLS;
- template/HTML, XSS e template injection;
- deserializzazione e parser pericolosi;
- password hashing, chiavi, firme, token e impostazioni cookie;
- configurazioni framework/auth/CSRF per cui esistono regole utilizzabili.

Per ogni famiglia distinguere regole **di vulnerabilità** da regole **di superficie**.
Una chiamata SQL sicura può essere correttamente censita dalla seconda: non deve
fallire un test negativo che pretende zero sink. I controlli negativi devono
verificare che non venga segnalata una vulnerabilità dalla regola specifica.

Lo stack comprende backend, frontend, template e configurazione: non fermarsi a
“Gitea = Go” o “YesWiki = PHP”. Per template non supportati o framework custom
esplicitare il vuoto e lasciare la lettura al Reader; non inferire coverage dalla
sola estensione. Per ogni lacuna, scegliere: regola upstream già compatibile;
piccola regola locale generalista se necessaria; altrimenti limite documentato.
Niente regole con nomi di progetto/CVE o path del benchmark.

Le capacità commerciali e quelle CE non sono intercambiabili; leggere la tabella
dell'edizione effettiva e verificarla con il binario fissato. Il repository delle
regole descrive anche funzionalità Pro/cross-file che non vanno attribuite al
solo corpus gratuito. [Repository ufficiale delle regole](https://github.com/semgrep/semgrep-rules),
[glossario delle analisi](https://semgrep.dev/docs/writing-rules/glossary).

## 3. Implementazione P0, in ordine

### A. Corpus riproducibile

1. Correggere/verificare l'esclusione modificata nella regola Slack, preservando
   l'intento della modifica; validare la regola e aggiornare il digest dei byte finali.
2. Riusare l'updater: scaricare una versione da fonte definita, preparare e validare
   i file prima di sostituire quelli buoni, generare il manifest dal file finale.
   Evitare che una modifica locale successiva lasci l'hash del download originario.
3. Registrare fonte, data, engine e digest effettivo; per una patch locale mantenere
   la provenienza e descrivere la modifica, senza un nuovo sistema di patch generico.
4. Aggiungere una verifica rapida di integrità dell'immagine dopo `COPY . .`.
   Una build con corpus richiesto incoerente non deve essere distribuibile.
   Tenere le prove più ampie di compatibilità nell'aggiornamento/CI mirata.

Nessun aggiornamento automatico del motore nel P0 se la versione fissata basta;
se una regola richiede un'altra versione, escluderla esplicitamente o motivare
l'aggiornamento necessario. Non dichiararla caricata per il solo parsing YAML.

### B. Consegna corretta dei segnali

1. Usare `--no-rewrite-rule-ids` nel percorso unico `_semgrep_signals`.
2. Verificare l'unicità degli ID dei pacchetti selezionati: deduplicare regole
   identiche, segnalare stesso ID con definizioni diverse durante l'aggiornamento.
3. Conservare filtro security e famiglie esistenti. Non accettare qualsiasi regola
   community come security e non dipendere da una substring arbitraria nell'ID.
4. Verificare che normalizzazione del path, filtro, ranking e paginazione conservino
   i risultati attesi. Il top-N del briefing non deve cancellare l'inventario completo.
5. Invalidare la cache precedente quando cambia questa semantica: riusare versione
   cache/fingerprint esistenti; niente secondo cache manager. Ricalcolare i segnali
   per nuove run, senza riscrivere i risultati storici.

### C. Stato affidabile con pochi dati utili

Riusare `sensor_status`, gli artifact e le limitation già presenti. Servono:

- engine e corpus effettivamente caricati, incluso fallback o pacchetti esclusi;
- numero di risultati prima del filtro e numero conservato;
- conteggi dei file analizzati/esclusi e delle regole applicabili quando il JSON
  dell'engine li fornisce; se assenti, segnalarli come non disponibili;
- errori essenziali con tipo, regola/file e messaggio breve, senza sorgenti o segreti.

`ready` richiede esecuzione riuscita con corpus previsto; checksum errato, pacchetto
incompatibile, timeout o parsing parziale devono produrre uno stato degradato/incompleto
coerente anche nel payload di `list_surface_signals`. Non chiamare `no_matches`
una scansione non effettuata o inutilizzabile. Non usare “zero findings” come errore:
una scansione sana può avere zero match.

Build/CI impediscono di distribuire l'errore noto. A runtime conservare il fail-open
dell'audit: Reader/Recon proseguono con la limitazione visibile e nessuna inferenza
di sicurezza dall'assenza di segnali. Nessun nuovo gate LLM, nuovo ruolo o redesign UI.

### D. Copertura per stack verificata

Compilare la matrice dal corpus effettivo, individuare i vuoti e aggiungere soltanto
i pacchetti/regole necessari. Usare un'unica lista di fonti nell'updater quando
servono più pacchetti, senza profili configurabili per ogni audit.

Ordine: Go/PHP per correggere il caso reale, poi smoke test dei linguaggi già
dichiarati nella matrice. I test devono verificare anche le regole community:
superare soltanto le regex locali non dimostra il ripristino del corpus.

## 4. Verifiche di accettazione

Tutte offline, senza agent loop, API LLM o exploit HTTP. Estendere i test esistenti
e poche fixture piccole; non costruire un nuovo harness.

1. **Integrità:** il corpus buono passa; alterare un byte fa fallire il controllo
   build e produce fallback dichiarato nel test del runtime.
2. **Regola modificata:** esempio escluso e esempio positivo restano distinguibili
   dopo la correzione della regola Slack, usando valori sintetici.
3. **CLI reale → Lailaps:** fixture Go con le due chiamate già usate; scan dal CWD
   e con i path del container → entrambi i risultati conservati, nei `top_files`,
   disponibili via `list_surface_signals`, osservabili come path dalla Recon.
4. **Corpus community:** per Go e PHP una regola security reale con caso positivo
   e negativo; il segnale deve attraversare `_semgrep_signals` e la proiezione.
5. **Altri stack dichiarati:** almeno uno smoke test community per linguaggio,
   eseguito con il parser reale. Per le nuove regole locali aggiungere casi
   positivi/negativi per il comportamento specifico, senza replicare mille test upstream.
6. **Degrado:** errori/timeout, linguaggio non coperto e pacchetti esclusi non
   diventano un inventario sano e vuoto. Testare anche i due errori Gitea quando
   il risultato grezzo sia nuovamente disponibile.
7. **Cache e ranking:** vecchia cache invalidata; la nuova restituisce lo stesso
   inventario; risultati fuori top-N recuperabili tramite paginazione.

Semgrep supporta fixture annotate `ruleid` e `ok`: riutilizzare il meccanismo
nativo per le regole locali. [Test ufficiali delle regole](https://docs.semgrep.dev/writing-rules/testing-rules).

Una successiva scansione statica dei soli snapshot YesWiki/Gitea, con timeout
esplicito, può confrontare risultati grezzi e consegnati senza lanciare agenti.
Non è necessaria una global a pagamento per verificare questa integrazione.
Non pretendere che Semgrep trovi tutte le CVE dei manifest: molte verificano
autorizzazioni o logica applicativa che il sensore non modella.

### Criterio di completamento

P0 è concluso quando corpus e manifest sono coerenti nell'immagine, i risultati
reali attraversano l'intero percorso, gli errori sono visibili e la matrice distingue
copertura verificata da lacune. Il solo `exit=0`, il numero di regole o un aumento
dei finding non soddisfano il criterio.

## 5. File e documentazione

Intervenire principalmente su:

- `agent/pentest-agent/src/pentest_agent/surface_context.py`
- `agent/pentest-agent/rules/security-surfaces.yml`, `rules/community/default.yml`,
  `rules/ruleset.lock.json`
- `agent/pentest-agent/scripts/update_semgrep_rules.py`
- `agent/pentest-agent/tests/test_surface_context.py` e fixture minime associate
- `agent/pentest-agent/Dockerfile`, `README.md`

Aggiornare `ARCHITECTURE.md` se cambia il contratto del corpus, della selezione
o del degrado consegnato agli agenti. Nessuna modifica a budget, ruoli o history.
Preservare gli altri cambiamenti presenti nel working tree; niente lint globale.

## P1+ esclusi

- Nuovo motore SAST, migrazione Pro o introduzione di scanner per ogni linguaggio.
- Analisi cross-file aggiuntiva, correzioni Joern e inventario universale delle route.
- Generazione automatica di regole tramite LLM o regole specifiche per il benchmark.
- Catalogo interattivo, telemetria estesa, scheduler di aggiornamento e download
  dei ruleset durante le run.
- SCA delle dipendenze e scansione completa dei segreti come nuovi prodotti.

## Verifiche svolte durante questo handoff

- Ricostruiti hash e diff delle revisioni `b04db39` e `5d51f54`.
- Letto il corpus locale e conteggiate regole/metadata per lingua.
- Riprodotto il filtro che perde i due segnali Go nel task precedente.
- Verificato il flag nativo e ripetuta la prova Go: due segnali conservati.
- Tentata validazione integrale del corpus nell'immagine senza rete: superato
  il timeout imposto di 45s. **Validazione completa non superata**; diagnosticarla
  e completarla nell'implementazione, senza attribuirne ora la causa alla rete
  o alle regole. Nessun audit o agent loop eseguito.
