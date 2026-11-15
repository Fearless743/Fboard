<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\WebSocket\NodeWorker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    /**
     * server handshake api
     */
    public function handshake(Request $request): JsonResponse
    {
        $websocket = ['enabled' => false];

        if ((bool) admin_setting('server_ws_enable', 1) && Cache::has(NodeWorker::HEARTBEAT_CACHE_KEY)) {
            $customUrl = trim((string) admin_setting('server_ws_url', ''));

            if ($customUrl !== '') {
                $wsUrl = rtrim($customUrl, '/');
            } else {
                $wsScheme = $request->isSecure() ? 'wss' : 'ws';
                $wsUrl = "{$wsScheme}://{$request->getHttpHost()}/ws";
            }

            $websocket = [
                'enabled' => true,
                'ws_url' => $wsUrl,
            ];
        }

        return response()->json([
            'websocket' => $websocket
        ]);
    }

    /**
     * node report api - merge traffic + alive + status + metrics
     */
    public function report(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');

        // Idempotent traffic accounting: a node retries the same report when
        // the HTTP response is lost. Without this key the retry would count
        // the same traffic twice and could push users over quota early.
        $reportId = trim((string) $request->input('report_id', ''));
        $reportKey = null;
        if ($reportId !== '') {
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $reportId)) {
                abort(422, 'Invalid report_id');
            }
            $reportKey = "server_report:{$node->id}:{$reportId}";
            // Cache::add is atomic: the first request wins, duplicates skip.
            if (!Cache::add($reportKey, 1, 3600)) {
                return response()->json(['data' => true, 'duplicate' => true]);
            }
        }

        try {
            ServerService::touchNode($node);

            $traffic = $request->input('traffic');
            if (is_array($traffic) && !empty($traffic)) {
                ServerService::processTraffic($node, $traffic);
            }

            $alive = $request->input('alive');
            if (is_array($alive) && !empty($alive)) {
                ServerService::processAlive($node->id, $alive);
            }

            // online 允许空数组：表示当前无在线用户，需清零 ONLINE_USER
            $online = $request->input('online');
            if (is_array($online)) {
                ServerService::processOnline($node, $online);
            }

            $status = $request->input('status');
            if (is_array($status) && !empty($status)) {
                ServerService::processStatus($node, $status);
            }

            $metrics = $request->input('metrics');
            if (is_array($metrics) && !empty($metrics)) {
                ServerService::updateMetrics($node, $metrics);
            }
        } catch (\Throwable $e) {
            // Processing failed before completion: allow the node to retry.
            if ($reportKey !== null) {
                Cache::forget($reportKey);
            }
            throw $e;
        }

        return response()->json(['data' => true]);
    }
}
