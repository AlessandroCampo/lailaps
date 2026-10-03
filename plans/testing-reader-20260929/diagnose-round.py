"""Read-only run snapshot and simulated context preflight; never calls a model."""
import asyncio
from collections import Counter
from datetime import datetime
from decimal import Decimal
import hashlib
import json
from pathlib import Path

from pentest_agent.deduper import Deduper, RoleConfig
from pentest_agent.reader_evaluation import qualify_output
from pydantic_ai.usage import RunUsage

ROOT = Path(__file__).resolve().parents[2]
STAMP = datetime.now().astimezone().isoformat(timespec="seconds")
OUT = ROOT / 'storage/app/reader-evaluation' / ('diagnostic-' + datetime.now().strftime('%Y%m%d-%H%M%S'))
OUT.mkdir(parents=True)

def frozen(path):
    raw = path.read_bytes()
    dest = OUT / 'inputs' / path.relative_to(ROOT)
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_bytes(raw)
    return json.loads(raw.decode('utf-8-sig')), hashlib.sha256(raw).hexdigest()

result = {'captured_at': STAMP, 'scope': 'persisted child snapshots; excludes unpublished in-flight usage', 'series': {}, 'roles': {}}
items_by_target = {}
for target, parent_id in [('yeswiki', 'yeswiki-reader-global-20260929-083528'), ('cacti', 'cacti-reader-global-20260929-084906')]:
    parent, _ = frozen(ROOT / 'storage/app/runs' / target / 'reader-global' / parent_id / (parent_id + '-outcome.json'))
    report = parent['report']
    children = []
    items = []
    for mapping in report['mapped_children']:
        run = mapping['run_id']
        p = ROOT / 'storage/app/runs' / target / 'reader-area' / run / (run + '-outcome.json')
        child, sha = frozen(p)
        r = child.get('report') or {}
        rows = {o['output_id']: o for o in r.get('structured_outputs', [])
                if o.get('role') == 'reader' and o.get('output_type') in ['ReaderLead', 'AreaEnrichmentLead']
                and (o.get('accepted') or o.get('review_status') == 'pending_review')}
        lead_count = sum(o['output_type'] == 'ReaderLead' and o.get('accepted', False) for o in rows.values())
        points = r.get('telemetry', {}).get('economic_points_used')
        children.append({'run_id': run, 'area_id': mapping.get('area_id'), 'sha256': sha,
                         'leads': lead_count, 'enrichments': sum(o['output_type'] == 'AreaEnrichmentLead' for o in rows.values()),
                         'economic_points': points, 'publication': r.get('publication_state'), 'termination': r.get('termination_reason')})
        items.extend(qualify_output(run, o, target, report['source_snapshot']) for o in rows.values())
    points = sum(Decimal(str(c['economic_points'] or 0)) for c in children)
    leads = sum(c['leads'] for c in children)
    result['series'][target] = {'parent_run_id': parent_id, 'children': children, 'raw_accepted_leads': leads,
        'reader_ep': str(points), 'raw_leads_per_100k_reader_ep': str(Decimal(leads)*100000/points),
        'reader_ep_per_raw_lead': str(points/leads), 'all_children_final': all(c['publication']=='final' for c in children),
        'parent_coverage': report.get('coverage'), 'parent_persisted_ratio': (parent.get('benchmark') or {}).get('accepted_leads_per_100k_points')}
    items_by_target[target] = items

role_paths = {
    'yeswiki_deduper': 'storage/app/reader-evaluation/p0-yeswiki-20260929-083526-290/current/dedup.json',
    'yeswiki_evaluator': 'storage/app/reader-evaluation/p0-yeswiki-20260929-083526-290/current/semantic-ledger.json',
    'cacti_deduper': 'storage/app/runs/cacti/reader-global/cacti-reader-global-20260929-084906/dedup.json',
}
for role, relative in role_paths.items():
    state, sha = frozen(ROOT/relative)
    rows = list(state.get('decisions', state.get('judgments', {})).values())
    result['roles'][role] = {'path': relative, 'sha256': sha, 'config': state['config'],
        'statuses': dict(Counter(r.get('dedup_status', r.get('status')) for r in rows)),
        'errors': dict(Counter(e['type'] for e in state['accounting']['errors'])),
        'reasons': dict(Counter(r.get('reason', r.get('decision', {}).get('reason', '')) for r in rows
                              if r.get('dedup_status', r.get('status'))=='inconclusive')),
        'accounting': {k:v for k,v in state['accounting'].items() if k not in ['errors','preflights']}}

async def caps():
    simulated = {}
    for target, items in items_by_target.items():
        simulated[target] = []
        for cap in [24000, 48000, 64000]:
            async def fake(package, adapter, prompt):
                return {'decision':'pass'}, RunUsage(input_tokens=1, output_tokens=1)
            service = Deduper(target, items[0]['snapshot'], RoleConfig('z-ai/glm-5.3-flash','InferenceNet',100000000,input_cap=cap), request=fake)
            results = [await service.acquire(i) for i in items]
            simulated[target].append({'input_cap':cap,'items':len(items),
                'inconclusive':sum(r['dedup_status']=='inconclusive' for r in results),
                'peak_estimated_request':max((r['predicted_uncached_input_tokens'] for r in service.runner.accounting.get('preflights',[])),default=0)})
    return simulated

result['context_simulation_all_pass_not_quality_test'] = asyncio.run(caps())
result['prior_1117_snapshot'] = {'raw_leads':93, 'reader_ep':'2904229.3712',
    'raw_leads_per_100k_reader_ep':str(Decimal(93)*100000/Decimal('2904229.3712')),
    'reader_ep_per_raw_lead':str(Decimal('2904229.3712')/93)}
(OUT/'summary.json').write_text(json.dumps(result, indent=2, ensure_ascii=False),encoding='utf-8')
print(OUT)
print(json.dumps({k:v for k,v in result.items() if k!='roles'},ensure_ascii=True))
print(json.dumps({k:{f:v[f] for f in ['statuses','errors','reasons']} for k,v in result['roles'].items()},ensure_ascii=True))
