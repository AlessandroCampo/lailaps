# Development snapshots

`dev:snapshot` preserves local development records and project files across a manual schema reset. It is available only in local/testing environments. It does not run migrations, change model definitions, start containers or dispatch preparation jobs.

## Export and verify

Finish queued preparations/audits, then stop queue workers and avoid application writes until export finishes.

```bash
php artisan dev:snapshot export
php artisan dev:snapshot verify /absolute/path/to/snapshot
```

Export prints its absolute directory. The directory contains `projects.tar` and encrypted `snapshot.enc`. Keep both files and the original `.env`/`APP_KEY`; do not run `key:generate`. The archive can contain private application credentials and must remain private. Copy the snapshot outside this checkout before deleting or replacing the checkout/storage volume.

Version 2 snapshots include users, projects, sources (including archived records), Doctor sessions, labs, project artifacts, audit runs, the run/artifact pivot, Recon areas, run areas and run leads when those tables exist. They include the corresponding project directories: uploaded sources, Doctor recipes/workspaces, indexes and agent output. Existing local Docker images remain in Docker; images/volumes are not exported. Temporary queues, login sessions, cache and orphan storage directories are not restored. Version 1 snapshots remain readable; the newer tables are empty when absent from that older backup.

Verified snapshot created on 2026-10-18:

```text
/home/alessandro/projects/lailaps-2/storage/app/private/dev-snapshots/20261018-111304-01M57BE6CVRVVJ7V8SYSEP67RY
```

It contains 2 users, 4 projects, 4 sources, 6 Doctor sessions, 4 labs and 12 artifacts. No audit runs existed at export time.

A newer version 2 snapshot was verified before the run-persistence schema update:

```text
/home/alessandro/projects/lailaps-2/storage/app/private/dev-snapshots/20261018-171033-01M57ZWRCXTXSP1W1TQDPRD2GS
```

It additionally preserves the existing audit run, its 3 artifact associations and
11 Recon-area definitions. Run-area and lead tables were absent at export time and
are represented as empty. This backup includes the completed run's original outcome,
runtime log and investigator journals. Keep it until the manual result import has
been checked.

## Restore after a manual reset

Complete and check the new migrations/model definitions first. Run the reset yourself, without seeding application records, then restore into empty application tables:

```bash
php artisan migrate:fresh
php artisan dev:snapshot restore /absolute/path/to/snapshot
```

The restore command refuses nonempty application tables. It inserts raw rows without model events, restores encrypted values unchanged and resets PostgreSQL integer sequences. Removed columns are ignored; new columns must accept defaults/null or be handled in the new schema. Relationships through the run/artifact pivot, Recon definitions, run areas and leads are restored in dependency order. An older run-area row without `area_key` takes its key from the linked Recon area; the new pass key defaults to `pass-001`. For legacy Lab rows without a Doctor reference, restore links the latest matching successful Doctor session, preserving its encrypted environment and verification timestamp. Future setup attempts persist this reference directly.

Integer primary keys are retained when the target schema uses integers. When users, projects, sources, labs, artifacts or audit runs use ULID keys, restore uses stable mappings saved in the snapshot. An existing audit `public_id` is retained as its new ULID primary key. Agent session UUIDs stay unchanged. Foreign keys, generated storage paths and prepared Recon/run identity envelopes are remapped; source content and model narrative remain unchanged. Changed IDs are printed on completion.

Original directories are kept. Restore publishes copies under the mapped identifiers and refuses to replace differing files. If DB insertion fails, fix the schema and retry against empty application tables: identical files already published by the same snapshot can be reused.

## Before switching Source IDs to ULID

These schema/runtime changes are separate from backup/restore:

- Use `$table->ulid('id')->primary()`, not `$table->ulid()`; the latter creates a column named `ulid`.
- Add `HasUlids` to the intended models and align every FK type. Explicit `foreignId(...)` declarations, including those in `agent_sessions`, remain integers unless changed.
- Create `agent_sessions` before adding a Lab FK to that table, or add that constraint in a later migration.
- PHP Source ID parameters and Python prepared input/output contracts now accept canonical ULID Source IDs. Artifact IDs remain integers. Use the rebuilt `-ulid` runtime images; old images still require integer Source IDs.

Restore was checked against integer and ULID fixtures and the real snapshot using an isolated in-memory database and separate temporary project storage. PostgreSQL raw insertion and sequence reset were checked in a separate temporary schema. No application DB reset or provider/model calls were used.
