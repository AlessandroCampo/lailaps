# Project preparation artifacts — implementation roadmap

Status: proposed implementation plan; no runtime changes are implemented by this document.

Date: 2026-10-16.

## Outcome and boundaries

Lailaps 2 owns the initial production, validation and persistence of Semgrep results and Codebase Memory (CBM) indexes. A temporary Python Recon runtime consumes those prepared inputs without rebuilding them. Laravel imports and persists the Recon result. The existing TypeScript Env Doctor remains responsible for preparing and verifying the application environment.

P0 includes first-time production, durable publication, consumption for the same source snapshot, failure diagnostics and integration into project creation. Persist the environment recipe/build references through the same artifact registry.

P0 excludes cross-snapshot reuse, selective updates, Git/PR integration, dependency-impact analysis, incremental Semgrep, replacement of CBM, a TypeScript Recon rewrite, resumable Python conversations, new audit agents, a shared graph server, a distributed image registry and new UI screens.

This is a persistence and integration task, not a replacement agent engine. Reuse Python's role history, provider handling, terminal phase and budget policies during an execution. Do not add another full-agent retry loop in Laravel.

## Verified starting point

- `CreateProjectAction` stores the snapshot and dispatches `LabSetup` after commit. `DoctorService` owns the shared CLI/job preparation and health-check pipeline.
- `AgentSession` belongs to the source and retains Doctor conversations; its expiry must not determine the lifetime of published artifacts.
- Legacy repository inspected at commit `1d1cb1516c1036fa61b7cf693815a3622f8f2567`. Recheck the selected files at implementation start; do not modify that repository as part of this roadmap.
- The current legacy CBM bootstrap returns `disabled`, `Deps.cbm_enabled` returns `False`, and `category_recon_tools` no longer exposes graph tools. Providing a database path alone will not enable CBM consumption. The temporary Lailaps 2 runtime needs a small query adapter and tool registration.
- The legacy Python `recon` entrypoint and `run_recon_only` remain available. The CLI currently creates `DockerCommandExecutor` even without a target; account for its Docker/toolbox prerequisites.
- `surface_context.py` currently both executes Semgrep and normalizes its results. Split that boundary without rewriting the normalization policy.
- The Python report's bounded Surface Context is not the complete Semgrep inventory. Preserve raw output and expose the existing normalized signals to Recon.
- `run_events.emit_event` is currently a no-op. Console text is not a machine transport.
- Lailaps 2 AGENTS.md currently says Python is reference-only. When implementing the bridge, amend that paragraph to permit temporary Recon consumption of prepared artifacts, preserving TypeScript as the intended agent runtime. The user's approved direction authorizes this exception.

## Ownership

| Component | Responsibility |
| --- | --- |
| Laravel | Source selection, queue, native process supervision, artifact validation/publication, database writes, execution provenance |
| Semgrep executable | Initial scan and raw structured results |
| CBM executable | Initial index and subsequent graph queries |
| Python Recon runtime | Existing signal normalization, graph query tools, investigation, history, terminal turns and budget accounting |
| TypeScript Doctor | Existing candidate recipe preparation and validation |

The original uploaded snapshot is the input for Semgrep and CBM. The Doctor workspace is mutable and may differ from that snapshot; it must not silently become the static-analysis source.

The intended flow is:

```text
Project source stored
  ├─ existing LabSetup → verified environment → environment artifact
  └─ PrepareProjectAnalysis
       → Semgrep artifact
       → CBM artifact
       → Python Recon with explicit prepared inputs
       → Recon artifact
```

The branches are independent jobs. A single worker may execute them sequentially; parallel scheduling is not a P0 requirement. Static preparation does not require an application container or an HTTP health check.

## Task 0 — Freeze the contracts and the temporary runtime

Implement before copying legacy code or designing migrations around its current report shape.

1. Select a reproducible Python baseline from the inspected repository, including its dependency lock, model configuration, category definitions and required runtime assets. Package it within Lailaps 2's build inputs; do not require a sibling checkout at runtime.
2. Pin the temporary image by version/digest and record the originating source revision. Keep the published image immutable. Do not rebuild it at every onboarding.
3. Select exact Semgrep and CBM releases. Verify that the chosen CBM executable supports the methods used by the retained client; a legacy Python dependency declaration does not establish compatibility with a different native release.
4. Choose the existing global Recon output contract and preserve it. Do not implement Reader or Confirmer. Keep transport identifiers outside model-facing output.
5. Document the temporary transport as a versioned descriptor generated by Laravel, with execution ID, source ID/hash, exact artifact IDs, expected statuses, file descriptors and frozen model/budget configuration. Resolve these values from database rows, not from arbitrary child-process claims.

The descriptor is an internal input, never a new schema the model has to fill. A descriptor version is a transport version, not a cache invalidation policy.

**Done when:** a developer can identify exact binaries, runtime build inputs, required mounts, environment variable names and the accepted input/output formats without launching a paid model run. Record concrete selected versions before closing this task.

## Task 1 — Add the artifact registry

Add `ProjectArtifact` and a creation migration, following the repository's development migration policy. Do not introduce record relocation/backfill code. This roadmap does not authorize running a database reset now.

Suggested columns:

| Column | Meaning |
| --- | --- |
| `id` | UUID allocated before work starts; publication/idempotency identity |
| `project_source_id` | Required source FK; project ownership is derived through the source |
| `type` | `environment`, `static_analysis`, `code_index`, `recon`; producer/format belong in metadata |
| `status` | `pending`, `running`, `ready`, `partial`, `failed` |
| `payload` | Nullable JSONB business result or compact descriptor |
| `storage_path` | Nullable private blob/directory path relative to the projects disk |
| `metadata` | Producer/runtime/schema versions, actual configuration, source hash, input artifact IDs and provenance |
| `content_hash` | Nullable checksum of the published payload/file manifest |
| `error` | Nullable diagnostic text |
| timestamps | Creation/update and explicit execution start/end if needed by the job |

`ProjectArtifactPreparation` shares locking, reuse, execution supervision and persistence for the static analysis and code index producers. The concrete services supply their process, provenance and `checkIfPartial` payload inspection. No plugin system or further interface hierarchy is required.

For Semgrep and CBM, P0 keeps one reusable artifact per source and type. Preparation returns an existing ready artifact without scanning again. A failed or partial preparation can retry on the same record, clearing the previous payload and diagnostics before starting. A lock per source and artifact type prevents overlapping preparations. Changing rules or engine versions does not automatically invalidate a ready artifact in P0; explicit refresh and version-based invalidation belong to the later update workflow.

Other artifact kinds retain the original publication contract: a new execution uses new artifact IDs, a completed artifact is immutable, and re-delivery is acknowledged only if its artifact ID and checksums match. A conflicting publication must not overwrite a finalized artifact.

The shared preparation lifecycle treats nonzero exit, exceptions, invalid JSON and unusable payloads as `failed`, retaining any available output. On successful execution, producer payload signals determine `partial` through a short reason persisted in `error`; otherwise the artifact is `ready`. An empty successful scan is different from an unavailable sensor. Preserve engine exclusions/timeouts in the payload and do not infer success from exit code alone. CBM intentional exclusions alone do not make an index partial.

**Done when:** fixture payloads can be stored, loaded by exact source/artifact ID and finalized without depending on an agent session. Publishing twice is harmless; publishing different content to a finalized ID is rejected.

## Task 2 — Implement publication and persist the existing environment output

Private artifact layout, relative to the existing projects disk:

```text
{project_id}/artifacts/{artifact_id}/
```

Keep JSON results and provenance in PostgreSQL. Use private storage for CBM's native database and multi-file environment recipes. Images remain in the local Docker daemon; store actual image IDs and available digests, not only mutable tags.

### Publishing files safely

Database transactions do not roll back filesystem operations:

1. Stage validated output under an application-controlled directory on the same filesystem.
2. Build a manifest of relative paths and byte checksums. Reject escapes/symlinks instead of trusting a producer-supplied destination.
3. Publish the complete directory by rename.
4. In a database transaction, finalize the artifact descriptor/checksum and status.
5. If the final DB write fails, leave the artifact non-ready and allow re-import of the same verified publication. Do not assume the files disappeared.

Readers only consume finalized artifacts after checking that the expected files exist and match. This does not require a generic distributed transaction or a new garbage collector.

After Doctor health checks and normal verification cleanup succeed, publish a stable recipe bundle plus image references and readiness evidence. Preserve the effective build inputs or source/override bundle needed for those artifacts. Do not keep references to mutable files that a later `agent:test --session` can overwrite.

Record when readiness was verified. This describes a verified preparation, not a currently running application. Keep generated credential values out of ordinary JSON descriptors, transport files and model context; retain generator references. Existing runtime secrets stay in encrypted server-owned storage. Published artifacts outlive conversation pruning.

**Done when:** recipe files remain stable after a later Doctor attempt or session pruning, and an environment artifact cannot become ready before successful validation. Existing Lab status behavior remains separate from analysis artifact status.

## Task 3 — Produce and persist Semgrep from Lailaps 2

Implement a focused `StaticAnalysisService` in `Features/ProjectAnalysis` using Laravel Process and a pinned native/container runtime. Semgrep is the current producer, declared in metadata; the artifact type is `static_analysis`. Use the existing local rules corpus and lock manifest. The scan needs no provider credentials and must not fetch changing registry rules at runtime.

Implementation progress (2026-10-17): the Laravel producer, durable JSON/diagnostics, rule verification, ready-result reuse and independent onboarding job are implemented. The retained Python normalization now also accepts prepared JSON in `agent/recon-python`, without scanner execution. CBM and Recon are not launched by this job yet.

1. Mount the exact source snapshot read-only. Preserve the ZIP's relative paths, including a wrapper directory if present.
2. Carry over the existing scan options, file-size/time limits and rule-ID behavior. Avoid silently changing targeting while moving the launcher.
3. Preserve the full JSON payload, nonempty stderr, exit status, image and rule corpus checksums. Engine version stays in the payload; source identity stays on `ProjectSource`. Scan configuration remains in the producer command, without duplicating the full command or rules manifest in metadata. Store the JSON in PostgreSQL; retain raw stdout when decoding fails.
4. Apply the existing completeness policy to determine ready/partial/failed. Confirm zero matches can still be ready; zero analyzed files cannot masquerade as a complete scan.
5. Extract the legacy raw-result normalization into a pure Python input path: raw results plus diagnostics in, normalized inventory/signals/status out. Keep its behavior and version; do not duplicate the policy in Laravel.

**Complex boundary:** move execution and persistence now, while retaining normalization in Python. That keeps security filters, signal IDs, path normalization and limitations consistent. Recon must receive all normalized signals available to its tools, not just the bounded prompt census.

**Done when:** an offline scan fixture produces a durable artifact with complete diagnostics, and the Python normalizer consumes its serialized results without invoking Semgrep.

## Task 4 — Produce and persist CBM from Lailaps 2

Implement a focused `CodebaseMemoryService` around the pinned executable.

Implementation progress (2026-10-17): `CodeIndexService` uses the shared artifact preparation lifecycle, persists the native cache and JSON result, and records a short partial reason from parser/skipped-file/count signals. A harmless fixture has been indexed and reopened with `index_status` in a fresh container. `PrepareProjectAnalysis` now dispatches preparation automatically and `ReconService` stages the cache for the Python query client. The full query-based sufficiency check below remains pending.

1. Index the same immutable source mounted at a stable logical root, `/workspace/source` for the selected CBM 0.12.1 runtime, which rejects `/workspace` as overly broad.
2. Use a dedicated writable index/cache location for this artifact. Keep it separate from the launcher binary cache and from the source tree.
3. Check index status, root association and basic architecture/query responses. A count of nodes alone does not establish completeness; persist parser limitations and native diagnostics.
4. Publish a consistent native index using the selected release's export operation, or an appropriate backup mechanism. For SQLite, use a native backup or close/checkpoint all writers before copying; copying only the main file while WAL contains writes is unsafe.
5. Record native format/version, project name/identifier, logical root, source hash, counts and an architecture summary. Retain everything necessary for query clients to reopen the index.

### Proving the artifact is sufficient

Stop the indexing process and remove its disposable working directory. In a fresh process, mount the original source at the same logical root (`/workspace/source`), restore/stage the artifact and execute a known symbol query. Assert that no indexing operation occurs.

Native query clients may require writable locks or cache files. Keep the published artifact immutable and stage a per-execution working copy where necessary. Disable auto-index/watch behavior for the chosen release rather than allowing queries to silently mutate the prepared source index.

This test must work on an extracted ZIP without `.git`. Git HEAD is not the source identity; Laravel's source hash is authoritative.

**Done when:** a fresh process can query the published index with indexing disabled, from an independent workspace, using only the declared files and version metadata.

## Task 5 — Add prepared-input consumption to the temporary Python runtime

Implementation progress (2026-10-17): `agent/recon-python` imports the committed `minimal` package and lock at `1d1cb1516c1036fa61b7cf693815a3622f8f2567`. The dedicated entrypoint validates a versioned descriptor and source/export hashes, normalizes prepared Semgrep, opens CBM 0.12.1 without indexing, and registers architecture/search tools under the retained terminal preparation and output manager. Offline checks cover native queries, missing/wrong inputs, terminal restrictions and simulated Recon outcomes. `ReconService` and `PrepareProjectAnalysis` now connect launch, persistence and onboarding dispatch. No live Recon provider run was executed for this integration. See `docs/python-recon-integration.md`.

Make these changes only in the runtime copy packaged for Lailaps 2.

1. Add an explicit prepared-input descriptor argument. Validate source identity, artifact kinds/statuses, descriptor/schema versions and compatible tool/index versions before model admission.
2. Load Semgrep JSON and run the retained pure normalizer. Inject normalized signals, inventory and sensor status into `Deps`/Surface Context. Skip `_semgrep_signals`' command execution path.
3. Add an open-existing CBM path that uses `index_status` and query operations without `index_repository`. Replace the current unconditional disabled state with actual loaded-client/runtime availability.
4. Restore the minimum Recon graph tools needed to use that client: architecture and symbol/graph search. Reuse existing client methods where possible. Respect the role's tool preparation, terminal-turn restrictions, provenance tracking and output-size limits.
5. Preserve shell/source exploration already supported by the chosen Recon baseline. Do not accidentally enable target commands/HTTP access by attaching an application container; static Recon does not need it.
6. Preserve Python's existing history, retry and budget mechanisms. Record the selected prompt/tool contract version because graph tools change the request envelope.

### Missing data and fallback

An explicitly prepared partial/failed sensor can remain advisory/fail-open, with its limitations visible. A wrong-source artifact, corrupt index or missing supposedly-ready file is an integration error; stop instead of silently recomputing it or substituting another source.

Both checks matter: fail-open sensor policy does not excuse broken provenance.

**Done when:** fixture-driven Recon dependencies can load both inputs; graph tools return fixture symbols; subprocess spies show zero Semgrep executions and zero CBM indexing calls. In terminal mode investigative tools remain unavailable. No paid model invocation is needed for this verification.

## Task 6 — Supervise Recon and persist its output

Add a small `PythonReconService` that invokes the pinned runtime and imports structured output. Reuse the legacy Recon-only entrypoint and report generation; do not call the old Laravel benchmark command or port its benchmark database/evaluator.

1. Allocate the Recon artifact ID and freeze exact input artifact IDs, source hash, runtime version and model/budget configuration before launch.
2. Build the internal descriptor in an application-controlled temporary location. Map source and index paths explicitly; never accept arbitrary host mount destinations from model output.
3. Run Python with a bounded timeout and the required existing Docker/toolbox prerequisites. The trusted runtime may need Docker access; the model-controlled shell toolbox must retain its isolation and receive no Docker socket/provider credentials.
4. Use the existing structured outcome file for transport and readable stdout/stderr for logs. Do not parse console text as JSON events.
5. Laravel validates that the accepted Recon output, source/run identity, schema and termination status agree. Import the complete Recon result, limitations, usage, budget outcome and exact producer configuration. Missing/malformed output, technical failure, fallback or budget exhaustion must retain an explicit disposition; exit code alone cannot make it ready.
6. Publish the Recon artifact and remove disposable transport/runtime files after ingestion. A timeout/failure must not erase already published Semgrep/CBM products.

An HTTP callback is not required for P0: subprocess transport plus Laravel import satisfies the single-database-writer rule. Python never receives database credentials.

**Done when:** a fake process returning a valid outcome is imported, while malformed, wrong-execution and failed outcomes remain non-ready with diagnostic information. Published input artifacts survive each failure case.

## Task 7 — Connect onboarding with minimal queue orchestration

Implementation progress (2026-10-17): one concrete job carries only the source ID and resolves services in `handle`. It executes Semgrep, CBM and Recon sequentially, after commit and independently of Lab. Its timeout is 3600 seconds with one attempt and source overlap protection. Worker failure marks only existing unfinished analysis artifacts failed. Offline verification covers ordering, failed/partial sensors, optional inputs, source replacement, ready reuse, after-commit/rollback and overlap.

Add one `PrepareProjectAnalysis` job. Do not introduce a workflow engine or a chain that automatically aborts all analysis for an advisory sensor failure.

1. Dispatch it from project creation only after the source transaction and snapshot storage complete, independently of `LabSetup`.
2. Carry the exact source ID in the job. Load archived sources deliberately if a later upload happens before consumption; never switch to `latestSource` during execution.
3. Allocate or resolve and record artifact IDs before starting producer work. Reuse an existing ready Semgrep artifact for the exact source; failed or partial Semgrep preparation retries on its existing record. Run missing Semgrep preparation and CBM sequentially initially, finalize their individual outcomes, then launch Recon with those exact IDs.
4. Use a queue overlap lock, bounded process timeouts and a reservation lifetime longer than the job timeout. Prevent queue retries from multiplying the model's own retry policy; begin with one queue attempt.
5. Handle job failure/timeout by marking unfinished work failed, retaining successful artifacts and diagnostics. Ordinary cleanup uses `finally`; document that hard process termination is not guaranteed cleanup/resume.
6. Keep Lab readiness independent. No new client action, polling endpoint or UI screen is required by this persistence task.

For an eventual scan, load artifacts by explicit source and accepted IDs. Do not silently fall back to another source's newest result. Missing preparation is a visible missing input, not authorization to run an expensive agent implicitly.

**Done when:** fake-queue project creation dispatches both branches after commit, source replacement cannot redirect a queued task, and Recon failure leaves healthy environment/Semgrep/CBM artifacts intact.

## Task 8 — Verification and documentation

Use the smallest meaningful checks and follow the repository's test constraints. Do not add a default agent-loop test suite, run real provider calls, reset development data or lint the entire repository.

Offline acceptance checklist:

- Artifact JSON/file publication, same-ID repeat import and conflicting finalization.
- File publication succeeds but DB finalization fails: artifact is not consumable until re-import commits.
- Semgrep success with no matches, timeout, invalid JSON, incomplete coverage and rule/engine mismatch.
- CBM reopen from a fresh process with no `.git`, no original index working directory and no re-index.
- Python prepared mode performs no Semgrep scan or CBM indexing and rejects wrong-source inputs.
- Restored graph tools obey existing terminal-phase/tool and provenance constraints.
- Fake Recon success, missing output, fallback, budget exhaustion and process failure.
- Queue serialization, ownership, source pinning, after-commit dispatch and independent artifact outcomes.
- Session pruning cannot delete published preparation artifacts.

Native Semgrep/CBM smoke checks may use a small disposable fixture and no LLM calls. A paid end-to-end Recon run is a separate explicit validation by the developer.

Update `ARCHITECTURE.md` only as each structural change is actually implemented, keeping planned behavior out of the current-state description. Update `docs/development.md` with pinned image/binary build instructions, worker requirements, storage lifetime and offline verification commands. Update the temporary Python exception in AGENTS.md with Task 5/6, not as a claim that the bridge already exists.

## Scope of memory guarantees

P0 persists prepared products and terminal Recon output. Existing Python append-only history is retained inside its own execution; this roadmap does not promise durable message checkpoints or automatic restart after a crash.

Do not serialize Pydantic messages into AI SDK's message contract or use the Doctor session endpoint as if formats were interchangeable. A later conversation-persistence task must preserve native message types, provider metadata, committed tool-call/result boundaries and the corresponding budget state.

Reference: DeepSeek Harness `SessionHandle`, verified at `1517a4dbca0a2789ff1d41ba439600f8547d23fa`, distinguishes accepted ordered appends from an acknowledged durability barrier. Adopt the distinction, not the plugin architecture:
https://github.com/deepseek-ai/deepseek-harness/blob/1517a4dbca0a2789ff1d41ba439600f8547d23fa/packages/session/session-persistence/src/handle.ts

## P1+ deferred

- Cross-snapshot reuse, incremental index/Semgrep updates and change-focused Recon.
- Durable Python conversation checkpoints and recovery after interruption.
- Multiple worker hosts, image registry distribution and shared graph services.
- TypeScript Recon migration and alternative graph-backend evaluation.
- Preparation progress UI and automated artifact garbage collection.

## Implementation batches

1. Tasks 0–2: artifact contracts/registry/publication and environment output. No new model calls.
2. Tasks 3–4: initial Semgrep/CBM production and durable native artifacts. No model calls.
3. Task 5: Python consumes existing artifacts and restores minimum CBM tools. Verify with fixtures.
4. Tasks 6–8: supervised Recon output import, onboarding dispatch, offline acceptance and current-state documentation.

Finish each batch with its acceptance criteria before starting the next. Only the final integrated Recon execution requires a provider call, and it is not an autonomous implementation test.
