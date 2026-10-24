<?php

namespace App\Controllers\Api;

/**
 * HealthController — unauthenticated liveness probe for the control plane.
 * (Per-node Ollama health lives in ServerService; this is just "is the API up".)
 */
class HealthController extends BaseApiController
{
    public function ping()
    {
        return $this->ok([
            'service' => 'ollamahub',
            'time'    => date('c'),
        ], 'pong');
    }
}
