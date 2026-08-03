Lailaps è un progetto per valutare la sicurezza dei progetti web, 
auditati con il consenso degli utenti in ambiente protetto. Fornisce un AUDIT di vulnerabilità attraverso un approccio ibrido gray box.

In ambiente di produzione, un cliente caricherà come zip temporaneo il codice del progetto, che verrà letto localmente 
dall'agente. In aggiunta, è necessario che il progetto abbia un ambiente correttamente e setuppato e raggiungibile
per confermare le sospette vulnerabilità trovate sul codice con effettive chiamate HTTP.

Vogliamo supportare 3 modalità principali di creazione dell'ambiente di sviluppo:

- Docker container creato programmaticamente dall'applicativo
- Container già dichiarato all'interno del progetto e inizializzato a runtime durante la fase di setup
- Ambiente già setuppato dall'utente finale, che offre un URL già raggiungibile (Ambiente di test già pubblicato, url locale condiviso con tool di tunnel forwarding come Ngrok)

I primi due approcci sono preferibili in produzione, il terzo è preferibile in fase di testing. Un health check viene fatto sul target per assicurarsi che la folder del progetto
target sia leggibile, il base URL raggiungibile e l'eventuale Database correttamente inizializzato. 

Il loop agentico è gestito in python tramite Pydantic AI, mentre il progetto Laravel 13 gestisce tutto ciò che avviene PRIMA dell'inizializzazione dell'agente.

L'obbiettivo è generare un report strutturato, che divida i finding in:

- Suspect (Letti sul codice, ma non confermati tramite chiamata HTTP)
- Confermati (Effettivamente exploitati)


I tool e l'architettura devono essere generalistici per permettere l'auditing corretto di applicazioni sviluppate con stack diversi, metodi di autenticazione diversi
e vulnerabilità potenziali diverse.

Il progetto ha solo scopo difensivo e didattico e non verrà utilizzato su progetti reali