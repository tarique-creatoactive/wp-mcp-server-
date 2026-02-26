<?php
/**
 * MCP REST API handler – JSON-RPC over HTTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_MCP_REST {

    const PROTOCOL_VERSION = '2024-11-05';
    const NAMESPACE = 'wp-mcp/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route(self::NAMESPACE, '/mcp', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [__CLASS__, 'handle_request'],
            'permission_callback' => [__CLASS__, 'check_auth'],
            'args'                => [],
        ]);
    }

    public static function check_auth($request) {
        $token = wp_mcp_ensure_token();
        if (empty($token)) {
            return new WP_Error('mcp_no_token', 'MCP is disabled: no bearer token configured.', ['status' => 503]);
        }

        $auth = $request->get_header('Authorization');
        if (!$auth || !preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return new WP_Error('mcp_unauthorized', 'Missing or invalid Authorization header.', ['status' => 401]);
        }

        if (!hash_equals($token, trim($m[1]))) {
            return new WP_Error('mcp_forbidden', 'Invalid bearer token.', ['status' => 403]);
        }

        return true;
    }

    public static function handle_request($request) {
        $method = $request->get_method();

        if ($method === 'GET') {
            return self::handle_sse($request);
        }

        $body = $request->get_body();
        $json = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_REST_Response(['jsonrpc' => '2.0', 'error' => [
                'code'    => -32700,
                'message' => 'Parse error: invalid JSON',
            ]], 400);
        }

        $rpc_method = $json['method'] ?? null;
        $rpc_id     = $json['id'] ?? null;
        $params     = $json['params'] ?? new stdClass();

        $result = self::dispatch($rpc_method, $params, $rpc_id);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'jsonrpc' => '2.0',
                'id'      => $rpc_id,
                'error'   => [
                    'code'    => $result->get_error_code() ?: -32603,
                    'message' => $result->get_error_message(),
                ],
            ], 400);
        }

        if ($result === null) {
            return new WP_REST_Response(null, 202);
        }

        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'id'      => $rpc_id,
            'result'  => $result,
        ], 200);
    }

    private static function handle_sse($request) {
        return new WP_Error('mcp_no_sse', 'This server does not support SSE. Use POST for JSON-RPC.', ['status' => 405]);
    }

    private static function dispatch($method, $params, $id) {
        switch ($method) {
            case 'initialize':
                return self::handle_initialize($params);
            case 'notifications/initialized':
                return null;
            case 'tools/list':
                return WP_MCP_Tools::list_tools($params);
            case 'tools/call':
                return WP_MCP_Tools::call_tool($params);
            case 'ping':
                return [];
            default:
                return new WP_Error('method_not_found', "Unknown method: {$method}", ['status' => -32601]);
        }
    }

    private static function handle_initialize($params) {
        $requested = $params['protocolVersion'] ?? '2024-11-05';
        return [
            'protocolVersion' => $requested,
            'capabilities'   => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => 'wp-mcp-server',
                'version' => WP_MCP_VERSION,
            ],
        ];
    }
}
