# Prepared audit smoke test

Run from the WSL project root:

```bash
php artisan audit:run <source-ulid>
```

This is synchronous; queue:work is not required. It makes real provider requests.
Use a source with a ready Lab and a usable Recon. The local runtime image is
`lailaps-agent:minimal-prepared-global-ulid`. The provider key comes from
`config('agent.openrouter_api_key')` / `OPENROUTER_API_KEY`.

The command is a thin caller of `RunAuditAction`, shared with the `RunAudit` job.
Edit models/budgets in `RunAuditAction::DEFAULT_CONFIGS`; there are no additional
CLI options. Creation rejects another pending/running audit on the same Lab because
the verified Doctor workspace is reused and can contain writable files.
Execution records `started_at` before workspace preparation and `finished_at` after
cleanup/import. See [audit-execution.md](audit-execution.md) for the queued route
and compact project-show data.

## Lifecycle after workspace preparation

1. `SandboxService::configure` reads and resolves the saved Compose recipe using
   `doctor_paths` and the saved environment. It applies run-specific names, labels
   and resource restrictions. It returns a runtime configuration; it does not yet
   start containers. Invalid recipes or references outside the allowed roots fail.
2. `start` builds target images as required and starts the application's services.
   The audit run ID identifies this stack through `lailaps.session`. Services such
   as the database are included. Docker returns a random HTTP port bound to host
   loopback. Build, startup and port-discovery errors stop the run.
3. `waitUntilHealthy` polls the Doctor manifest's readiness checks through that host
   URL until their expected status/body markers match or the health timeout expires.
   It reuses the verified recipe and credentials without invoking Doctor again.
4. `docker inspect` retrieves the web container ID and attached networks. The Action
   selects a shared network configured without `internal: true`. This permits the
   runtime to contact the target and potentially the provider; actual provider
   reachability is still subject to the host's network configuration. The runtime
   URL uses the web container name and its internal port, not host loopback.
5. The Action writes `inputs/run.json`: configured models, reasoning efforts,
   concurrency and budgets; source/run identity; prepared artifact descriptors;
   actor profile path; target URL, container ID and session ID. File paths inside
   this JSON refer to container mounts. The source host path lets the trusted
   runtime mount source-only toolbox containers. Checksums identify the exact
   exported JSON bytes. Failure to write these inputs stops execution.
6. The Action calls
   `DockerService::runAudit`. This starts a separate trusted Python container on
   the shared target network. Its entrypoint is explicitly overridden to run
   `python -m pentest_agent.prepared_run`; the image's default entrypoint is Recon.
7. Python validates the inputs, checksums, source identity, Recon references and
   target ownership, then reuses the persisted Recon to run the legacy pipeline.
   Python updates the mounted outcome file during execution and finalizes it before
   exiting. Progress on stderr is also written to `output/runtime.log` and shown in
   the terminal. The provider key is passed through process/container environment,
   not embedded in Docker command arguments.
8. Laravel checks process exit status, the presence and JSON validity of the outcome,
   its schema/run/source identity, a completed/partial final status and a usable
   report. A successful process with no final result is a failure. On timeout or
   exception, DockerService force-removes its runtime container. The Action always
   attempts to destroy the target stack and its disposable named volumes. Bind-mounted
   input/output files and the canonical source are retained.

`runAudit` allows 3600 seconds. The target's build/start/readiness timeouts remain
those in `config/sandbox.php`. No new model retry or scheduler behavior is added.

## Docker mounts and arguments

An image is the reusable packaged software. A container is one execution of that
image. A bind mount makes one host directory visible at a different path inside
the container; writing the mounted path writes to the host directory immediately.
There is no subsequent docker cp operation.

| Host input | Runtime path | Access/purpose |
| --- | --- | --- |
| `paths.source` | `/workspace/source` | Read-only canonical source |
| `paths.inputs` | `/inputs` | Read-only run descriptor and exported artifacts |
| `paths.index` | `/index` | Writable run-local CBM copy |
| `paths.private` | `/private` | Read-only test actor credentials |
| `paths.output` | `/output` | Writable durable outcomes/evidence |
| Docker socket | `/var/run/docker.sock` | Trusted runtime manages source toolboxes and checks target ownership |

`--network` joins the target's shared network. `--user` reuses the PHP worker's
UID/GID for private-file access and file ownership; `--group-add` permits socket
access. `--read-only` protects the container root while writable mounts and a
temporary `/tmp` remain available. `--init` manages child processes. `--rm` removes
the runtime container when it exits, not its host output directory. `TOOLBOX_IMAGE`
selects the packaged image for source-only toolboxes, which do not receive the
trusted runtime's provider key, target network or Docker socket.

## Durable output and database status

```text
<project-ulid>/runs/<audit-run-ulid>/
  inputs/run.json
  private/actors.json
  index/
  output/outcome.json
  output/runtime.log
  output/error.log          # when a diagnostic exists
```

All paths above are relative to the private projects disk. The command prints the
run ID and absolute output directory. No report JSON column is added: Laravel can
locate the result using `$auditRun->path().'/output/outcome.json'`.

Python writes JSON through a temporary file followed by atomic replacement. A
reader sees the last complete checkpoint rather than a half-written JSON file.
An interrupted run may retain an intermediate checkpoint; Laravel records failure
and never treats that checkpoint as successful completion. Already published data
is retained; an in-flight model response may not have been published yet.

Completed and partial outcomes produce command exit code 0. Technical failures
produce exit code 1 and persist diagnostics in `audit_runs.error` and, when the
output directory exists, `output/error.log`. Diagnostic collection cannot replace
the primary error. A target cleanup failure also produces exit code 1, appends its
diagnostic and preserves a completed/partial audit status when a valid audit result
already exists. Run status describes the audit result; error also exposes cleanup
problems. Result-import failure behaves similarly: it preserves the agent status,
records diagnostics, returns exit code 1 and retains the raw files for retry.
Routine logs are private diagnostics, not customer-facing report content.

## Leads and areas

The command retains the complete legacy output and imports its compact DTO using
`OutcomeService`: one transaction updates the snapshot, run areas and leads,
including deferred proposals. Runtime identity makes re-import idempotent.
Useful existing report fields include:

- `report.confirmed` and `report.suspected`: customer-facing finding candidates.
- `report.structured_outputs`: captured Reader/investigator stage outputs and
  decisions; `accepted` describes output admission, not vulnerability confirmation.
- `report.pending_leads` and global pipeline events: incomplete downstream work.
- `report.coverage.area_ledger`, `reader_area_checkpoints`, `reader_area_updates`
  and `visited_area_ids`: execution/coverage evidence for each area.

Keep the complete outcome for diagnosis and future imports. A lead that is not
confirmed is not automatically rejected: technical blockers and budget exhaustion
must remain distinguishable from evidence-based rejection. Likewise, the legacy
`rejected_inconclusive` bucket includes several closure states and should not be
blindly interpreted as a false-positive list. The DTO selects the product-relevant
rows while the complete raw report remains available for diagnosis.

Area definitions belong to a specific Recon artifact. Exploration status belongs
to a particular audit run. The database projection has
Recon-area definitions identified by `(recon_artifact_id, area_id)`, and separate
run-area rows containing the per-run status/reason/checkpoint. Leads point to the
run-area they originated from. Runtime-enriched areas must also be retained even
when absent from the original Recon. JSONB can retain the original area definition
and detailed output while searchable identifiers, relations and statuses use
ordinary columns.

Do not mutate the reusable Recon with execution status. Use authoritative ledger,
checkpoints and termination reasons to distinguish not-started, incomplete,
technically failed and completed areas; the current area ledger alone has only
queued/active/closed states. Running with the same Recon today seeds all areas
again. Selective continuation requires a later explicit Python input and admission
change; storing area rows does not itself implement resume or lead replay.

## Verification performed

- Targeted PHP syntax and Pint checks on the two modified PHP files.
- Full command lifecycle with simulated Docker/HTTP: completed/partial, Python and
  Docker failures, malformed/missing/intermediate/wrong-identity output, preparation
  errors, runtime exceptions, target cleanup failures and import failures. Database fixture writes
  were rolled back and only fixture directories removed.
- Real restored source inputs accepted by the installed Python image using
  `--check-inputs`, with network disabled and no Docker socket/provider key.
- Real Docker probe confirmed stderr callbacks and persistent host output after
  `--rm`, without invoking the audit pipeline.

No live investigative agent run was executed. The actual smoke test remains the
user-invoked audit command above.
