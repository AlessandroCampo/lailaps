# Recon -> Reader: esperimento P0

19 settembre 2026. Implementato sul branch `experiment/recon-reader-assignments`,
dal checkpoint `29b286a`. Verificato offline; nessuna run con modelli avviata.

## Contratto implementato

Recon costruisce incarichi funzionali usando briefing del progetto, CBM quando
disponibile e sorgente. Semgrep e' consultabile in ogni turno, ma il prompt chiede
prima una mappa funzionale, poi i segnali e infine una riconciliazione delle omissioni.
Non riceve gli hotspot nel contesto iniziale. Una componente non deve sembrare
vulnerabile per meritare un incarico.

Output model-facing di esempio; i path devono essere osservati nel progetto reale:

```json
{
  "briefing": "Wiki con utenti, editor e amministratori. I campi alimentano template e widget; gli helper condividono trasformazioni ma i contesti di output variano.",
  "assignments": [
    {
      "title": "Rendering dei valori dei campi",
      "paths": ["templates/fields/"],
      "next_check": "Individua origine e trasformazioni dei valori. Confronta contesti HTML, attributo e widget e le protezioni applicate; segui helper e percorsi alternativi."
    },
    {
      "title": "Modifica e cancellazione degli oggetti",
      "paths": [],
      "next_check": "Localizza i dispatcher delle operazioni di scrittura. Confronta identita', ruoli e ownership richiesti per modificare o cancellare lo stesso tipo di oggetto."
    }
  ],
  "coverage_notes": "Il dispatch dei plugin non e' ancora chiaro: seguire gli ingressi alternativi durante gli incarichi e proporre un incarico aggiuntivo se emerge comportamento indipendente."
}
```

L'orchestratore assegna ID e stato attraverso il ledger esistente. Reader riceve un
incarico completo, briefing e note comuni e solo ID/titoli degli altri incarichi.
Il Reviewer riceve il contesto di coordinamento completo. Reader puo' seguire helper
e chiamanti fuori dai path iniziali e proporre nuovi incarichi tramite enrichment.

Il pacchetto del P0 e' ancora `ReaderLead`: locator, osservazione, operazione/controllo,
ipotesi e unknowns. Una lead non chiude l'incarico e non cambia la history; il Reviewer
decide la chiusura o il pivot. Un nuovo incarico apre una nuova epoch. Non vengono
avviati Confirmer, Worker, Judge o richieste HTTP. Una lead non e' una conferma statica.

## Esecuzione manuale successiva

```powershell
php artisan benchmark:recon-reader <target-id> --repetitions=1
```

Il comando usa i manifest dello stesso target/snapshot e la copia sanitizzata di
`targets/<target-id>`. Accetta `--path`, `--recon-model`, `--reader-model`,
`--reviewer-model`, `--budget-category`, `--timeout` e `--tool-output`.
Concorrenza Reader fissata a uno; ogni repetition riparte da Recon. Nessuna fixture
Recon congelata o ground truth viene passata agli agenti.

Il budget discovery resta condiviso fra Recon, Reader e Reviewer. Prima del confronto
registrare modelli, preset effettivo e cutoff; non equiparare numero di incarichi a
copertura. Un fallimento Recon termina il test e non attiva un fallback senza guida.

## Lettura dei risultati

L'outcome standard sotto `storage/app/runs` conserva piano, lead, osservazioni,
checkpoint, telemetria e diagnostici per manifest. I diagnostici automatici non sono
un verdetto semantico: il benchmark resta `pending_semantic_review` fino al riesame.
Il costo complessivo e' quello della run congiunta, senza sommare lo stesso costo dai
diversi manifest. I read di Recon non contano come copertura Reader.

Nel riesame del primo target verificare:

- quali comportamenti Recon ha assegnato, escluso o lasciato incerti;
- quali percorsi Reader ha seguito e quali domande concrete ha prodotto;
- quali casi noti distinti sono rappresentati dalle domande, senza premiare duplicati;
- quali omissioni dipendono dal piano, dal comportamento Reader o dal cutoff condiviso.

Questo primo test include il Reader recentemente modificato: un risultato insufficiente
non identifica da solo Recon come causa. I test offline verificano passaggio di contesto,
sequenza e persistenza; non misurano la qualita' della ricerca del modello.

## P1+ non inclusi

- Reader concorrenti e assegnazione dinamica fra processi indipendenti.
- Nuovo protocollo InvestigationPacket e revisione del lifecycle Reader/Reviewer.
- Judge semantico automatico del benchmark o confronto sperimentale fra strategie.
