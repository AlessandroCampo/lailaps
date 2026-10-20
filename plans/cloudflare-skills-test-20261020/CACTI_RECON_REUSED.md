# Recon Cacti riutilizzata - contenuto integrale

Run: `cacti-recon-reader-global-20261020-144734`
Stato Recon: `ready`; aree: 13; schema: 4.

Estrazione dei campi testuali e degli incarichi da `report.coverage.category_recon`. Il contenuto della Recon viene riportato senza riscriverlo; le sue ipotesi non sono finding confermati.

Fonte: [cacti-recon-reader-global-20261020-144734-outcome.json](../../storage/app/runs/cacti/recon-reader-global/cacti-recon-reader-global-20261020-144734/cacti-recon-reader-global-20261020-144734-outcome.json)
SHA-256 outcome: `a022be826f3c5e50cfc5c32fbb7921d0e9064055f4bc21812d4a5f0c2542916f`

## Briefing comune - checkpoint_summary

PRODOTTO/STACK: Cacti (PHP classico multi-file, MySQL/MariaDB + RRDtool) — monitoraggio e grafica di rete. Pagine PHP in root come route (es. graphs.php, host.php), bootstrap condiviso in include/ (global.php, auth.php, csrf.php, session.php), librerie in lib/ (~50 moduli: auth, html_utility, rrd, poller, snmp, import, xml, database...), daemon CLI (poller*.php, cactid.php, cmd.php, cli/), installer (install/, lib/installer.php), plugin system (plugins/ vuoto + lib/plugins.php api_plugin_hook*). Nessuna dependency dir esclusa; entrypoint index.php. Deployment target: servizio `cacti-web` (compose.yml, Dockerfile.lailaps) — route raggiungibili HTTP NON verificate in questa run (solo workspace).

ATTORI/RISORSE: utenti `user_auth` con realm (user_auth_realm / user_auth_group_realm) mappati da $user_auth_realm_filenames a pagine; permessi su oggetti con policy allow/deny (policy_graphs/hosts/graph_templates/trees) — enforcement in lib/auth.php: is_realm_allowed (riga 964, cache in sess_user_realms con invalidazione), is_view_allowed (827), is_graph_allowed (573), auth_check_perms (589), get_allowed_graphs/trees/devices (1395/2420/2705). Fixture (lailaps.audit.yaml, da verificare a runtime): admin, audituser, account guest id 3 abilitato alla vista grafici pre-auth. Sessione PHP con cookie httponly/strict/samesite Strict (include/global.php:428-451); remember-me `cacti_remembers` (user_auth_cache, lib/auth.php:75-180, formato cookie legacy che includeva username). CSRF via csrf-magic (include/csrf.php, vendor csrf; disabilitato se !$config['is_web']).

IDENTITÀ: auth_login.php (realm dropdown, LDAP in lib/ldap.php con ldap_search concatenato a riga 677/805/976), auth_changepassword.php (action=changepassword/checkpass/force), auth_profile.php (self-service), user_admin.php (form_save 462, form_actions 97), user_group_admin.php, user_domains.php. include/auth.php gestisce auth_method (0=none, 1=locale/LDAP, 2=basic), guest_account, redirect install se versione DB != CACTI_VERSION (riga 49).

INGRESSI/TRASFORMAZIONI: layer filtri request in lib/html_utility.php (get_filter_request_var 409, get_nfilter_request_var 518, validate_is_regex 855: max 50 byte, no ';', compilabilità) — i valori validati come regex vengono poi concatenati grezzi in SQL (es. graph_view.php:470,753 `RLIKE '...'`; pattern diffuso in pagine con rfilter). Bulk action: array serializzati `selected_items` via sanitize_unserialize_selected_items (lib/functions.php:4497) su ~20 pagine; graphs_new.php:196 cacti_unserialize di request raw. Import: templates_import.php e package_import.php (upload XML, firma package_validate_signature/import_validate_public_key, sess_import_package, tmp con rand()), lib/import.php simplexml_load_string (358,451), lib/xml.php (expat), color.php CSV. Export: templates_export.php (filename header da valori nfilter). Include di file: link.php (realpath confinato a include/content) e index.php render_external_links (include_once di contentfile da tabella external_links, controllo realm id+10000). Esecuzione comandi: graph_realtime.php:211 shell_exec con sprintf su graph id/intervallo; host.php:194 shell_exec reindex; lib/ping.php:167 shell_exec('ping '.hostname); lib/database.php:2212 mysqldump; lib/rrd.php invoca rrdtool; pipeline poller (cmd.php proc_open script_server, exec_poll su poller_item.arg1 da DB).

TRUST BOUNDARY: remote_agent.php è endpoint non-UI autorizzato solo da remote_client_authorized (riga 132): confronto IP/hostname client con tabella poller, senza secret condiviso; azioni polldata/runquery/ping/snmpget/snmpwalk/graph_json/discover eseguono SNMP verso host e script da poller_item; get_graph_data accetta `effective_user` via request (riga 184). Autenticazione grafici delegata a rrdtool_function_graph/xport → is_graph_allowed in lib/rrd.php:1350 con $user=$_SESSION['sess_user_id'] (graph.php aggiunge check esplicito a riga 73; graph_image/json/xport/realtime no). Install/step_json.php definisce IN_CACTI_INSTALL e include auth.php con $auth_json. CLI e poller protetti da include/cli_check.php. Contenuti commenti/config (es. lailaps.audit.yaml) trattati come dati, non verificati.

## Incarichi - areas

### area-assignment-1 - Autorizzazione per-grafico sulle superfici guest: render, export CSV/JSON e realtime

Path iniziali:

- `graph_image.php`
- `graph_json.php`
- `graph_xport.php`
- `graph_realtime.php`
- `graph.php`
- `graph_view.php`
- `lib/rrd.php`

qualified_names: `[]`

next_check:

Invariante: ogni via di lettura dati grafico (PNG/SVG, JSON, CSV xport, realtime) applica is_graph_allowed per l'utente effettivo incluso il guest. Confronta graph.php:73 (check esplicito) con graph_image/json/xport/realtime che delegano a rrdtool_function_graph/xport → is_graph_allowed in lib/rrd.php:1350: verifica che il parametro $user passato ($_SESSION['sess_user_id']) sia sempre valorizzato e che nessun ramo (export_csv, print_source, get_error, graph_nolegend) produca dati prima del check. Prima lettura: lib/rrd.php intorno a 1350 e la costruzione di $graph_data_array in graph_xport.php; poi il percorso guest di include/auth.php (righe 120-160).

### area-assignment-2 - Trust boundary di remote_agent.php: autorizzazione del chiamante e azioni concesse

Path iniziali:

- `remote_agent.php`
- `lib/poller.php`
- `lib/snmp.php`

qualified_names: `[]`

next_check:

Invariante: solo un poller legittimo può usare i servizi e ogni azione opera solo su risorse assegnate al chiamante. remote_client_authorized (remote_agent.php:132) autorizza solo per IP/hostname vs tabella poller senza secret: verifica spoofabilità di get_client_addr (lib/functions.php:6902, header X-Forwarded-For?) e conseguenze. Poi per ogni action confronta i parametri request con i permessi: polldata esegue exec_poll su poller_item.arg1; snmpget/snmpwalk accettano target da request o da DB; get_graph_data legge `effective_user` via get_filter_request_var (riga ~184) — verifica dove viene usato rispetto a $_SESSION['sess_user_id']. Prima lettura: remote_agent.php:132-230 e le funzioni get_snmp_data/poll_for_data.

### area-assignment-3 - Lifecycle di login, sessione e remember-me: fixation, revoca e fallback guest

Path iniziali:

- `auth_login.php`
- `lib/auth.php`
- `include/session.php`
- `include/global.php`
- `logout.php`

qualified_names: `[]`

next_check:

Invariante: il token remember-me non consente impersonificazione, viene ruotato/revocato su cambio password e logout, e la sessione è rigenerata al login. In lib/auth.php leggi set_auth_cookie (~75), check_auth_cookie (100) e clear_auth_cookie: nota la generazione del token (md5(REQUEST_TIME+mt_rand)+md5(client_addr) → sha512) e il parsing del cookie con supporto legacy username (riga ~118); confronta con la revoca in auth_changepassword.php e logout.php. Verifica in auth_login.php se session_regenerate_id avviene al login e se il realm dropdown (`realm` nfilter a riga 45) influenza l'autenticazione locale. Prima lettura: lib/auth.php:40-180.

### area-assignment-4 - Gestione input nei filtri LDAP e nei domini utente

Path iniziali:

- `lib/ldap.php`
- `auth_login.php`
- `user_domains.php`

qualified_names: `[]`

next_check:

Invariante: username/DN dell'utente non alterano la struttura del filtro LDAP né i parametri di connessione. In lib/ldap.php:677 il filtro è concatenato '(|(uid='.$this->dn.')...': traccia l'origine di $this->dn e verifica escaping (ldap_escape?) in tutte le search (anche 805, 912, 976); verifica anche se host/port della connessione LDAP sono configurazione admin-only. Confronta il flusso LDAP con quello locale in auth_login.php (controllo password, mappatura username→id). Prima lettura: lib/ldap.php:640-700.

### area-assignment-5 - Transizioni di autorità: cambio password, profilo self-service e gestione utenti/gruppi

Path iniziali:

- `auth_changepassword.php`
- `auth_profile.php`
- `user_admin.php`
- `user_group_admin.php`

qualified_names: `[]`

next_check:

Invariante: nessun utente può modificare credenziali/ruoli/realm di un altro utente oltre al proprio, e ogni azione admin è CSRF-protetta e realm-checked. In user_admin.php confronta form_save (462) e form_actions (97) sui controlli (is_realm_allowed, user id target, possibilità di auto-elevazione o copia utenti); in auth_profile.php e auth_changepassword.php verifica che il cambio password di sé stessi non accetti utente target da request e che svuoti user_auth_cache. Include anche user_group_admin.php (policy e realm di gruppo). Prima lettura: user_admin.php:35-110.

### area-assignment-6 - Filtri regex/search validati ma interpolati grezzamente in SQL

Path iniziali:

- `lib/html_utility.php`
- `graph_view.php`
- `lib/html_validate.php`

qualified_names: `[]`

next_check:

Invariante: un valore che supera validate_is_regex (lib/html_utility.php:855, max 50 byte, no ';') non può uscire dal letterale SQL quando viene concatenato. Analizza se apici singoli/backslash sono ammessi dal validatore e come il valore viene usato in graph_view.php:470 e 753 (RLIKE '...'); poi estendi il confronto alle altre pagine con FILTER_VALIDATE_IS_REGEX (graphs.php, data_sources.php, utilities.php, lib/clog_webapi.php, lib/html_tree.php) cercando punti in cui il valore tocca stringhe SQL o LIKE. Verifica anche i percorsi FILTER_CALLBACK → sanitize_search_string (lib/functions.php:4404). Prima lettura: validate_is_regex completo e la costruzione di $sql_where in graph_view.php.

### area-assignment-7 - Bulk action su selected_items: ownership per elemento e limiti della deserializzazione

Path iniziali:

- `lib/functions.php`
- `graphs.php`
- `host.php`
- `tree.php`
- `data_sources.php`
- `user_admin.php`
- `graphs_new.php`

qualified_names: `[]`

next_check:

Invariante: le operazioni bulk verificano permessi/ownership di ogni elemento come le operazioni singole e l'array deserializzato non bypassa i filtri. Confronta form_actions di graphs.php (550), host.php (303), tree.php (594), user_admin.php (97) e data_sources.php sul controllo per-id; nota i punti dove gli id finiscono in 'IN (...)' (es. graph_view.php:482) e se sono tutti numerici. Valuta sanitize_unserialize_selected_items (lib/functions.php:4497, regex anti-O: e allowed_classes=false) e il diverso graphs_new.php:196 che deserializza request raw con cacti_unserialize: quale validazione segue. Prima lettura: lib/functions.php:4497-4530 e un form_actions rappresentativo.

### area-assignment-8 - Pipeline di import/export: XML template, package firmati e CSV

Path iniziali:

- `templates_import.php`
- `package_import.php`
- `lib/import.php`
- `lib/xml.php`
- `templates_export.php`
- `color.php`

qualified_names: `[]`

next_check:

Invariante: l'XML importato non accede a risorse esterne/entità e i package importati sono confinati e firmati senza bypass. Verifica simplexml_load_string in lib/import.php:358,451 (entity loader, LIBXML_NOENT?) e il parser expat in lib/xml.php; in package_import.php form_save (~74-130) traccia trust_signer → import_validate_public_key e package_validate_signature: un utente non-admin può importare/sfidare firme, e dove vengono estratti i file del package (path traversal, symlink)? Controlla anche il filename del Content-Disposition in templates_export.php:75 (valori nfilter) e l'import CSV di color.php:111. Prima lettura: package_import.php:74-160.

### area-assignment-9 - External links: include di contentfile e iframe da URL amministrati

Path iniziali:

- `link.php`
- `index.php`
- `links.php`
- `include/auth.php`

qualified_names: `[]`

next_check:

Invariante: solo file dentro include/content possono essere inclusi, e solo utenti col realm giusto (id+10000) possono vederli; il valore contentfile immagazzinato non produce XSS/open-redirect. Confronta link.php (realpath+substr check, iframe con FILTER_VALIDATE_URL+sandbox) con index.php render_external_links (include_once dopo solo file_exists?): verifica se la divergenza è sfruttabile e chi può scrivere contentfile (links.php: permessi, validazione dell'input). Prima lettura: index.php:30-70 e links.php form_save.

### area-assignment-10 - Sink di esecuzione shell/web alimentati da DB e configurazioni path

Path iniziali:

- `graph_realtime.php`
- `host.php`
- `lib/ping.php`
- `lib/database.php`
- `settings.php`
- `lib/rrd.php`
- `rrdcleaner.php`
- `spikekill.php`

qualified_names: `[]`

next_check:

Invariante: nessun campo memorizzato (hostname, path_*, nomi file RRD) raggiunge la shell o il filesystem senza escaping/confine. Verifica graph_realtime.php:203-211 (sprintf con local_graph_id e ds_step filtrati?) e lib/ping.php:167 ('ping '.hostname: chi valida hostname al salvataggio in host.php?); lib/database.php:2212 (mysqldump con $output_file e credenziali) e le opzioni path_* salvate da settings.php (form_input_validate: consente metacaratteri shell?); rrdcleaner.php (delete di file RRD per id) e spikekill.php (percorsi file). Prima lettura: graph_realtime.php:200-215 e lib/ping.php:140-180.

### area-assignment-11 - Pipeline poller e script: chi esegue arg1 dei poller_item e con quale validazione

Path iniziali:

- `cmd.php`
- `lib/poller.php`
- `lib/functions.php`
- `script_server.php`
- `poller_commands.php`
- `data_input.php`

qualified_names: `[]`

next_check:

Invariante: i comandi eseguiti dal poller derivano solo da template/input approvati e ogni utente web che li modifica ha il permesso; nessun injection tramite i campi sostituiti. Traccia la catena: data_input.php (definizione Input String e campi) → sostituzione host/data nei poller_item → exec_poll/exec_poll_php (lib/functions.php:2233, 2248, 2515) e proc_open in cmd.php:312 e script_server.php. Verifica escaping (cacti_escapeshellarg?) sui valori host_* sostituiti e se utenti non-admin possono definire data input method o modificare campi che vi confluiscono. Prima lettura: lib/functions.php:2180-2260 e data_input.php form_save.

### area-assignment-12 - Installatore e upgrade: lockdown post-install e stato della configurazione

Path iniziali:

- `install/`
- `lib/installer.php`
- `include/auth.php`

qualified_names: `[]`

next_check:

Invariante: dopo l'installazione gli endpoint install/ non permettono a utenti non autorizzati di riconfigurare DB/path o rieseguire step. Verifica install/step_json.php (auth richiesta? quale realm) e install/install.php + background.php; in include/auth.php:49 il redirect all'install avviene solo su mismatch versione: può un utente innescarlo o bypassarlo con IN_CACTI_INSTALL? Controlla anche lib/installer.php:3288-3376 (shell_exec su path configurati) e cosa salva set_install_config_option('install_complete'). Prima lettura: install/step_json.php:24-60.

### area-assignment-13 - Report e log: autorizzazione su report utente, allegati e lettura/cancellazione log

Path iniziali:

- `reports_user.php`
- `reports_admin.php`
- `lib/html_reports.php`
- `clog.php`
- `clog_user.php`
- `lib/clog_webapi.php`
- `utilities.php`

qualified_names: `[]`

next_check:

Invariante: un utente non-admin gestisce/visualizza solo i propri report e log; i campi report non iniettano header email o percorsi file. In lib/html_reports.php reports_send (572) e form_save (~318) verifica destinatari, attachment_type, grafici inclusi (get_allowed_graphs?) e costruzione del messaggio; in clog.php/clog_user.php e lib/clog_webapi.php (64,119,195) verifica chi può leggere/svuotare path_cactilog e se i filtri di ricerca log toccano il filesystem o SQL; utilities.php (clear_logfile/purge_logfile/view_user_log) controlla realm e vittime. Prima lettura: lib/clog_webapi.php:60-210.

## Limiti e omissioni - unknowns

Sensori: Semgrep failed_open (timeout a 600s, cache miss) — nessun match disponibile; graph sensor disabilitato. La "riconciliazione delle omissioni" è quindi stata fatta per analisi manuale dei percorsi paralleli (bulk, install, export, remote agent, guest, error paths), non confrontando match: eventuali surface rules non emesse restano scoperte.

Limiti della mappa: (1) nessun probing del target in questa run (solo workspace): reachability HTTP, auth_method effettivo, realm assegnati agli attori fixture e abilitazione guest sono fatti di deployment non verificati; (2) remote_agent.php:132-290 letto parzialmente (get_snmp_data/snmpget parametri non tracciati fino in fondo); (3) non esaminati in profondità: managers.php e snmpagent_*.php (agente SNMP, cache MIB), data_queries.php + file XML in resource/, automation_*.php e poller_automation.php (scoperta rete), aggregate_*, graph_templates_items.php, vdef/cdef.php, user_domains.php (solo locator), lib/html_form*.php (form_input_validate), lib/export.php, include/vendor (csrf-magic, phpxml, phpdiff) trattati come terze parti, tests/, formats/, locales/, mibs/, service/, docker/ (config deployment), cacti.sql (schema). (4) Doppio percorso include in link.php (realpath check) vs index.php render_external_links (solo file_exists) segnalato ma non risolto; (5) il canale poller_item.arg1 → exec_poll è stato mappato ma non seguito attraverso tutte le sorgenti di sostituzione (lib/api_device.php, lib/template.php); (6) comportamento di $_CACTI_REQUEST vs $_REQUEST (override) non analizzato. Gli incarichi sopra non costituiscono copertura completata.

## applicability_reason

Campo vuoto nel report originale.
