# Lailaps — Agent Naming & Lore

## Naming philosophy

**Lailaps** prende il nome dal cane della mitologia greca destinato a catturare qualunque preda inseguisse. Il nome rappresenta quindi l'intero sistema: una suite di agenti specializzati che collaborano per individuare, verificare e confermare vulnerabilità.

Per i singoli agenti sono stati scelti personaggi della mitologia greca relativamente meno abusati, privilegiando figure il cui mito richiama direttamente il ruolo tecnico svolto nella pipeline.

## Argus — Reader

**Ruolo:** esplorazione statica della codebase e generazione delle lead.

**Perché Argus:** Argo Panoptes (*Argus Panoptes*) era il gigante dai cento occhi, un guardiano capace di osservare continuamente ciò che lo circondava. È una metafora naturale del Reader: non deve ancora dimostrare una vulnerabilità, ma deve guardare il codice da molti punti di vista, individuare anomalie e produrre piste interessanti.

**Lore:** Argo era un guardiano dalla vista eccezionale; secondo il mito, solo parte dei suoi numerosi occhi dormiva contemporaneamente, rendendolo estremamente difficile da sorprendere.

> **Argus sees.**

## Palamedes — Confirmer

**Ruolo:** scrutinio e arricchimento delle lead prima della conferma dinamica.

**Perché Palamedes:** Palamede era ricordato soprattutto per intelligenza, razionalità e capacità di smascherare gli inganni. Il Confirmer svolge una funzione analoga: riceve ciò che Argus ha osservato e cerca di capire se la pista regge davvero, se deve essere scartata o se mancano ancora informazioni.

Può quindi scartare una falsa pista, rimandarla ad Argus per ulteriori evidenze oppure prepararla per Autolycus.

**Lore:** Palamede fu uno degli eroi achei associati più all'ingegno che alla forza. In una celebre tradizione smascherò persino il tentativo di Odisseo di fingersi pazzo per evitare la guerra di Troia.

> **Palamedes questions.**

## Autolycus — Worker

**Ruolo:** conferma dinamica delle vulnerabilità attraverso tentativi concreti di exploit.

**Perché Autolycus:** Autolico era un leggendario ladro, maestro dell'astuzia e dell'inganno. Non rappresenta la forza bruta dell'attaccante, ma la capacità di trovare una strada attraverso le difese, adattarsi e sfruttare opportunità — esattamente ciò che deve fare il Worker.

**Lore:** figlio di Hermes, Autolico era celebre per la sua abilità nel furto e per la capacità di rendere difficile riconoscere ciò che aveva sottratto. La sua reputazione era fondata sull'astuzia più che sul combattimento diretto.

> **Autolycus attempts.**

## Minos — Judge

**Ruolo:** valutazione finale delle evidenze e decisione sul finding.

**Perché Minos:** dopo la morte, Minosse divenne nella tradizione greca uno dei giudici dei defunti nell'Ade. Il parallelismo con il Judge è diretto: non esplora e non attacca; riceve ciò che gli altri agenti hanno prodotto e pronuncia il verdetto sulla base delle evidenze.

**Lore:** Minosse, leggendario re di Creta, venne ricordato nella tradizione successiva come legislatore e giudice. Nell'oltretomba figura insieme a Radamanto ed Eaco tra coloro che giudicano le anime.

> **Minos judges.**

## The Lailaps pipeline

```text
                     LAILAPS
                        │
                        ▼
                 ARGUS — Reader
                    "sees"
                        │
                     lead
                        ▼
              PALAMEDES — Confirmer
                  "questions"
                 ╱      │      ╲
            discard   enrich   ready
                       │        │
                       └───┐    │
                           ▼    ▼
                    AUTOLYCUS — Worker
                        "attempts"
                             │
                          evidence
                             ▼
                       MINOS — Judge
                         "judges"
```

La sequenza sintetizza la filosofia del sistema:

**Argus sees → Palamedes questions → Autolycus attempts → Minos judges.**

Lailaps non è quindi un quinto agente: è il nome della caccia nel suo complesso.
