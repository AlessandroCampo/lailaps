import test from 'node:test';
import assert from 'node:assert/strict';
import { extractTerminalOutputs, indexTranscript, normalize, ratio, secondsBetween } from './freeze-python-c0.mjs';

test('missing/zero durations do not create throughput; timezone offsets align', () => {
  assert.equal(ratio(5, 0), null);
  assert.equal(ratio(null, 60), null);
  assert.equal(ratio(0, 60), 0);
  assert.equal(secondsBetween('2026-10-07T00:00:00+02:00', '2026-10-06T22:10:00Z'), 600);
  assert.equal(secondsBetween('invalid', '2026-10-07T00:00:00Z'), null);
});

test('global totals do not double-count category/role/lead projections; reported invalid rates remain inspectable', () => {
  const o = { run_id: 'fixture', started_at: '2026-10-07T00:00:00Z',
    report: { mode: 'global', confirmed: [], suspected: [{ lead_id: 'a', title: 'A' }], rejected_inconclusive: [],
      telemetry: { lead_count: 1, economic_points_used: 100, model_requests: 10, number_of_tool_calls: 4,
        total_model_input_tokens: 1000, total_model_output_tokens: 100, tokens_per_lead: 99999,
        role_usage: { reader: { economic_points: 100 } }, lead_usage: { a: { economic_points: 100, duration_seconds: 900 } },
        confirmer_first_pass_validation_rate: 4, confirmer_first_pass_validations: 4, confirmer_terminal_validation_attempts: 4,
        upstream_errors_by_role: { worker: 2 }, model_request_deadlines_by_role: { worker: 2 },
        exploration_tool_results: 4, exploration_error_results: 1 },
      passes: [{ pass_id: 'pass-001', started_at: '2026-10-07T00:00:00Z', finished_at: '2026-10-07T00:10:00Z',
        report: { telemetry: { economic_points_used: 100 } } }] },
    benchmark: { target_id: 'fixture', cost: { economic_points: 30, model_requests: 3, duration_seconds: 0 } } };
  const m = normalize(o, 'Agente interrotto: superato il timeout di 7200s\nAgente terminato con codice 124');
  assert.equal(m.totals.economic_points, 100);
  assert.equal(m.derived.leads_per_reported_pass_minute, 0.1);
  assert.equal(m.derived.tokens_per_lead, 1100);
  assert.equal(m.reported_telemetry.tokens_per_lead, 99999);
  assert.equal(m.derived.confirmer_validation_success_fraction, 1);
  assert.equal(m.reported_telemetry.confirmer_first_pass_validation_rate, 4);
  assert.equal(m.errors.upstream_events_per_model_request.value, 0.2);
  assert.equal(m.errors.exploration_error_fraction.value, 0.25);
  assert.equal(m.errors.global_tool_error_fraction, null);
  assert.equal(m.timing.actual_process_wall_seconds, null);
  assert.equal(m.timing.duration_comparison_eligibility, 'diagnostic_only_conflicting_clocks');
  assert.equal(m.lifecycle.process_exit_codes[0], 124);
});

test('concurrent tool calls remain unpaired and multi-line results retain line references', () => {
  const transcript = '[reader:tool] read_file(path="a")\n[reader:tool] list_dir()\n[reader:result] B\ncontinued\n[reader:result] A\n';
  const trace = indexTranscript(transcript);
  assert.equal(trace.records[2].line_start, 3);
  assert.equal(trace.records[2].line_end, 4);
  assert.equal(trace.records[0].tool_name, 'read_file');
  assert.equal(trace.prefix_counts['reader:tool'], 2);
  assert.equal(Object.hasOwn(trace.records[2], 'call_id'), false);
});

test('historical terminal output marker extracts JSON and preserves malformed output for diagnosis', () => {
  const transcript = '[confirmer:terminal-structured-output] {"decision":"suspected"}\n[worker:tool] http_call()\n[worker:terminal-structured-output] malformed JSON\n';
  const outputs = extractTerminalOutputs(transcript);
  assert.equal(outputs.length, 2);
  assert.deepEqual(outputs[0].payload, { decision: 'suspected' });
  assert.equal(outputs[1].payload, 'malformed JSON');
  assert.equal(outputs[0].line_start, 1);
});
