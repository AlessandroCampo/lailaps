# Avvio manuale del pilot

Dalla root di Lailaps-1, sul branch `codex/cloudflare-skills-test`.
La baseline del codice precedente è `46160f3`; le run storiche di confronto sono
in [GOLDEN_SELECTION.md](GOLDEN_SELECTION.md).

Il runtime container usa l'immagine `lailaps-pentest-agent:minimal` configurata
in `config/pentest.php`: ricostruirla per includere il nuovo codice e le skill.

```powershell
docker build -t lailaps-pentest-agent:minimal agent/pentest-agent
php artisan benchmark:recon-reader cacti --recon-run=cacti-recon-reader-global-20261020-144734 --cloudflare-hunting --reader-concurrency=4 --reader-points=500000 --reader-model=xiaomi/mimo-v2.6-pro --repetitions=1 --timeout=7200
php artisan benchmark:recon-reader yeswiki --cloudflare-hunting --reader-concurrency=4 --reader-points=500000 --recon-model=xiaomi/mimo-v2.6-pro --reader-model=xiaomi/mimo-v2.6-pro --repetitions=1 --timeout=7200
```

Le run sono workspace-only con quattro Reader concorrenti, senza Investigator.
Il flag abilita ora `reader_knowledge_mode=progressive_v1`: metodo e catalogo nel
prompt, 58 topic nei quattro domini previsti accessibili tramite `read_skill`.
Le letture sono scelte semanticamente dai Reader e non sono prerequisiti per
emettere lead; trace completa, mitigazioni e controprove restano all'Investigator.
I documenti tecnici sono nel package e non vengono scaricati durante la run.
Cacti riusa la Recon della run Hunting interrotta e parte direttamente dal Reader;
YesWiki genera una Recon fresca, perché non è presente una run Hunting equivalente.
Il pool non riprende le lead né la memoria della run precedente: riparte da zero
sugli stessi incarichi e briefing. Il nome del comando rimane `recon-reader`, ma
`--recon-run` salta completamente il passo Recon.
Ogni target ha 500k EP aggregati Reader, compresi i checkpoint; la Recon è
conteggiata separatamente entro la propria riserva e il cap complessivo.
Il default rimane 3600 secondi; i comandi sopra impostano esplicitamente 7200
per dare spazio al consumo del budget senza il precedente cutoff di un'ora. Un esaurimento del budget
può conservare copertura incompleta e risultati validi; un timeout o errore
tecnico va riportato nel confronto, non ignorato.

Per Cacti sono stati verificati 13 incarichi, briefing di 4.159 caratteri e snapshot
coincidente. Il briefing è già nell'outcome, in
`report.coverage.category_recon.checkpoint_summary`; non c'è un `architecture.md`
separato da rigenerare. I sensori incompleti conservano la propria limitation e
non vengono rieseguiti. Per riusare una futura run YesWiki aggiungere
`--recon-run=<id-run-recon-reader-global>`: target/snapshot, ready, briefing e
locator devono superare i controlli; un errore non avvia una nuova Recon.

`agent/pentest-agent/Models.json` attualmente imposta `medium` per Recon e Reader;
eventuali override nel file env dell'agente vanno controllati prima del pilot.
Il report conserva modello e reasoning effettivamente configurati. Modificare
il file Models.json montato non richiede rebuild; le skill e il Python sì.

Snapshot locali verificati durante l'implementazione:

- Cacti: `6482af547c204199e829b7a0df0b7a13db3e0a58`;
- YesWiki: `7325759547611def210a731b103878bd696c419c`.

Il comando stampa il path dell'outcome in
`storage/app/runs/<target>/recon-reader-global/<run_id>/`.
Confrontare gli output Reader accettati e il loro consumo EP con le golden;
escludere Recon dal denominatore lead/EP, riportandone comunque costo e durata.
`recon_reader_experiment` conserva hash degli asset disponibili, modalità,
provenienza e overshoot; `reader_skill_reads` distingue le letture riuscite con
topic, hash del testo restituito, scope e tool call ID. Gli hash della libreria
non provano che ogni topic sia stato letto. Una rilettura resta consentita dopo
compaction; i testi in history concorrono al contesto e al costo reale.
`global_pipeline.recon_reuse` conserva checksum dell'input e indica zero EP e zero
richieste modello Recon nel riuso. Per la baseline Cacti contare 14 lead accettate,
non le 15 emissioni grezze. La golden rg del 1 ottobre ha concorrenza 3 e budget
per area: il parallelismo 4 e il cap aggregato corrispondono invece alla gl-230800.
Il confronto rimane storico; un confronto causale delle sole skill richiederebbe
stessa Recon, stesso modello e stesso cap anche con il flag spento.
I match automatici al catalogo richiedono riesame prima di essere contati come
vulnerabilità distinte. Nessuna delle run qui documentate è stata lanciata
durante l'implementazione.

## Verifiche eseguite

- 89 test Python su Recon/Reader e pool globale con richieste provider disabilitate
  e modelli fake; altri 15 degli stessi test rieseguiti con esito positivo dopo
  le ultime modifiche a letture e pubblicazione. Coperti catalogo, asset mancanti,
  ID non validi, rilettura dopo cambio epoch, boundary tool-free, esclusione dagli
  altri ruoli e provenienza distinta dei quattro Reader nei parziali/finali;
- regressione con FunctionModel: una lead con propagazione incompleta e mitigazioni
  non verificate è accettata sia prima di consultare skill sia dopo una lettura,
  senza richieste di prova aggiuntive o chiamate provider;
- 21 test Laravel in `BenchmarkReconReaderCommandTest.php` verificati nel precedente
  incremento, con processo fixture; nessuna nuova modifica Laravel in questo passo;
- build wheel offline e lettura dei documenti dal package estratto in una directory
  temporanea: 8 checksum coincidenti, 58 topic, 4.034 caratteri Recon e 10.023
  caratteri di metodo/catalogo Reader aggiunti. I quattro riferimenti contengono
  45.712 caratteri; ogni risposta composta misura fra 1.498 e 1.932 caratteri;
- validatore della skill Reader, controllo sintattico Python e provenienza/licenza;
- snapshot Git locali dei due target coincidenti con le golden.
- riuso in sola lettura dell'outcome Hunting Cacti: 13 aree, briefing e snapshot
  validi, senza importazione di finding o usage storici.

La build Docker e le due run con il modello restano da eseguire manualmente con
i comandi sopra. La validazione offline non misura il miglioramento dei finding.

## Replay Investigator separato

L’integrazione di briefing e topic sulle 11 lead Cacti è implementata, senza
nuove regole di validazione. Fixture e comandi manuali sono in
[INVESTIGATOR_RUN.md](INVESTIGATOR_RUN.md). Le verifiche Reader riportate sopra
si riferiscono al precedente incremento; quelle Investigator sono nella nuova guida.
