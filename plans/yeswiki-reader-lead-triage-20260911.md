# Triage offline delle lead Reader YesWiki

## Metodo

Il triage usa esclusivamente le 31 lead persistite nelle quattro run post-fix pricing. Le lead sono state deduplicate per sink, sorgente e meccanismo, non soltanto per titolo. `Unmatched` significa soltanto che il finding non corrisponde a un caso del manifest benchmark: non equivale a `false positive`.

Le classificazioni `plausibile` e `debole` non sono verdetti. Mancano la verifica completa dei caller, delle ACL, della configurazione effettiva e una conferma runtime.

## Sintesi

- Lead emesse: **31** (16 Security, 15 Injection).
- Ripetizioni dello stesso sink tra run: **10**.
- Ipotesi distinte dopo deduplica: **21**.
- Finding noti effettivamente descritti dal Reader: **3 ipotesi distinte** (`CVE-2026-52773`, `CVE-2026-52763` e `CVE-2026-52775`), presenti in 5 emissioni. Il valutatore ne ha accreditate solo 3, perche le due emissioni di `CVE-2026-52775` sono rimaste `unmatched`.
- Ipotesi nuove con un sink concreto e meritevoli di conferma: **circa 7**.
- Varianti/route dello stesso difetto, più utili come enrichment che come lead autonome: **2-3**.
- Ipotesi deboli, probabilmente non sfruttabili o a valore di sicurezza molto basso: **circa 6**.
- Restanti casi: configuration/design risk o controllabilità ancora troppo incerta.

Quindi non e corretto dire che tutte le lead unmatched siano sprecate. E pero corretto dire che il Reader sovraproduce: circa un terzo delle ipotesi distinte e debole o duplicativa. Tre ipotesi sono supportate dall'oracolo corrente, anche se il matching automatico ne riconosce soltanto due.

## Security misconfiguration / XSS

| Ipotesi deduplicata | Valutazione offline | Motivo |
|---|---|---|
| `$_GET['time']` in attributo hidden, `handlers/page/show.php:49` | **Finding noto** | Corrisponde a `CVE-2026-52773`; sink e input diretto sono evidenti. |
| Markdown image `src` non escapato, `formatters/wakka.php:422` | **Plausibile forte** | Input di markup finisce in un attributo HTML senza escaping. Da verificare ACL di edit e sanitizzazione downstream. |
| Markdown extra attributes verso `LinkTo`, `includes/YesWiki.php:643` | **Plausibile forte** | Serializzazione `key="$value"` senza escaping; il parser sembra consentire attributi controllati. Serve verificare la grammatica effettiva. |
| Raw HTML `""...""` con `allow_raw_html` | **Rischio di configurazione/design** | Il comportamento e intenzionale e condizionato da config/ACL. Puo essere una misconfiguration pericolosa, non automaticamente una vulnerabilita software. |
| `coloration_delphi`/Highlighter emette token raw | **Plausibile, reach incerta** | Sink concreto, duplicato nelle due run. Occorre provare che il formatter sia registrato e riceva `<` non pre-escapato. |
| Re-decoding di entita dopo `htmlspecialchars` | **Probabile falso positivo** | Un'entita che decodifica in `<` durante il parsing HTML non viene reinterpretata come apertura di un nuovo tag nello stesso passaggio del tokenizer. |
| `page/render` fa `strip_tags` e poi `Format()` | **Variante, non root cause autonoma** | Puo rendere riflessi i difetti del formatter Markdown, ma il sink reale resta l'escaping errato nel formatter. Va collegato alle lead image/attributes. |
| Formatter `raw.php` legge ed emette un file/URL | **Plausibile, scope da chiarire** | Potenziale disclosure/SSRF/XSS, ma servono dispatch, restrizioni path/URL e trust boundary. Non va scartato. |
| Search phrase di `newtextsearch` reinserita in `Format()` | **Plausibile** | Sorgente apparentemente utente e output wiki-rendered; exploit dipende dalla grammatica e da `allow_raw_html`. |
| Parametro `user` di `listpages` passato a `Format()` | **Variante/lead debole** | E principalmente un'altra route verso gli stessi formatter; controllabilita e sintassi exploit non sono dimostrate. |
| Username stored passato a `Format()` in `recentchanges` | **Debole / probabile non sfruttabile** | Richiede che la validazione username consenta markup pericoloso; la lead non lo dimostra. |

## Injection

| Ipotesi deduplicata | Valutazione offline | Motivo |
|---|---|---|
| `period` -> `$minDate` in `PageManager::getRecentlyChanged` | **Finding noto** | Corrisponde a `CVE-2026-52763` in entrambe le run. |
| `$extraSQL` di ReactionManager verso `TripleStore::delete` | **Finding noto non accreditato** | Descrive `CVE-2026-52775` e il path autenticato delle reactions. Il matcher sembra perderlo perche la primary location e `TripleStore.php:313`, mentre l'anchor del manifest e `ReactionManager.php:326-359`. |
| Needle/REGEXP di `newtextsearch` | **Plausibile forte** | L'escaping regex descritto non equivale all'escaping del literal SQL. Serve confermare che quote e commenti raggiungano il sink. |
| `$dateMin` in `listusers` | **Plausibile forte** | Pattern quasi identico al CVE noto: parametro `period` fuori allowlist concatenato in una stringa SQL. Da verificare `GetParameter` e reachability. |
| `$_POST['from']` in `INTERVAL ... hour` di `despam` | **Tecnicamente plausibile, basso valore** | L'escape per stringhe non protegge un contesto numerico; tuttavia il ramo e admin-only e l'impatto utile a un attaccante e dubbio. |
| `$forcedDate` in `PageManager::save` | **Incerta** | Sink non parametrizzato reale, ma la lead non prova che il valore superi la validazione server-side della data. |
| `$limit` in `getRevisions` | **Debole / probabile falso positivo** | I caller indicati sembrano usare configurazione o valori numerici sanitizzati; manca una sorgente HTTP non castata. |
| `$dbFields` in `UserManager::getAll` | **Debole / probabile falso positivo** | La lead non dimostra che il parametro PHP sia bindato dalla request; inoltre l'endpoint e admin-only. |
| Username/gruppo in SQL ACL | **Debole** | Serve dimostrare che nomi con quote siano registrabili; senza questo la sorgente non e controllabile. |
| Page tag stored in restore `despam` | **Debole / probabile falso positivo** | Second-order e admin-only; dipende dalla possibilita di memorizzare un tag contenente quote, non dimostrata e verosimilmente vietata. |

## Duplicazione e spreco reale

Le ripetizioni tra run non sono di per se un male: dimostrano stabilita del modello. Sono pero spreco se vengono persistite o finanziate come lead nuove nello stesso benchmark aggregato.

Duplicati stabili osservati:

- Security: Markdown image, Markdown attributes, raw HTML, coloration Delphi e route `page/render` compaiono in entrambe le run.
- Injection: `$minDate`, `$limit`, `$extraSQL` e i due sink `despam` compaiono in entrambe le run.

Lo spreco maggiore non e solo la duplicazione. E la mancata conversione: il Reader raggiunge diversi anchor corretti, ma spende il budget producendo molte ipotesi laterali senza chiudere i casi gia vicini all'oracolo. In Injection, 7-8 lead per run producono sempre un solo TP.

## Verdetto

Le lead non sono tutte sprecate. La lettura piu prudente e:

- **3/21** sono rilevanti per il benchmark corrente; uno dei tre e un falso negativo del matcher.
- **circa 7/21** sono candidati tecnicamente sensati fuori dall'oracolo e meritano Confirmer o un test mirato.
- **circa 2-3/21** dovrebbero essere accorpati come route/varianti di una root cause.
- **circa 6/21** hanno evidenza di controllabilita troppo debole o un errore concettuale e sono verosimilmente rumore.
- **2-3/21** restano rischi dipendenti da configurazione o validazione non osservata.

Non e possibile trasformare questi numeri in precisione reale senza ampliare il manifest o far passare le ipotesi nuove da Confirmer. Il benchmark attuale misura bene il richiamo dei CVE noti, ma sottostima esplicitamente la possibile novel discovery.
