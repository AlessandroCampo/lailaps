# Filesystem and target startup for a prepared audit

The current launcher does not require `labs/<id>/workspace` or `labs/<id>/artifacts`.
`Lab::storageDirectory()` supplies a possible path, but `LabSetup` currently prepares
Doctor files through `ProjectService::prepareDoctorWorkspace()`, under `doctor/`.
Use the verified Lab's `doctorSession` relation to resolve those existing paths.

```text
<projects-disk>/<project-ulid>/
├── sources/<source-ulid>/
│   ├── source.zip
│   └── files/                         original source snapshot
├── doctor/<workspace-uuid>/
│   ├── workspace/                     Doctor's prepared application copy
│   └── artifacts/                     manifest, Compose, Dockerfile, bootstrap
├── artifacts/<artifact-id>/
│   ├── code_index/                    native CBM files, when this is a CBM artifact
│   └── recon/                         when this is a Recon artifact
│       ├── inputs/                    prepared.json and exported sensors
│       ├── index/                     Recon's working CBM copy
│       └── output/                    persisted Recon outcome and journals
└── runs/<audit-run-ulid>/              created by your new launcher
    ├── inputs/                        run.json, recon.json, optional sensor JSON
    ├── index/                         run's writable CBM copy, when provided
    ├── private/                       actors.json, when actors exist
    └── output/                        report, journals, evidence
```

Different artifact IDs own the `code_index` and `recon` subdirectories: the tree
shows their shapes together for brevity. Semgrep and OSV are currently persisted
as database payloads; they do not require their own filesystem directory.

The immutable source snapshot is what the agents read. Doctor's workspace is a
separate copy because installing/configuring the target can modify application
files. Doctor's `artifacts` directory contains deployment recipe files; these are
different from the `ProjectArtifact` analysis records in the database.

## Reuse the verified target recipe

Start with one fixed Source, its ready Lab, the Lab's verified Doctor session and
the prepared Recon. Do not create another Doctor session just to launch an audit.
Successful Doctor verification normally tears down the target containers; `ready`
means the recipe passed verification, not that an HTTP application is still running.

The following is the target-startup part of your CLI command, not a complete audit
launcher. `$run` is your new `AuditRun`, and `$source` is the selected Source.

```php
$lab = $source->readyLab;
$doctor = $lab?->doctorSession;

if (! $doctor) {
    throw new RuntimeException('The source has no verified Doctor recipe.');
}

$paths = $doctor->paths;
$sandboxId = (string) $run->id;
$manifest = $sandbox->manifest($paths['artifacts'], $paths['workspace']);
$environment = $sandbox->environment($manifest, $lab->environment ?? $doctor->environment ?? []);
$runtime = $sandbox->configure($manifest, $paths, $sandboxId, $environment);
$output = fn (string $type, string $text) => fwrite($type === 'err' ? STDERR : STDOUT, $text);

try {
    $hostUrl = $sandbox->start($runtime, $paths['artifacts'], $sandboxId, $manifest, $output);
    $sandbox->waitUntilHealthy($hostUrl, $manifest['readiness']);
} finally {
    $sandbox->destroy($runtime, $paths['artifacts'], $sandboxId);
}
```

Insert target inspection, descriptor creation and Python execution inside that
`try`, after readiness and before teardown. Reuse the verified environment so that
bootstrap and application actors use matching passwords. Keep credentials and
resolved runtime configuration out of plaintext `AuditRun.configs`.

`configure()` resolves Compose paths, assigns run-specific container/network/volume
names and attaches `lailaps.session=<sandboxId>`. The same ID must be passed to
`configure`, `start`, `destroy` and Python's `target_session_id`. It is an execution
ownership ID, not necessarily the Doctor session UUID.

For this first test, reuse the verified Doctor workspace and recipe with one audit
at a time per Lab. Unique Docker names do not isolate writable bind-mounted files.
Concurrent runs or reproducible resets require separate target workspaces; that is
a later step, not a reason to duplicate directories for the current sequential test.

## Mounts for the Python agent

Create the new run directories with `ProjectService::prepareDirectory`, relative
to the projects disk. Export the selected DB payloads into `inputs`, hash the exact
JSON bytes, and export the entire Recon outcome, including its identity envelope.
Select sensor IDs referenced by Recon's `input_artifacts`, so the frozen map and
the supplied artifacts agree.

| Host path | Agent container path | Mode |
| --- | --- | --- |
| `$storage->path($source->sourcePath())` | `/workspace/source` | read only |
| `<run>/inputs` | `/inputs` | read only |
| `<run>/index` | `/index` | writable |
| `<run>/private` | `/private` | read only |
| `<run>/output` | `/output` | writable |

The source mounts directly: do not copy it into the run. Small JSON files are
exports for a Python process that cannot query Laravel's DB. CBM opens its cache
in writable mode, so copy the finalized native index to `<run>/index`; do not mount
the canonical index writable or reuse Recon's working copy for concurrent activity.
No sensor, index or Recon needs to be rerun to prepare these files.

Resolve the target web container by its run ownership label and the manifest's
Compose service. Join the runtime to an appropriate shared Docker network.
`$hostUrl` uses `127.0.0.1` and is for Laravel's readiness check. Python needs
`http://<web-container-name>:<manifest.web_port>` on the shared network, since its
own `127.0.0.1` refers to the agent container.

The run descriptor contains Source ULID/hash, mounted paths, optional sensor
descriptors, required Recon descriptor, target URL/container/ownership ID, optional
actors file, models, budget and concurrency. The full descriptor and Process call
are in [python-global-run-integration.md](python-global-run-integration.md).
Use image `lailaps-agent:minimal-prepared-global-ulid` and entrypoint
`python -m pentest_agent.prepared_run`; the default image entrypoint is Recon.

Run `--check-inputs` first to validate artifacts without provider calls. It does
not test target ownership or HTTP readiness. A complete audit is an explicit next
execution, not part of project preparation or the restore.
