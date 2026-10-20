# Replay Investigator sulle 11 lead Cacti

Implementato in Lailaps-1 sul branch `codex/cloudflare-skills-test`, 20 ottobre 2026.
Scope: briefing generale + 58 topic incrementali, senza nuovi requisiti di validazione.
Piano aggiornato: [INVESTIGATOR_P0_PLAN.md](INVESTIGATOR_P0_PLAN.md).

## Input pronto

Fixture locali: `storage/framework/lailaps-investigator-p0/cacti-20261020-173047/`.
Il manifest usa lo schema replay esistente e punta a 11 subject e al contesto frozen
Recon/sensori. Inventario e checksum: [INVESTIGATOR_INPUT.json](INVESTIGATOR_INPUT.json).
Origine esatta: `cacti-recon-reader-global-20261020-173047`, snapshot
`6482af547c204199e829b7a0df0b7a13db3e0a58`. Non selezionare automaticamente una run più recente.

Il preflight reale è già passato: 11 artifact, tutti globali, reference e contesto
con checksum validi. Non ha avviato modelli, target Docker o HTTP. Le fixture sono
artifact locali sotto storage: conservarle insieme alla run sorgente per ripetere
il test; non vengono scaricate dall'agente.

## Lancio manuale dalla root Lailaps-1

Ricostruire l'immagine per includere Python e knowledge aggiornati (tag predefinito):

```powershell
docker build -t lailaps-pentest-agent:minimal agent/pentest-agent
```

Rivalidazione facoltativa dei soli artifact, senza modelli o sandbox:

```powershell
php artisan benchmark:confirmer cacti --unified-lead --replay-manifest=storage/framework/lailaps-investigator-p0/cacti-20261020-173047/manifest.json --cloudflare-hunting --lead-concurrency=1 --lead-model=xiaomi/mimo-v2.6-pro --envelope-points=20000000 --preflight-only
```

Verifica runtime/fixture, prepara Docker e può eseguire probe HTTP, ma non modelli:

```powershell
php artisan benchmark:confirmer cacti --unified-lead --replay-manifest=storage/framework/lailaps-investigator-p0/cacti-20261020-173047/manifest.json --cloudflare-hunting --lead-concurrency=1 --lead-model=xiaomi/mimo-v2.6-pro --envelope-points=20000000 --runtime-preflight
```

**Run effettiva con modelli**, una ripetizione e una sessione alla volta:

```powershell
php artisan benchmark:confirmer cacti --unified-lead --replay-manifest=storage/framework/lailaps-investigator-p0/cacti-20261020-173047/manifest.json --cloudflare-hunting --lead-concurrency=1 --lead-model=xiaomi/mimo-v2.6-pro --envelope-points=20000000 --repetitions=1
```

Nessuna nuova Recon o discovery Reader, nessun nuovo scan Semgrep: si riusano
briefing e sensori della run sorgente, inclusi i loro limiti dichiarati. Le sessioni
HTTP/browser e lo stato runtime vengono preparati dal normale wrapper benchmark;
non si importano cookie, notebook o risultati investigativi storici.

Il cap 20M EP è la proposta per il batch di 11, non il consumo atteso o una garanzia
di completamento. Le allowance iniziali esistenti sono 500k statici e 1M dinamici per
lead, prima delle estensioni. Il valore evita il default replay 100M. Nessun budget
runtime è stato ridisegnato. Il modello è unico per le due fasi; controllare gli
override effettivi di reasoning/configurazione prima di confrontare costi.

`benchmark:confirmer` non espone `--timeout`/`--ttl`: valgono timeout agente della
configurazione Laravel (default 7200s) e TTL sandbox di `pentest:run` (default 9000s).
Un cutoff resta un esito tecnico da riportare. Questa consegna non modifica né
prolunga automaticamente tali limiti.

## Cosa leggere nell'outcome

Il comando stampa run ID/path; gli outcome sono sotto `storage/app/runs/cacti/`.
`global_lead_replay` collega ogni input selezionato alla sua ammissione/indagine;
`knowledge` registra modalità `briefing_topics_v1` e hash degli asset.
Nei finding, `lead_investigation.knowledge.reads` distingue topic, hash del testo,
fase e tool call ID. Gli hash disponibili non provano che un topic sia stato letto.
Il testo tecnico è advice, non una source reference o prova sul target.

Rivedere per ciascun input: esito statico, esito dinamico, fatto decisivo, blocker,
EP e durata. Separare conferme, confutazioni e casi ancora suspected/blocked;
riportare anche duplicati, mancata ammissione o finanziamento. Undici lead non sono
undici vulnerabilità. LDAP/DNS/poller mancanti non sono automaticamente confutazioni.
Il singolo replay non misura da solo il gain causale delle skill rispetto al baseline.

## Verifiche della consegna

- 115 casi Python verificati offline nelle suite unified lead, global pipeline e
  Recon/Reader, con richieste provider disabilitate e modelli fake.
- 5 test Laravel, 58 assertion: flag, replay globale e inoltro dei parametri.
- Preflight reale: tutti gli 11 subject validi, nessun avvio agente.
- Wheel costruita offline e importata dalla directory estratta: 58 topic, 7 hash
  Investigator e 8 Reader; guida/catalogo Investigator di 9.020 caratteri.
- Metodo/catalogo e tutte le 58 risposte Reader identici alla versione precedente;
  nessuna istruzione di emissione Reader nella vista Investigator.
- Validazione della skill, sintassi dei file coinvolti e controllo whitespace.

Build Docker, runtime-preflight e run con modelli **non eseguiti** durante il task.
