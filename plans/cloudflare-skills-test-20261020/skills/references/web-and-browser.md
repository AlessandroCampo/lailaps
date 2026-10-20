# Prospettive HTTP, identità e browser

Adattamento di `WEB-PROTOCOL-AND-AUTH.md` e `CLIENT-SIDE.md` Cloudflare per target web Lailaps. Applicare le parti corrispondenti a componenti osservati. Le condizioni qui elencate orientano l'indagine, non impongono al Reader una prova completa prima della lead.

## HTTP e cache

Se due componenti interpretano la stessa richiesta, confronta framing, header duplicati, normalizzazione del path e downgrade di protocollo. Nomina entrambi i parser: non assumere l'esistenza o configurazione di un proxy dal solo framework.

Confronta la chiave di cache con tutti gli input che cambiano risposta, identità o autorizzazione: host, schema, query, header, cookie e tenant. Verifica differenze fra routing statico/dinamico, risposte private e fallback.

Segui `Host` e header forwarded verso URL assoluti, reset link, callback, client address e routing tenant. Chiarisci quale componente elimina o accetta le copie del chiamante. Per header di risposta verifica anche la gestione dei caratteri da parte del framework.

## Sessioni, CSRF e identità

Per una mutazione con credenziali ambientali individua forma della richiesta, controllo CSRF, Origin/Referer e comportamento dei cookie. Una route con bearer esplicito non ha automaticamente lo stesso modello CSRF di una sessione browser.

Segui login, cambio account, privilegio, MFA, logout, revoca e recupero: identificatori ruotati, token invalidati e copie in cache. Un attributo cookie assente da solo non dimostra compromissione; identifica l'origine o il principal che ne trarrebbe vantaggio.

Per JWT verifica firma e provenienza della chiave, algoritmo consentito, issuer, audience, scadenza e uso effettivo dei claim. Per OAuth/OIDC confronta state, PKCE, nonce, callback, sessione e account iniziatore, secondo il ruolo concreto del target. Per SAML verifica che l'oggetto firmato sia quello consumato come identità.

Per MFA/passkey/recovery/account linking verifica a chi e a quale sessione, azione, origine e livello di autenticazione è vincolata la prova. Confronta enrollment, fallback, retry e API alternative. Per API key e mTLS separa autenticazione dalla successiva associazione a tenant, scope e risorsa.

## DOM, messaggi e oggetti

Segui input da URL, fragment, referrer, `window.name`, storage, messaggi e dati server verso HTML, script, navigazione o API di rendering. Verifica l'escaping effettivo e le modalità che lo disabilitano.

Per DOM clobbering cerca sia markup controllabile che sopravvive al filtro sia un uso sensibile della proprietà oscurata. Per prototype pollution cerca scrittura ricorsiva e consumo della proprietà in un comportamento sensibile: `JSON.parse` da solo non dimostra il difetto.

Per `postMessage` confronta origin esatta, source della finestra e azione richiesta. Per CORS verifica l'origine realmente ammessa e il dato esposto: wildcard con credenziali non equivale automaticamente a una lettura cross-origin accettata dal browser. Per WebSocket controlla handshake e handler che usa l'autorità della sessione.

## Persistenza e confini del browser

Controlla service worker, Cache Storage, IndexedDB e storage in caso di cambio account, logout, offline fallback, aggiornamento e tab rimaste aperte. Chi scrive? Chi può leggere successivamente? Quale account/origine possiede il risultato?

Per clickjacking identifica un'azione sensibile realmente completabile nel frame. Per navigazione controlla destinazione, schema e relazione opener. Per XS-Leaks identifica il fatto riservato distinguibile, non solo una variazione generica di timing.

Escaping del framework, same-origin policy, CSP, cookie, COOP/CORP e isolamento opener possono impedire l'effetto. Il Reader segnala la domanda concreta; l'Investigator verifica i controlli pertinenti e il risultato nella sessione/browser disponibile.

Header di deployment, comportamento IdP, certificati o browser non osservati restano fatti mancanti. Non trasformarli in prove di assenza del controllo o in reiezioni automatiche. Le conferme e i blocchi usano esclusivamente i contratti Lailaps.
