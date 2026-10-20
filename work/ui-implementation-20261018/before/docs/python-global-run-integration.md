# Launch a prepared global run from Laravel

The Python adapter is `pentest_agent.prepared_run`. It runs the imported `minimal`
global pipeline with its existing Reader, unified investigator, budgets, tool
restrictions, actor sessions, evidence and report publication. It imports a persisted
Recon map and never regenerates Recon, Semgrep, OSV or the code index. The existing
default Docker entrypoint still runs Recon only.

The Laravel smoke-test entrypoint is now `php artisan audit:run <source-ulid>`.
It creates an AuditRun, freezes its inputs, starts the verified target, invokes
Python through DockerService, preserves its output directory, records the final
status and tears down the target. Python never writes the application database.
After cleanup, `OutcomeService` imports the validated terminal DTO, areas and leads
into the database. `RunAuditAction` now owns this lifecycle; the smoke-test command
and `RunAudit` queue job both call it. The project/source POST endpoint creates and
queues a pending run. See [audit-execution.md](audit-execution.md) for entrypoints
and the compact project-show prop.
See [audit-smoke-test.md](audit-smoke-test.md) for the implemented lifecycle.

## 1. Build the image in WSL

From the Lailaps 2 project root:

```bash
docker build -t lailaps-agent:minimal-prepared-global-ulid agent/recon-python
```

This uses the existing `lailaps-code-index:0.12.1` image. Source files, prepared
artifacts, target credentials and provider keys are supplied at execution time.
Set `TOOLBOX_IMAGE=lailaps-agent:minimal-prepared-global-ulid` on the runtime too: its
source-only shell containers use this image and remain without network, credentials
or Docker socket.

See [project-run-filesystem.md](project-run-filesystem.md) for the existing Doctor
paths, the target startup sequence and the host/container mount mapping. Source IDs
are now canonical ULID strings; analysis artifact IDs remain integers.

## 2. Prepare the files in your Artisan command

Accept a fixed source ID. Resolve every artifact and the successful Doctor session
from that source, rather than resolving the latest project again during execution.
Use a new run ID and private directories created by `ProjectService::prepareDirectory`:

```text
<run-directory>/inputs/run.json
<run-directory>/inputs/static_analysis.json
<run-directory>/inputs/dependency_analysis.json
<run-directory>/inputs/recon.json
<run-directory>/index/
<run-directory>/private/actors.json
<run-directory>/output/
```

Export the complete `ProjectArtifact.payload` as JSON for Semgrep, OSV and Recon.
`recon.json` is the entire Recon outcome, including `project_source_id`, `source_hash`,
`input_artifacts`, `preparation_status` and `report`; do not export only its map.
Use `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`, check each write, and hash the
exact exported bytes with SHA-256. Copy the entire finalized CBM artifact directory
into the run's writable `index` directory after indexing has stopped.

Recon must have status `ready` or `partial`, and its inner `category_recon` must be
`ready`. The adapter validates source identity and fingerprint, JSON checksums,
supplied sensor IDs against the Recon references, and the native CBM project/root.
Missing or failed optional sensors remain advisory; corrupt available inputs stop
the run before model admission. A partial Recon can be reused without another Recon
request, and remains visible as an input limitation.

## 3. Start the verified application recipe

`Lab::READY` records a successful verification; Doctor normally removes its stack.
Your command must start a target and own its teardown:

1. Read the ready Doctor session for the fixed source and its workspace/artifact paths.
2. Load the manifest using `SandboxService::manifest` and the session's encrypted
   environment. Preserve the same generated credentials used by bootstrap.
3. Configure and start the stack with `SandboxService::configure` and `start`, using
   the new run ID as the sandbox ID. Run `waitUntilHealthy` with the manifest checks.
4. Get the declared web container ID and one of its Docker networks. The network must
   allow the runtime to reach both the web container and the model provider.
5. Place `SandboxService::destroy` in `finally`, including paths that fail before or
   during agent execution. Python closes its own toolboxes and HTTP clients; it does
   not create or remove the application stack.

The web container must be running and have `lailaps.session=<sandbox ID>`.
`target_session_id` below is this exact ID: it is the new run ID if you passed that
to `SandboxService`, or the Doctor session ID when borrowing a retained Doctor stack.
The adapter preserves this ownership check and discovers sibling Compose services
under the same label, including database services.

The URL returned by `SandboxService::start` uses host loopback. It is suitable for
Laravel's health check, but `127.0.0.1` inside the agent container is the agent itself.
For Python use `http://<web-container-name>:<web_port>` on a shared Docker network.
You can obtain network names with:

```bash
docker inspect --format '{{json .NetworkSettings.Networks}}' <web-container>
```

## 4. Export private application actors

Resolve each manifest actor's `password_ref` against the encrypted session environment.
Write the secret-bearing profile to `private/actors.json`, outside the ordinary run
descriptor. Do not print it or persist plaintext credentials in run metadata.

```json
{
  "actors": [
    {
      "role": "admin",
      "username": "admin",
      "password": "<resolved generated password>"
    }
  ]
}
```

The existing actor loader requires `role`, `password`, and `username` or `email`.
Omit `actors_file` when no authorized actors are available. Use the same Linux
UID/GID for PHP and the runtime so mode-0700 directories remain readable.

## 5. Write the run descriptor

Paths refer to container mounts. IDs, hashes, project names and statuses come from
the selected source and artifacts. The example values are placeholders.

```json
{
  "schema_version": 1,
  "run_id": "audit-<unique-id>",
  "source_id": "01M57BE6CVRVVJ7V8SYSEP67RY",
  "source_hash": "<ProjectSource.content_hash>",
  "source_path": "/workspace/source",
  "source_host_path": "/home/alessandro/projects/lailaps-2/storage/app/private/projects/01M57BE6CVRVVJ7V8SYSEP67RX/sources/01M57BE6CVRVVJ7V8SYSEP67RY/files",
  "static_analysis": {
    "id": 10,
    "project_source_id": "01M57BE6CVRVVJ7V8SYSEP67RY",
    "status": "partial",
    "reason": "<artifact.error>",
    "path": "/inputs/semgrep.json",
    "sha256": "<SHA-256 of exported bytes>"
  },
  "code_index": {
    "id": 11,
    "project_source_id": "01M57BE6CVRVVJ7V8SYSEP67RY",
    "status": "partial",
    "reason": "<artifact.error>",
    "path": "/index",
    "project": "<code-index payload.project>",
    "engine_version": "0.12.1"
  },
  "dependency_analysis": {
    "id": 12,
    "project_source_id": "01M57BE6CVRVVJ7V8SYSEP67RY",
    "status": "ready",
    "path": "/inputs/dependencies.json",
    "sha256": "<SHA-256 of exported bytes>"
  },
  "recon": {
    "id": 13,
    "project_source_id": "01M57BE6CVRVVJ7V8SYSEP67RY",
    "status": "partial",
    "reason": "<artifact.error>",
    "path": "/inputs/recon.json",
    "sha256": "<SHA-256 of exported bytes>"
  },
  "target_url": "http://<web-container-name>:80",
  "target_container_id": "<web-container-id>",
  "target_session_id": "<sandbox ID used by Laravel>",
  "actors_file": "/private/actors.json",
  "reader_model": "z-ai/glm-5.3-flash",
  "lead_model": "z-ai/glm-5.3-flash",
  "reader_reasoning_effort": "high",
  "lead_reasoning_effort": "high",
  "point_limit": 1000000,
  "reader_points": 300000,
  "worker_points": 700000,
  "reader_concurrency": 1,
  "lead_concurrency": 1
}
```

`point_limit` is the pass's economic-point cap, not tokens or dollars. The optional
`reader_points` and `worker_points` use the existing global stage allowances; omitted
values retain legacy defaults. Concurrency defaults remain four Readers and two
investigators. There is one pass; per-role request/continuation limits remain the
legacy configuration. Recon's old `model`, `reasoning_effort` and `request_limit`
fields do not belong in this descriptor.

## 6. Implement the Process call

The implemented `DockerService::runAudit` delegates to `run`, including cleanup on
process exceptions. The command consumes the mounted outcome file and streams stderr
to its private runtime log. The standalone equivalent below uses absolute host paths
and the shared Docker network found in the preceding steps. Keep the configured
provider key out of command arguments:

```php
$result = Process::env([
    'OPENROUTER_API_KEY' => config('agent.openrouter_api_key'),
])->timeout(3600)->run([
    'docker', 'run', '--rm', '--init',
    '--name', $containerName,
    '--user', posix_geteuid().':'.posix_getegid(),
    '--group-add', (string) filegroup('/var/run/docker.sock'),
    '--network', $networkName,
    '--read-only',
    '--tmpfs', '/tmp:rw,nosuid,nodev,size=128m',
    '--env', 'OPENROUTER_API_KEY',
    '--env', 'TOOLBOX_IMAGE=lailaps-agent:minimal-prepared-global-ulid',
    '--mount', 'type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock',
    '--mount', "type=bind,src={$sourcePath},dst=/workspace/source,readonly",
    '--mount', "type=bind,src={$inputsPath},dst=/inputs,readonly",
    '--mount', "type=bind,src={$indexPath},dst=/index",
    '--mount', "type=bind,src={$privatePath},dst=/private,readonly",
    '--mount', "type=bind,src={$outputPath},dst=/output",
    '--entrypoint', 'python',
    'lailaps-agent:minimal-prepared-global-ulid',
    '-m', 'pentest_agent.prepared_run',
    '--prepared-inputs', '/inputs/run.json',
    '--output', '/output/outcome.json',
], function (string $type, string $text): void {
    if ($type === 'err') {
        fwrite(STDERR, $text);
    }
});
```

Give the container a unique application-controlled name. In your caller's `finally`,
force-remove that runtime container on process timeout/exception before destroying
the target stack. `--rm` removes a container that exits, but killing the Docker CLI
does not reliably stop the runtime. A hard kill may also leave a toolbox behind;
ordinary Python completion/errors use the existing executor cleanup.

For an initial validation, append `--check-inputs`. It verifies the source,
Semgrep/OSV, native CBM, frozen Recon and actor-file shape without model requests,
Docker access or HTTP requests. The target descriptor fields remain required, but
this mode does not verify target ownership, reachability or application readiness.

## 7. Read the result

Python atomically updates `/output/outcome.json` through the existing report store,
then emits exactly one final JSON object on stdout. Progress logs go to stderr.
`Process::run` launches the process; Python writes the mounted output file.

Transport fields are `schema_version`, `run_id`, `project_source_id`, `source_hash`,
`input_artifacts` (including Recon), `run_status`, `run_reason`, and the legacy `report`.

| Exit | `run_status` | Meaning |
| --- | --- | --- |
| 0 | `completed` | Final report with complete coverage and ready inputs |
| 0 | `partial` | Final report with incomplete coverage or prepared inputs |
| 0 | `validated` | `mode: check_inputs`; no audit was executed |
| 1 | `failed` | Invalid input, technical/model failure or fatal report |

Check process exit status as well as the JSON. If Docker or the process fails before
Python starts, stdout may not contain JSON. On exception the adapter retains the
last durable report when available; on an external timeout the file can still be
a legacy intermediate checkpoint without a final `run_status`. The caller must mark that execution failed/interrupted and
keep its diagnostics, rather than accepting a partial checkpoint as success.

The report preserves legacy findings, coverage, evidence and telemetry. Frozen Recon
provenance is in `report.global_pipeline.recon_reuse`, with zero new Recon requests.
An audit with no findings can be `completed`; partial input coverage does not mean
the runtime failed. Stage JSONL journals and other files beside the outcome remain
part of the run output and should be retained if you later persist a run record.

## Verification and reference

Offline validation exercises the real global scheduler with simulated agent
responses, native CBM queries, a temporary Docker target and source toolbox, HTTP
connectivity, source/checksum/ownership rejection and JSON failure output. No paid
agent run is required or executed by this integration task.

The [DeepSeek activation implementation at 687f282e](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/subagent/subagent/src/index.ts)
and [lifecycle contract](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/docs/subsystems/subagent.md)
were consulted for caller-owned execution and result delivery. This adapter keeps
the existing Lailaps global pipeline and does not introduce another scheduler,
retry layer or dependency on that harness.
