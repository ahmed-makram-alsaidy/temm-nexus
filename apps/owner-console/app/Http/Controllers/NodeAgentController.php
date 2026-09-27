<?php

namespace App\Http\Controllers;

use App\Models\InfrastructureNode;
use App\Services\ControlPlane\NodeHeartbeatService;
use Illuminate\Http\Request;

/**
 * Phase 21B: node agent channel. Agents authenticate with a per-node bearer
 * token (sha256 hash stored server-side, rotatable) and push status only.
 * There is deliberately NO endpoint here that runs anything on the node —
 * remote actions are limited to the allowlisted, signed command envelope
 * described in docs/NODE_AGENT.md (not implemented in this phase).
 */
class NodeAgentController extends Controller
{
    protected function node(Request $request): InfrastructureNode
    {
        $token = $request->bearerToken() ?: (string) $request->header('X-Node-Token', '');
        abort_if($token === '', 401, 'Node token required.');
        // Prefix narrows the candidate set; constant-time hash decides.
        $candidates = InfrastructureNode::query()
            ->where('token_prefix', substr($token, 0, 12))
            ->get();
        foreach ($candidates as $node) {
            if ($node->tokenMatches($token)) {
                abort_if(! $node->enabled, 403, 'Node is disabled.');

                return $node;
            }
        }
        abort(401, 'Invalid node token.');
    }

    /** POST /cp-nodes/heartbeat — agent status push. */
    public function heartbeat(Request $request)
    {
        $node = $this->node($request);
        $data = $request->validate([
            'cpu' => 'nullable|numeric|min:0|max:100',
            'ram' => 'nullable|numeric|min:0|max:100',
            'disk' => 'nullable|numeric|min:0|max:100',
            'agent_version' => 'nullable|string|max:30',
            'services' => 'sometimes|array|max:50',
            'services.*.key' => 'required|string|max:40',
            'services.*.status' => 'sometimes|string|max:16',
            'services.*.version' => 'nullable|string|max:60',
            'services.*.endpoint' => 'nullable|string|max:255',
        ]);

        return response()->json([
            'status' => 'ok',
            'result' => NodeHeartbeatService::ingest($node, $data),
            'allowed_remote_actions' => config('infrastructure.allowed_remote_actions', []),
        ]);
    }

    /** GET /cp-nodes/config — non-secret agent operating parameters. */
    public function agentConfig(Request $request)
    {
        $node = $this->node($request);

        return response()->json([
            'status' => 'ok',
            'node' => $node->name,
            'heartbeat_interval_s' => 30,
            'thresholds' => config('infrastructure.heartbeat'),
        ]);
    }
}
