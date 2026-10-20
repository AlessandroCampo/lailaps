# Run persistence structure and evidence references

`OutcomeService` coordinates transactional result imports and on-demand report
presentation. `audit:run` imports a validated terminal outcome after target cleanup.
The already completed historical run is left untouched. `RunAuditAction` is shared
by the smoke-test command and the queued `RunAudit` job. No pruning or report UI
is implemented.

The original run/area migrations were updated and a new lead-table migration was
added. A verified version 2 development snapshot was rehearsed in an isolated
PostgreSQL schema before the live reset/restore. The live reset uses
`Schema::dropAllTables()` followed by `migrate` on the same outer transaction;
wrapping `migrate:fresh` itself does not provide this guarantee because its wipe
command disconnects the database connection. Existing application rows, IDs and
all five files in the completed audit's output folder were verified unchanged.
The existing run has a null stored outcome, with zero run-area and lead rows.

## Database structure

- `audit_runs.outcome`: nullable JSONB, cast to an associative array. It can hold
  `RunOutcome::toArray()` after import. The original Python
  outcome remains a separate private file; `getOutcome()` still reads that file.
- `audit_run_areas`: integer ID, run FK, nullable Recon-area FK, `pass_key` (default
  `pass-001`), `area_key`, dedicated enum status, text reason and JSONB payload.
  `(audit_run_id, pass_key, area_key)` is unique. Deleting the run removes its areas;
  deleting a Recon definition clears its FK without erasing historical run areas.
  Reader-created areas may have no Recon definition. Preserve the initial definition
  and checkpoint/result separately within the payload when importing.
- `audit_run_leads`: ULID ID, run FK, nullable run-area FK, runtime `lead_key`,
  dedicated enum status, text title, nullable severity and JSONB payload. The pair
  `(audit_run_id, lead_key)` is unique. Deleting the run removes its leads; deleting
  an area clears its FK without deleting the lead. Do not put a global runtime key
  in the database primary key.

The lead status enum supports pending, running, confirmed, suspected, blocked,
inconclusive, rejected and failed. Detailed legacy lifecycle/funding states belong
in the payload. The DTO's proposal IDs remain distinct from investigator IDs.

Relationships are `AuditRun::areas()`, `AuditRun::leads()`,
`AuditRunArea::auditRun()`, `AuditRunArea::reconArea()`,
`AuditRunArea::leads()`, `AuditRunLead::auditRun()` and
`AuditRunLead::auditRunArea()`.

`CreateAuditRunAction` supplies the new area key for future pending run-area rows.
Its preparation is not a result import. The already completed run is left untouched.

The importer resolves areas through the current run with the DTO's pass and area
keys. Leads reference that run's area, and imports update existing rows without
changing their IDs. The snapshot array is not the Python envelope accepted by
`RunOutcome::fromArray()`; that factory normalizes the original runtime format.

## Import and presentation

```php
$outcomes = app(OutcomeService::class);
$dto = $run->getOutcomeSummary();
$run = $outcomes->persist($run, $dto);
$report = $outcomes->getEnrichedPayloadWithSnippets($run, $dto);
$detail = $outcomes->presentLead($run, $dto->leads[0], includeToolCalls: true);
```

Check for a null DTO when no raw outcome exists. `persist` validates the run/source
identity, source hash, pinned artifact references and terminal status. It locks the
run and imports areas, leads, deferred Reader proposals and the compact snapshot
within one transaction. `CreateAuditRunAreaAction` preserves the definition beside
the result; `CreateAuditRunLeadAction` resolves the pass/area relationship and
updates searchable title, severity and status columns. A missing referenced area
aborts the complete import. Existing areas absent from the outcome are retained.

The caller owns the run's lifecycle status and diagnostics. The command also imports
a valid native failed outcome, preserving any already published results. Import
failure is recorded in `audit_runs.error` and `output/error.log`, causes exit code 1
and leaves the previous snapshot intact. It preserves a completed/partial agent
status and retains raw files for a later retry. Missing, invalid or nonterminal
checkpoints are not automatically imported.

Presentation never writes to the database or mutates the DTO. Source snippets are
bounded to 40 lines; HTTP evidence is resolved by pass and request/response IDs.
`presentLead` optionally reads its investigator journal, exposing the first 20
calls with 2,000-character argument/result/output previews and truncation flags.
Missing or expired evidence produces an explicit unavailable reason. File paths
must resolve inside the source snapshot or the run's output directory.

`app:test` previews the latest raw outcome with snippets and HTTP evidence without
importing it. Offline checks verified real evidence resolution, atomic rollback,
stable IDs on re-import, pass-specific links and the command's failure paths.
Fixture writes were rolled back and no model/provider requests were made.

## Where the real evidence lives

All paths below are relative to the private projects disk:

```text
<project-id>/runs/<run-id>/output/
  outcome.json
  runtime.log
  <run-id>-investigator-<hash>.jsonl
```

The inspected run has three investigator journals. Its output folder is about
3.2 MB in total: roughly 901 KB outcome, 479 KB runtime log and 1.81 MB journals.
Removing a Docker container does not delete these bind-mounted host files.
All 33 HTTP references, 35 source locations and three journal references in the
real compact DTO were verified against the retained original files.

### HTTP request and response references

For an aggregated outcome, transactions are stored at:

```text
report.passes[*].report.evidence_ledger.http_transactions
```

Match the DTO's `pass_id` against the pass and its
`evidence.http_refs[*].request_id` / `response_id` against a transaction. In the
real run, for example, the fully qualified lead is
`pass-001:investigation-1:lead-1`, while its transaction uses local lead ID
`investigation-1:lead-1`, request ID `investigation-1:http-1` and response ID
`investigation-1:response-1`. IDs must remain scoped to their run and pass.

```php
$raw = $auditRun->getOutcome();
$pass = collect($raw['report']['passes'])->firstWhere('pass_id', $leadDto->passId);
$reference = $leadDto->evidence['http_refs'][0];
$transaction = collect(data_get($pass, 'report.evidence_ledger.http_transactions', []))
    ->first(fn (array $row) => ($row['request_id'] ?? null) === $reference['request_id']
        && ($row['response_id'] ?? null) === $reference['response_id']);
```

The transaction contains method/path/status, actor, request representation,
redirect metadata and bounded response/inspection excerpts. `ArtifactStore` bounds
HTTP body and grep excerpts to 2,000 characters and applies redaction. Derived
response inspections, when present, are under
`evidence_ledger.derived_response_observations`, matched by request/response IDs.
Browser evidence has its own action and observation lists in the same ledger.

Full raw HTTP response bodies are held in process memory. The method
`write_response_body()` does not create a body file. A response ID therefore does
not imply that its complete original body can be recovered after the run. A journal
may preserve larger extracts produced by inspection tools, but this is not a complete
HTTP traffic archive.

### Investigator tool outputs

The DTO's `evidence.journal` is the filename under that run's output directory.
Read it as JSON Lines: each line is an independent JSON event.

- `started`: tool name, arguments and `call_id`.
- `result`: returned result or error for the same `call_id`.
- `output`: an archived managed tool output, with `output_id`, full managed result
  and metadata. An investigator's managed output can be archived before the smaller
  view is delivered to its model.
- `attempt` and cancellation/error events retain execution context.

Match tool calls by `call_id`; match managed outputs by `output_id`. HTTP IDs are
separate identifiers and should first resolve through the transaction ledger rather
than being treated as journal call IDs. Tool results can already be truncated at
their source; archival cannot reconstruct bytes a tool never captured. Journals are
redacted, but remain private diagnostic records rather than ready-made UI content.

Durable journals are scoped to active investigator leads. Reader and Recon tool
buffers are not all persisted as equivalent journals. `write_tool_output()` is a
no-op legacy hook: durable investigator outputs are written via `append_tool_trace`,
not one separate file per output.

### Source evidence

The DTO retains file/line ranges. Read the exact `ProjectSource` snapshot, resolve
the relative path inside its canonical source root and display a bounded line window.
No running container is necessary after the run: the mounted source is a host
directory. Reject resolved paths outside that source root. For the original mapping
between source reference IDs and observed snippets, inspect the raw finding's
locations and the pass's structured output source references.

## Proposed retention policy, not enabled

Do not prune the current run before importing it and extracting the report evidence.
The compact DTO holds references, not independent copies of all evidence.

A practical first policy is:

1. Keep normalized run/area/lead records and selected, redacted report evidence for
   the lifetime of the report.
2. Retain the original outcome, investigator journals and runtime logs for a short
   configurable debugging period, for example seven days; a longer interval for
   technical failures can be decided separately.
3. Remove run-local actor files, exported input copies and CBM copies after a
   terminal run and successful import, unless an explicit recovery needs them.
   Keep canonical source, canonical artifacts and the verified Doctor recipe under
   their own lifecycle.
4. Delete raw evidence only after selected evidence is durably available for every
   report reference that must remain usable. Record pruning and make expired debug
   references explicit rather than leaving unexplained missing files.
5. Apply cleanup to backups too: development snapshots include project output and
   private run files, so deleting only the live directory does not remove those copies.

A future Laravel scheduled command/job can implement this against terminal runs,
with an import-success marker and recorded evidence expiry/pruning dates. No automatic
deletion or retention timer is activated by the importer.
