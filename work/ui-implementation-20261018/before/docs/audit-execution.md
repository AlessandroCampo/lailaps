# Audit entrypoints and project summaries

`RunAuditAction::handle(AuditRun $run, ?callable $output = null): AuditRun` owns one
execution: workspace preparation, target startup/readiness, Python execution,
teardown, result import and diagnostics. It claims a pending run in a short database
transaction, then performs the external work without holding a database transaction
open. Calling it again for a running or terminal run does not restart the audit.
It returns the refreshed run on success and throws after persisting diagnostics on
execution, cleanup or import failure. A completed/partial agent status is preserved
when only cleanup/import fails.

Create the pending run with the existing Action:

```php
$run = $createAuditRun->handle($source, RunAuditAction::DEFAULT_CONFIGS);
$runAudit->handle($run);
```

`DEFAULT_CONFIGS` is the single static model/budget/concurrency array used by both
entrypoints. Configuration is copied to each run when it is created; later edits
to defaults do not change already queued runs.

## CLI smoke test

```bash
php artisan audit:run <source-ulid>
```

This executes synchronously, streams redacted output and returns exit code 1 on
failure. It has no additional configuration options and does not require a worker.

## Queued execution

```text
POST /projects/{project}/sources/{source}/audit-runs
route: projects.sources.audit_runs.store
```

The endpoint requires authentication, verified email, project ownership and a source
belonging to that project. It accepts no model/budget request parameters. A ready
source, ready Lab and usable Recon are required. Creation locks the Lab and rejects
another pending/running audit on it with a validation error. This protects the
currently shared Doctor workspace.

`AuditRunController::store` creates the pending record, dispatches `RunAudit` after
commit and redirects to `projects.show`. The job serializes only the run ID, loads
the run and invokes `RunAuditAction`. Dispatch failure marks a still-pending run
failed. The job has one attempt, a 7200-second timeout and `failOnTimeout`; its
failure callback marks only unfinished runs failed. There is no automatic paid retry.

Run a queue worker normally:

```bash
php artisan queue:work
```

Restart an already running worker after deploying the code. The database queue's
`retry_after` must exceed the job timeout; its configured default is 7260 seconds.
`started_at` measures actual execution, excluding queue wait. `finished_at` includes
setup, execution, cleanup and result import. The additive migration leaves old run
timestamps null rather than inventing their duration.

## Read-only progress endpoint

```text
GET /audit-runs/{auditRun}/progress
route: audit_runs.progress
```

Use the authenticated browser session and request JSON. The endpoint requires
verified email and ownership of the run's project; unauthorized or unknown run
IDs return 404. It performs no run, area or lead writes and never dispatches work.
The UI has not been modified.

```json
{
  "run_id": "<run-ulid>",
  "status": "running",
  "started_at": "2026-10-18T10:00:03.000000Z",
  "finished_at": null,
  "duration_seconds": 600,
  "poll_after_seconds": 30,
  "snapshot_state": "available",
  "snapshot_source": "filesystem",
  "snapshot_updated_at": "2026-10-18T10:09:58.000000Z",
  "snapshot": {
    "status": "running",
    "provisional": true,
    "termination_reason": "running",
    "coverage_complete": false,
    "summary": {
      "areas": {"pending": 0, "running": 1, "completed": 0, "partial": 0, "failed": 0},
      "leads": {"confirmed": 1},
      "deferred_leads": 0,
      "rejected_leads": 0
    },
    "usage": {"points_used": 100000, "point_limit": 1000000},
    "areas": [
      {"pass_id": "pass-001", "area_key": "uploads", "title": "Uploads", "status": "running", "reason": null}
    ],
    "leads": [
      {"id": "pass-001:investigation-1:lead-1", "pass_id": "pass-001", "area_key": "uploads", "title": "Published finding", "status": "confirmed", "severity": "high"}
    ]
  }
}
```

Poll after 30 seconds while `poll_after_seconds` is non-null. A terminal database
status returns null to stop polling. The database run status is authoritative;
`snapshot.status` separately describes the retained checkpoint. A checkpoint is
provisional while the run is active, or whenever the checkpoint itself is still
pending/running, including after an interrupted run has been marked failed.

During execution, `OutcomeService::progress` reads the current atomically published
`output/outcome.json`, validates schema/run/source identity and normalizes it with
`RunOutcome`. The result contains compact area and published-lead lists, summary
counts and normalized usage. It excludes detailed narratives, source paths/snippets,
tool journals, HTTP bodies and debug diagnostics. Deferred Reader proposals remain
a separate summary count; they are not counted as published findings. Each response
reflects that snapshot alone, so a withdrawn finding is not retained from earlier
polls. Missing telemetry remains absent rather than being invented.

`snapshot_state` is `waiting` before a usable checkpoint exists for an active run,
`available` for a valid snapshot, `unavailable` for an unreadable checkpoint or a
terminal run without one, and `invalid` for invalid JSON/schema/identity/payload.
Unavailable/invalid snapshots return null data without changing the run. The initial
Python bootstrap file may have a null report and no identity yet; it is treated as
waiting and exposes no result data. Snapshot problems return HTTP 200 with that
explicit state. Responses use `Cache-Control: private, no-store`.

After a terminal run has a stored compact outcome, the endpoint projects that
database snapshot and reports `snapshot_source: database`, without requiring the
raw file. Otherwise it can still show the last file checkpoint. The update timestamp
is file modification time for filesystem snapshots and row update time for stored
snapshots; it is not a process heartbeat. Final persistence remains exclusively in
the existing execution/import path. No SSE or background synchronization is added.

Offline checks used a copy of the real outcome, including a running checkpoint,
withdrawn lead, failed-run checkpoint, missing/invalid/foreign files and a stored
terminal DTO without its file. The real snapshot projects to about 3.5 KB. HTTP
ownership/authentication/email checks and unchanged result rows were verified;
fixtures were rolled back and no agents/provider calls were launched.

## Project show prop

The existing page receives one additional prop; no UI components have changed:

```json
{
  "runs": {
    "latest": {
      "id": "<run-ulid>",
      "status": "partial",
      "created_at": "2026-10-18T10:00:00.000000Z",
      "started_at": "2026-10-18T10:00:03.000000Z",
      "finished_at": "2026-10-18T10:18:27.000000Z",
      "duration_seconds": 1104,
      "source": {
        "id": "<source-ulid>",
        "source_type": "zip",
        "uploaded_at": "2026-10-18T09:00:00.000000Z"
      },
      "has_report": true,
      "coverage_complete": false,
      "findings_count": 4,
      "lead_counts": {
        "confirmed": 2,
        "suspected": 1,
        "inconclusive": 1,
        "blocked": 0
      }
    },
    "history": []
  }
}
```

`history` contains every other run, newest first, with the same compact shape.
With no runs, `latest` is null and `history` is empty. `has_report` means the compact
outcome was imported; counts are null before that import, not misleading zeros.
Pending Reader proposals are excluded from findings. Coverage can be true, false
or unknown/null. Historical source summaries include soft-deleted source versions.
Original ZIP filenames are not currently stored by uploads, so the summary exposes
source identity/type rather than inventing a filename.

The query selects only summary columns, one JSON coverage field and count
aggregates; it does not load full outcome/config/error/evidence payloads. The Resource
does not read raw files or resolve evidence during project listing.

Offline verification covered the shared execution lifecycle, authenticated route,
owner/source isolation, duplicate rejection, failed enqueue, job delegation/failure,
latest/history ordering and counts/duration/coverage. Fixture writes were rolled
back; no live agent/provider requests were made.
