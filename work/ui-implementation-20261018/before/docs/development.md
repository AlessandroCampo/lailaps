# Database di sviluppo

Laravel usa PostgreSQL 16 in Ubuntu/WSL. Il servizio Windows esistente resta
separato. Il cluster WSL usa la porta 5434 e ascolta soltanto su localhost.

Configurazione locale di sviluppo in .env e .env.example:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5434
DB_DATABASE=lailaps_dev
DB_USERNAME=lailaps_dev
DB_PASSWORD=lailaps_dev
```

Queste credenziali sono destinate esclusivamente allo sviluppo locale.

Il servizio parte con WSL/systemd. Per avviarlo manualmente da Ubuntu:

```bash
sudo systemctl start postgresql@16-main
pg_lsclusters
```

Per ricreare lo schema di sviluppo, dalla root del progetto:

```bash
/home/alessandro/.config/herd-lite/bin/php artisan config:clear
/home/alessandro/.config/herd-lite/bin/php artisan migrate:fresh --seed
```

Il reset elimina i record del database di sviluppo. Il seeder crea l'account
test@example.com con password password. Gli snapshot sul disco projects sono
separati dal database e non vengono eliminati da migrate:fresh.

In DBeaver su Windows, crea una connessione PostgreSQL con host localhost,
porta 5434, database lailaps_dev, utente lailaps_dev e password lailaps_dev.
WSL deve essere in esecuzione. Usa Test Connection, poi Finish.

I test PHPUnit mantengono la connessione SQLite in memoria definita in
phpunit.xml; non utilizzano il database PostgreSQL di sviluppo.

## Env Doctor and sandbox verification

Enable Docker Desktop WSL integration for Ubuntu. Docker and Docker Compose must
be available to Laravel. Use the configured PHP 8.5 runtime and Node.js 24.

```bash
php artisan migrate
npm --prefix agent run build
docker build -t lailaps-toolbox:dev agent/docker
php artisan serve
```

Keep the Laravel HTTP server running at `APP_URL` (default
`http://localhost:8000`). The runtime uses signed internal session endpoints.
In another terminal:

```bash
php artisan agent:test
php artisan agent:test 2 --keep
php artisan agent:test --session=<session-id>
php artisan sandbox:stop <session-id>
```

A real Doctor run uses the configured `OPENROUTER_API_KEY` and `AI_MODEL` and incurs
provider costs. It is not an automatic test. The command prints the session ID and
artifacts directory, runs up to three candidate/build/readiness attempts, and stops
on readiness success or failure. A failed health check feeds redacted service state
and logs back into the same database conversation. Provider/process failures stop
immediately and retain the last completed step. Only one worker may own a session.

To verify an existing candidate without any model calls:

```bash
php artisan agent:test 2 --artifacts=/absolute/path/to/doctor/session/artifacts
```

The artifacts must have a sibling `workspace` directory under that project's Doctor
storage. The manifest must declare generated variables in `environment`, for example
`{"LAILAPS_APP_KEY":"base64_32","LAILAPS_DB_PASSWORD":"secret"}`. Actor
`password_ref` variables are generated automatically. Ordinary values/defaults belong
in Compose. Generated values are encrypted in the database and retained across repair
attempts; they are not written to an env file or sent through the model session API.
Existing recipes that omit required variable declarations fail with Compose diagnostics.

The runner builds the Compose services, waits for dependency startup and checks every
manifest readiness path against its exact status and optional body marker. At least
one check must require a 2xx response and a non-empty application-specific marker.
`bootstrap_file` identifies a script wired into Compose; it is not run a second time.
The runner publishes the declared HTTP port on a free loopback port and isolates
container, image, network and volume names per session. Target applications still
need compatible application URL configuration when they depend on an external origin.

By default both stacks are removed after verification. `--keep` retains a successful
target for inspection; the toolbox is always removed. Use `sandbox:stop` before pruning
sessions that own retained stacks. Other expired terminal sessions can be pruned with:

```bash
php artisan model:prune --model='App\Models\AgentSession'
```

Console output shows readable text/reasoning, commands, stdout/stderr and step
summaries. Use `--ansi` or `--no-ansi` to control colors. Provider signatures remain
in the persisted SDK messages but are omitted from console output. Output is shown
per complete model response, rather than streamed token by token.

The CLI remains diagnostic. Project creation queues `LabSetup`, which uses the same
`DoctorService` pipeline and marks the associated Lab ready only after health checks
pass. Neither entrypoint runs an audit, verifies actor login or promotes the manifest.

## Project environment queue

Run the Laravel HTTP process, CLI and queue workers with the same Linux UID/GID
for the private projects disk. Application-managed project, artifact and Doctor
directories are prepared through `ProjectService::prepareDirectory` using relative
disk paths, private visibility (0700) and an ownership/writability check. Project
creation prepares its artifact base before dispatch; each producer prepares its
own output directory before mounting it. Copied Doctor directories are private too.
Foreground analysis containers and the Doctor toolbox run as that worker UID/GID;
the foreground runner defaults HOME to /tmp. Application containers from Doctor
recipes keep their image-specific users and isolated runtime storage requirements.
Runtime code does not use sudo/chown to repair storage created by another account.

Project creation queues both `LabSetup` and `PrepareProjectAnalysis` after commit.
The latter runs `StaticAnalysisService`, `CodeIndexService`, `DependencyAnalysisService` and `ReconService` in that
order for the exact uploaded source, independently of Doctor readiness, and accepts
archived sources whose snapshots remain stored. Recon waits for both sensor outcomes;
failed sensors remain optional inputs rather than aborting the pipeline.
The status page keeps loading until Doctor and all four artifacts finalize. It shows
ready for a usable Recon result, including partial results with advisory limitations;
Doctor/Recon/queue failures show a generic setup error. Individual producer details
remain in the database and are not displayed in the UI.
One worker processes the jobs sequentially; multiple workers can process them in
parallel. Static analysis itself does not invoke an LLM.

The worker needs Docker and the pinned Semgrep image. Download it during deployment:

```bash
docker pull semgrep/semgrep:1.175.0@sha256:b94b53d02fd4a022f9eac4e2af1380f5c3c4c21400e79d3336bdff1d1db5e796
```

Rules are versioned in `app/Features/ProjectAnalysis/rules`; scanning requires no
registry access. Results are stored as `static_analysis` artifacts, with producer
`semgrep` and format `semgrep-json` in metadata. Ready results are reused; failed or
partial results retry on the same record. The analysis job has one queue attempt,
a 3600-second timeout, 600-second sensor process timeouts and an 1800-second Recon
process timeout. Database queue
`retry_after` must exceed the job timeout; the existing 7260-second default covers
both preparation jobs. Use an asynchronous queue connection. Source reuse does not
automatically invalidate results when rules or the engine change.

Keep the HTTP server, Docker and the built agent runtime available, then run:

```bash
php artisan queue:work --tries=1 --timeout=7200
```

`php artisan dev` already runs the queue listener alongside the HTTP and Vite servers.
Restart existing workers/listeners after changing queue configuration. The job has
its own timeout (7200 seconds for Lab, 3600 for analysis) and one queue attempt. Database `retry_after`
defaults to 7260 seconds and must remain greater than the job timeout to prevent
concurrent delivery of a long-running job. Process/build/start/health timeouts remain
bounded independently by the existing agent/sandbox configuration.

Creating a project now automatically incurs Doctor and Recon provider calls when a
worker consumes the respective jobs. Doctor preparation retries use the same session, files and diagnostic
history, up to `sandbox.attempts`; technical failures stop immediately. Exhausted
preparation remains failed without a client-triggered restart. The environment page
polls pending/running state and stops on ready/failed. Opening or refreshing the
page never starts a job. Queued output is visible in the worker terminal; conversation
and failure diagnostics remain in `agent_sessions`.

## Prepared Python Recon

The temporary Python package imported from Lailaps 1 `minimal` is in
`agent/recon-python`. Build, mounts, input descriptor, offline validation and Laravel
launch/output instructions are in [python-recon-integration.md](python-recon-integration.md).
`ReconService` stages prepared sensor artifacts and persists the outcome through the
shared artifact lifecycle. `PrepareProjectAnalysis` invokes it after both sensors finish.

