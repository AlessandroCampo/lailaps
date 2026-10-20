<?php

namespace App\Http\Controllers;

use App\Enums\AgentSessionStatus;
use App\Models\AgentSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentSessionController extends Controller
{
    public function show(AgentSession $agentSession): JsonResponse
    {
        $this->ensureActive($agentSession);

        return response()->json([
            'messages_json' => $agentSession->messages,
            'system_prompt' => $agentSession->system_prompt,
            'model' => $agentSession->model,
        ]);
    }

    public function update(Request $request, AgentSession $agentSession): JsonResponse
    {
        $this->ensureActive($agentSession);
        $data = $request->validate([
            'messages_json' => ['required', 'string', 'json', 'max:8000000'],
            'system_prompt' => ['required', 'string', 'max:100000'],
            'output' => ['nullable', 'array'],
        ]);
        $messages = json_decode($data['messages_json'], false, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($messages), 422, 'Messages must be a JSON array.');
        foreach ($messages as $message) {
            abort_unless(is_object($message) && in_array($message->role ?? null, ['user', 'assistant', 'tool'], true), 422, 'Invalid message role.');
        }
        abort_if($agentSession->system_prompt !== null && $agentSession->system_prompt !== $data['system_prompt'], 409, 'The session prompt cannot change.');
        $agentSession->update([
            'messages' => $data['messages_json'],
            'system_prompt' => $data['system_prompt'],
            'output' => $data['output'] ?? null,
        ]);

        return response()->json(['saved' => true]);
    }

    private function ensureActive(AgentSession $session): void
    {
        abort_unless($session->status === AgentSessionStatus::RUNNING && $session->expires_at->isFuture(), 410, 'The agent session is no longer active.');
    }
}
