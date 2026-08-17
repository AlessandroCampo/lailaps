# Lailaps — Sintesi evoluzione prodotto e possibili estensioni

## 1. Posizionamento del prodotto

Lailaps non dovrebbe essere presentato come un semplice **AI vulnerability scanner** o come un sostituto diretto di strumenti SAST tradizionali come Semgrep, Snyk Code o CodeQL.

Il posizionamento più interessante è:

> **White-box autonomous security validation:** Lailaps analizza il codice, individua una vulnerabilità sospetta, prova a sfruttarla sull'applicazione reale e conserva una prova verificabile del finding.

La differenza chiave rispetto a uno scanner classico è quindi:

```text
Traditional scanner:
source → data flow → potential vulnerability

Lailaps:
source → suspect → runtime exploit → confirmed vulnerability
```

Il vantaggio competitivo dovrebbe essere soprattutto sulle vulnerabilità dove il semplice static analysis è meno efficace:

- Broken Access Control / IDOR
- authentication bypass
- mass assignment
- privilege escalation
- business logic vulnerabilities
- vulnerabilità che richiedono conoscenza combinata di codice e comportamento runtime

---

## 2. Pipeline core

L'architettura discussa parte dal modello multi-agent già adottato:

```text
Reader
  ↓
Suspect

Worker
  ↓
Runtime confirmation

Judge
  ↓
Confirmed / Rejected finding
```

### Reader

Responsabile dell'analisi statica e della codebase.

Obiettivo:

- individuare flow sospetti;
- raccogliere il contesto minimo necessario;
- formulare una hypothesis concreta da verificare.

### Worker

Responsabile dell'interazione con il target runtime.

Obiettivo:

- provare a riprodurre realmente la vulnerabilità;
- raccogliere evidence;
- produrre una PoC ripetibile.

### Judge

Responsabile della valutazione finale delle evidenze.

Il suo ruolo dovrebbe rimanere stretto:

> decidere se le evidenze raccolte sono sufficienti per dichiarare il finding confermato.

È preferibile evitare che Judge diventi un agente generalista.

---

# 3. Estensione principale: Security Regression Tests

L'estensione più interessante emersa dalla discussione non è necessariamente l'autofix.

È la trasformazione automatica di ogni vulnerabilità confermata in un **security regression test**.

La pipeline diventerebbe:

```text
scan
  ↓
suspect
  ↓
confirm exploit
  ↓
judge
  ↓
confirmed finding
  ↓
generate security regression test
```

Il test dovrebbe derivare direttamente dalla PoC usata dal Worker.

Esempio:

```text
Actor A autenticato

GET /api/orders/123

Order 123 appartiene a User B

Expected:
403 / 404

Vulnerable behaviour:
200 + dati di B
```

Questa evidence può essere trasformata in una proprietà permanente:

```text
Given:
  User A
  User B
  Resource owned by B

When:
  A requests B's resource

Then:
  access must be rejected
  B's data must not be exposed
```

Il vantaggio è che il test nasce da un exploit già osservato e non da una semplice ipotesi generata dal modello.

---

# 4. Nuovo possibile agente: Tester

È preferibile introdurre un agente dedicato invece di affidare la generazione dei test a Judge.

Architettura:

```text
Reader
  ↓
Worker
  ↓
Judge
  ↓
CONFIRMED FINDING
  ↓
Tester
  ↓
Security Regression Spec
```

Il Tester riceve:

- finding confermato;
- evidence del Worker;
- attori utilizzati;
- setup necessario;
- request che ha sfruttato la vulnerabilità;
- comportamento vulnerabile osservato;
- comportamento sicuro atteso.

Possibili tool:

- creazione di utenti/dati;
- HTTP interaction;
- setup/reset DB;
- `save_security_test`;
- `run_security_test`.

Il Tester dovrebbe anche verificare immediatamente il test.

Prima del fix il risultato atteso è:

```text
FAIL
```

perché la vulnerabilità esiste ancora.

Dopo il fix:

```text
PASS
```

Questo realizza una forma di:

> **Security Regression-Driven Development**

---

# 5. Security test come asset permanente

Il finding non avrebbe più una vita limitata a:

```text
finding → fix → finding disappears
```

ma:

```text
finding
  ↓
confirmed exploit
  ↓
security invariant
  ↓
permanent regression test
  ↓
CI forever
```

Ogni vulnerabilità trovata aumenterebbe quindi permanentemente la security coverage del progetto.

Possibile struttura:

```text
.lailaps/
  findings/
    LAILAPS-001.yaml
    LAILAPS-002.yaml

  security_tests/
    LAILAPS-001.yaml
    LAILAPS-002.yaml
```

In una prima versione i test potrebbero rimanere in un formato interno Lailaps.

In futuro sarebbe possibile esportarli come:

- PHPUnit
- Pest
- pytest
- Jest
- framework-specific tests

---

# 6. Autofix: utile, ma non core

L'autofix con AI è probabilmente destinato a diventare una commodity.

Coding agent come:

- Codex
- Claude Code
- GitHub Copilot
- Cursor

sono già molto efficaci nella modifica del codice.

Lailaps non deve necessariamente essere il miglior agente che scrive la patch.

Il valore più difendibile è:

> **Lailaps sa verificare se la patch ha realmente eliminato la vulnerabilità.**

Quindi la separazione ideale è:

```text
Lailaps:
find → exploit → create regression test

Developer / Coding Agent:
fix

Lailaps:
verify
```

Possibile messaging:

> **Your coding agent writes the fix. Lailaps decides whether it's secure.**

---

# 7. Verified Remediation

In futuro Lailaps può comunque offrire un proprio agente Fixer.

Pipeline:

```text
scan
  ↓
suspect
  ↓
confirm
  ↓
fix
  ↓
targeted rescan
  ↓
replay exploit
  ↓
legitimate control test
```

È però fondamentale che il Fixer non possa dichiarare autonomamente la patch riuscita.

Separazione:

```text
Worker
  ↓
confirmed exploit

Fixer
  ↓
patch

NEW Worker instance
  ↓
attack again
```

La verifica dovrebbe controllare almeno due condizioni:

```text
BEFORE

legitimate request → 200
malicious request  → 200  ❌

AFTER

legitimate request → 200  ✅
malicious request  → 403  ✅
```

Non basta che l'exploit smetta di funzionare: la patch potrebbe aver semplicemente rotto la funzionalità.

Per questo il nome più interessante sarebbe:

> **Verified Fix**

oppure:

> **Verified Remediation**

piuttosto che "AI Autofix".

---

# 8. Targeted rescan

Una volta che una vulnerabilità è stata individuata, non è necessario rilanciare un audit completo.

Lailaps conosce già il security flow coinvolto.

Esempio:

```text
Route
  ↓
Controller
  ↓
Model lookup
  ↓
Authorization missing
  ↓
Resource
```

Dopo la patch, Reader può ricevere una richiesta molto più specifica:

> Verifica esclusivamente se il dataflow che produceva LAILAPS-143 è stato eliminato e se la patch ha introdotto percorsi equivalenti.

Vantaggi:

- costo token molto inferiore;
- latenza inferiore;
- minore rumore;
- verifica più deterministica.

---

# 9. Integrazione GitHub

Lailaps potrebbe diventare una **GitHub App**, oltre che una webapp indipendente.

Workflow:

```text
Pull Request
  ↓
Lailaps Security Check
  ↓
run existing security regression tests
  +
targeted analysis of changed code
```

Esempio UI:

```text
PR #184

✓ unit-tests
✓ build
✓ CodeQL
✗ Lailaps Security

Critical: Broken Access Control

Exploit reproduced
Security regression: FAIL
```

Lailaps potrebbe inoltre produrre:

- Check Runs;
- annotazioni nelle PR;
- required security checks;
- SARIF;
- link alla evidence completa nella webapp.

### Nessuna necessità di sostituire GitHub Code Security

La possibile convivenza è:

```text
CodeQL / Semgrep / Snyk
  ↓
deterministic detection

+

Lailaps
  ↓
reasoning
  ↓
runtime exploit validation
  ↓
regression testing
```

Lailaps potrebbe in futuro perfino usare strumenti SAST tradizionali come segnali interni.

---

# 10. MCP / integrazione con coding agent

Lailaps può diventare un servizio utilizzabile direttamente dai coding agent tramite MCP.

Possibili tool pubblici:

```text
lailaps_scan
lailaps_get_findings
lailaps_get_finding
lailaps_run_security_tests
lailaps_verify_fix
```

È preferibile NON esporre i micro-tool interni:

```text
read_file
grep
shell
http_call
browser
...
```

Il coding agent non dovrebbe orchestrare internamente Reader/Worker/Judge.

Lailaps deve rimanere una black box specializzata:

```text
Coding Agent
  ↓
verify finding

LAILAPS CORE
  ├─ Reader
  ├─ Worker
  ├─ Judge
  └─ Tester
  ↓

PASS / FAIL
```

Esempio:

```text
Developer:
Fix LAILAPS-018

Coding Agent:
→ lailaps_get_finding("LAILAPS-018")

Agent modifica il codice

→ lailaps_verify_fix("LAILAPS-018")

Lailaps:
PASS
Original exploit no longer succeeds.
Legitimate access still works.
```

---

# 11. CLI

Prima o parallelamente all'MCP sarebbe utile una CLI.

Esempi:

```bash
lailaps scan
```

```bash
lailaps verify LAILAPS-018
```

```bash
lailaps test
```

Architettura:

```text
                 Lailaps API
                    ↑
       ┌────────────┼─────────────┐
       │            │             │
      CLI       GitHub App       MCP
       │                          │
       └────────── Web UI ────────┘
```

Un solo engine, più superfici di utilizzo.

---

# 12. Webapp come Control Plane

La webapp rimane importante, ma non deve necessariamente essere il principale punto di utilizzo quotidiano.

Può diventare il **control plane** per:

- configurazione dei progetti;
- ambienti target;
- storico degli scan;
- finding;
- exploit evidence;
- security tests;
- team;
- policy;
- costi;
- report;
- audit log;
- gestione CI;
- gestione integrazioni.

Lo sviluppatore può invece interagire quotidianamente tramite:

- GitHub;
- CLI;
- Codex;
- Claude Code;
- Cursor;
- altri coding agent.

---

# 13. Scan diversi per frequenza diversa

Non è necessario eseguire un full security audit a ogni commit.

## Pull Request Scan

Più economico:

```text
existing regression tests
+
diff-aware static analysis
+
targeted runtime validation
```

## Nightly / Release Scan

Più profondo:

```text
full OWASP audit
+
new vulnerability exploration
+
runtime confirmation
```

Questo rende sostenibile il costo dei loop agentici e permette un pricing più prevedibile.

---

# 14. Possibile architettura complessiva futura

```text
                       ┌────────────────────┐
                       │      Web App       │
                       │   Control Plane    │
                       └─────────┬──────────┘
                                 │

GitHub App ─────────────┐        │
                       │        ▼
CLI ───────────────────┼──► ┌───────────────┐
                       │    │               │
Codex / MCP ───────────┤    │  LAILAPS CORE │
                       │    │               │
Claude / MCP ──────────┤    │ Reader        │
                       │    │ Worker        │
Cursor / MCP ──────────┘    │ Judge         │
                            │ Tester        │
                            │               │
                            └───────┬───────┘
                                    │
                                    ▼
                              Target Runner
                                    │
                                    ▼
                           Runtime Application
```

In futuro:

```text
LAILAPS CORE
  ├─ Reader
  ├─ Worker
  ├─ Judge
  ├─ Tester
  └─ Fixer (optional)
```

---

# 15. Business model discusso

Lailaps dovrebbe probabilmente evitare un pricing puramente "per developer", perché il costo marginale di una run agentica è legato soprattutto a:

- token;
- durata del reasoning;
- numero di categorie analizzate;
- runtime/container;
- HTTP interactions;
- numero di verification run.

Un modello interessante:

> **Subscription + Scan Credits**

Possibili tier iniziali:

| Piano | Target | Prezzo indicativo |
|---|---|---:|
| Free | singoli sviluppatori / OSS | €0 |
| Developer | freelance / indie | ~€29/mese |
| Team | startup / piccoli team | ~€99-149/mese |
| Scale | PMI / AppSec | ~€399-799/mese |
| Enterprise | aziende | custom |

Possibile prodotto premium separato:

> **Lailaps Hunt / Autonomous Pentest**

Audit più profondo venduto per applicazione o per run.

---

# 16. Competitor / riferimento di mercato

## SAST / AppSec

- Semgrep
- Snyk
- GitHub Code Security / CodeQL
- Aikido

Questi sono molto forti su:

- static analysis;
- dependency scanning;
- secret detection;
- CI integration.

Lailaps non dovrebbe competere principalmente sulla velocità o sulla quantità di finding.

## Dynamic / Agentic Security

Più vicini concettualmente:

- StackHawk / Wingman
- XBOW
- Snyk offensive/agentic security products

L'area di differenziazione più interessante per Lailaps è:

> **white-box knowledge + autonomous runtime exploitation + permanent security regressions**

---

# 17. Posizionamento / messaging possibile

Messaggi emersi durante la discussione:

> **We don't report what might be vulnerable. We prove what is exploitable.**

Poi, introducendo i regression test:

> **Every vulnerability becomes a regression test.**

Sintesi:

> **Find it. Prove it. Never ship it again.**

Per l'integrazione con coding agent:

> **Your coding agent writes the fix. Lailaps decides whether it's secure.**

---

# 18. Priorità suggerita di sviluppo

Ordine ragionevole:

```text
1. High-quality discovery
2. Reliable runtime confirmation
3. Judge / evidence quality
4. Security Regression Tests
5. CLI / API
6. GitHub App
7. MCP integration
8. Targeted verification after fixes
9. Optional Fixer / Verified Remediation
```

Il punto centrale è evitare di investire troppo presto nell'autofix.

Prima Lailaps deve diventare estremamente affidabile nel rispondere a:

> **Questa vulnerabilità esiste davvero?**

Poi:

> **Questa patch l'ha realmente eliminata?**

---

# 19. Visione sintetica

La possibile evoluzione di Lailaps non è semplicemente:

> scanner AI più intelligente.

È qualcosa di più vicino a:

> **security verification layer per sviluppatori e coding agent.**

Lailaps:

```text
discovers
  ↓
proves
  ↓
turns vulnerabilities into tests
  ↓
verifies future fixes
  ↓
guards future commits
```

In questo modello:

- GitHub diventa il punto di enforcement;
- i coding agent diventano possibili autori delle patch;
- la webapp diventa il control plane;
- Lailaps rimane l'autorità indipendente che stabilisce se una security property è rispettata.

Questa separazione potrebbe diventare una delle caratteristiche più forti e difendibili del progetto.
