"""Freeze existing outcomes for P0 recovery. Read-only to runs, zero inference."""
import argparse
from datetime import datetime, timezone
from pathlib import Path
import shutil

from pentest_agent.deduper import digest, write_json, RoleConfig, offline_preflight
from pentest_agent.reader_evaluation import (collect, load_json, ordered_items, relocate_artifacts,
    verify_collection, verify_source_snapshot, read_source_blob, declared_paths, DirectoryLocator)

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
destination = args.output.resolve()
if destination.exists():
    raise SystemExit('Use a new output directory; frozen inputs are never overwritten')
source_base = Path('C:/Users/Alessandro/Documents/Codex/2026-09-28/ok-vorrei-fare-la-seguente-cosa/outputs/reader-round/sources')
summary = {'captured_at': datetime.now(timezone.utc).isoformat(), 'inference_requests': 0,
           'scope': 'persisted outcomes; excludes unpublished in-flight work', 'series': {}}
for target in ('yeswiki', 'cacti'):
    output = destination / target
    output.mkdir(parents=True)
    historical = {}
    if target == 'yeswiki':
        original_root = ROOT / 'storage/app/reader-evaluation/p0-yeswiki-20260929-083526-290/current'
        collection = load_json(original_root / 'collection.json')
        verify_collection(collection)
        relocate_artifacts(collection, original_root)
        for ledger in ('dedup.json', 'semantic-ledger.json'):
            previous = load_json(original_root / ledger)
            historical[ledger] = {'ledger_sha256': digest(previous), 'version': previous['version'],
                                  'accounting': previous['accounting']}
    else:
        parent_run = 'cacti-reader-global-20260929-084906'
        parent_dir = ROOT / 'storage/app/runs/cacti/reader-global' / parent_run
        collection = collect(parent_dir / (parent_run + '-outcome.json'), target, output,
                             child_root=ROOT / 'storage/app/runs/cacti/reader-area')
        verify_collection(collection)
        previous = load_json(parent_dir / 'dedup.json')
        historical['dedup.json'] = {'ledger_sha256': digest(previous), 'version': previous['version'],
                                    'accounting': previous['accounting']}
    copied = {}
    def freeze_files(value):
        if isinstance(value, dict):
            if value.get('artifact_path'):
                path = Path(value['artifact_path'])
                if str(path) not in copied:
                    frozen = output / 'inputs' / path.name
                    frozen.parent.mkdir(parents=True, exist_ok=True)
                    if path.resolve() != frozen.resolve():
                        shutil.copyfile(path, frozen)
                    copied[str(path)] = str(frozen.resolve())
                value['artifact_path'] = copied[str(path)]
            for child in value.values():
                freeze_files(child)
        elif isinstance(value, list):
            for child in value:
                freeze_files(child)
    freeze_files(collection)
    collection['items'] = ordered_items(collection)
    collection['acquisition_order'] = [i['id'] for i in collection['items']]
    collection['historical_role_accounting'] = historical
    collection['frozen_at'] = summary['captured_at']
    collection['input_hash'] = digest({k: v for k, v in collection.items() if k != 'input_hash'})
    write_json(output / 'collection.json', collection)
    write_json(output / 'parent.json', collection['parent_data'])
    for child in collection['children']:
        write_json(output / 'children' / child['run_id'] / (child['run_id'] + '-outcome.json'), child['outcome'])
    cases = []
    for manifest_path in sorted((ROOT / 'agent/pentest-agent/benchmarks/targets' / target / 'manifests').glob('*.json')):
        manifest = load_json(manifest_path)
        pin = manifest['source'].get('commit', manifest['source'].get('snapshot'))
        if pin != collection['snapshot']:
            raise ValueError('Manifest snapshot mismatch')
        cases.extend(manifest.get('cases', []))
    write_json(output / 'manifests.json', {'source_snapshot': collection['snapshot'], 'cases': cases})
    # These remain the configured per-round envelopes. A recovery is explicit new funding,
    # with historical accounting separately retained. Resume never changes these caps.
    points = 250000 if target == 'yeswiki' else 1000000
    role = RoleConfig('z-ai/glm-5.3-flash', 'InferenceNet', points)
    config = {'project': target, 'snapshot': collection['snapshot'], 'source_snapshot': collection['snapshot'],
              'recovery_id': 'semantic-p0-20260929-v2', 'deduper': role.__dict__, 'evaluator': role.__dict__}
    write_json(output / 'roles.json', config)
    sizing = offline_preflight(collection['items'], role)
    identity = verify_source_snapshot(source_base / target, collection['snapshot'])
    checks = []
    for path in sorted(declared_paths({'originals': {i['id']: i for i in collection['items']}}, cases)):
        try:
            blob = read_source_blob(source_base / target, identity, path, 1, 1)
            checks.append({k: blob[k] for k in ('path', 'git_blob', 'file_sha256')})
        except DirectoryLocator as exc:
            checks.append({'path': path, 'status': 'excluded_directory', 'reason': str(exc)})
        except ValueError as exc:
            checks.append({'path': path, 'error': str(exc)})
    write_json(output / 'offline-verification.json', {'sizing': sizing, 'source': identity, 'source_checks': checks,
        'inference_requests': 0, 'quality': 'not_measured'})
    summary['series'][target] = {'products': len(collection['items']),
        'raw_leads': sum(i['kind'] == 'lead' for i in collection['items']),
        'peak_estimated_input_tokens': sizing['peak_estimated_input_tokens'], 'context_fits': sizing['complete'],
        'source_verified': identity['verified'], 'source_path_errors': [r for r in checks if 'error' in r],
        'collection_sha256': digest(collection), 'output': str(output)}
write_json(destination / 'summary.json', summary)
print(summary)
