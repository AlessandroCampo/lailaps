# Worker browser

`server.mjs` espone il protocollo autenticato; `runtime.mjs` implementa context,
azioni, raccolta e inspection. `target-proxy.mjs` vincola l'egress del context
all'origin autorizzato anche dopo redirect, preservando POST e tunneling TLS.
La versione Playwright coincide con quella
dell'immagine Docker. Il Worker continua a usare soltanto `browser_flow` e
`inspect_browser`, con record piatti.

## POST con query e body separati

```json
{
  "actor": "anonymous",
  "steps": [
    {"action": "submit_form", "path": "/revision?time=123", "form": "time=marker&field=a&field=b"}
  ],
  "assertions": [
    {"assertion": "dialog", "expected": "unique-execution-marker", "timeout_ms": 3000}
  ]
}
```

Il marker e il trigger effettivi dipendono dalla lead. `form` e' testo
URL-encoded: codificare i valori, mantenendo i campi ripetuti quando necessari.
La form sintetica provoca una navigazione POST reale e rispetta la CSP della
risposta. Il suo origin e' il target: non riproduce un attacco cross-site.
Se il context e' nuovo, il tool apre prima la root del target.

## Cookie

```json
{"action": "set_cookie", "name": "view", "value": "compact", "path": "/", "same_site": "Lax", "secure": false, "http_only": false}
```

Per cancellarlo: `{"action":"clear_cookie","name":"view","path":"/"}`.
Il dominio e' determinato dall'harness, non dal modello. Le sessioni degli actor
restano isolate e separate dai tool HTTP. Impostare cookie e' setup: il Worker
deve giustificare come l'attaccante possa controllare lo stato necessario.

## Feedback e recupero

- Leggere il `summary`: riporta assertion PASS/FAIL e osservazioni citabili.
  `assertion_failed` significa che gli step sono terminati ma l'oracle no.
- Un elemento ritardato puo' essere atteso con `wait_for`; `detached` e `hidden`
  ammettono un elemento gia' assente. Locator ambigui restano errori.
- `inspect_browser(mode="forms")` elenca form, campi e locator; `aria` aiuta
  con controlli senza form. Nessun valore degli input viene incluso in `forms`.
- `network` include Content-Type, CSP, Origin, Referer, dimensione body e nomi
  dei campi form, senza body o header di autenticazione.
- Per continuare un'ispezione passare `next_cursor` come `cursor`, mantenendo
  actor, mode e selector. Lo snapshot rimane stabile anche dopo una navigazione.
  Otto snapshot per context, massimo 1 MB ciascuno; il limite viene segnalato.
- Ogni nuova chiamata `browser_flow` esegue nuovamente gli step. Rileggere con
  `inspect_browser` evita di ripetere mutazioni. Solo ritrasmissioni del medesimo
  invocation id interno vengono deduplicate.
- La reflection HTTP e' evidenza parziale per XSS. In assenza di una catena
  browser esprimibile, riportare il blocco mantenendo la lead sospetta.

## Test Chromium isolati (PowerShell, dalla root)

```powershell
docker build -t lailaps-playwright-worker:test agent/playwright-worker
$browserTests = (Resolve-Path agent/playwright-worker/tests).Path
$browserSeccomp = (Resolve-Path agent/playwright-worker/seccomp_profile.json).Path
docker run --rm --network none --cap-drop ALL --cap-add SYS_CHROOT --security-opt no-new-privileges --security-opt "seccomp=$browserSeccomp" --mount "type=bind,source=$browserTests,target=/srv/tests,readonly" lailaps-playwright-worker:test npm test
```

Le fixture HTTP vivono sul loopback dello stesso container. I test non usano
credenziali, servizi esterni o un modello LLM e mantengono il sandbox Chromium.
