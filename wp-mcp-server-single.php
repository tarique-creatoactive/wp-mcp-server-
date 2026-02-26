<?php
/**
 * Plugin Name: WordPress MCP Server
 * Plugin URI: https://github.com/tarique-creatoactive/wp-mcp-server-
 * Description: Exposes your WordPress site as an MCP (Model Context Protocol) server so AI clients like Cursor can connect and interact with your content.
 * Version: 1.0.0
 * Author: Tarique Khan
 * License: GPL v2 or later
 * Text Domain: wp-mcp-server
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_MCP_VERSION', '1.0.0');
define('WP_MCP_GITHUB_REPO', 'tarique-creatoactive/wp-mcp-server-');

add_action('rest_api_init', 'wp_mcp_register_routes');
add_action('admin_init', 'wp_mcp_register_settings');
add_filter('pre_set_site_transient_update_plugins', 'wp_mcp_inject_update');
add_filter('plugins_api', 'wp_mcp_plugin_info', 20, 3);
add_action('admin_menu', 'wp_mcp_add_menu');
add_action('admin_init', 'wp_mcp_register_settings');

function wp_mcp_ensure_token() {
    $token = get_option('wp_mcp_bearer_token', '');
    if (empty($token)) {
        $token = wp_generate_password(48, true, true);
        update_option('wp_mcp_bearer_token', $token);
    }
    return $token;
}

function wp_mcp_register_routes() {
    register_rest_route('wp-mcp/v1', '/mcp', [
        'methods'             => ['GET', 'POST'],
        'callback'            => 'wp_mcp_handle_request',
        'permission_callback' => 'wp_mcp_check_auth',
        'args'                => [],
    ]);
}

function wp_mcp_check_auth($request) {
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

function wp_mcp_handle_request($request) {
    if ($request->get_method() === 'GET') {
        return new WP_Error('mcp_no_sse', 'This server does not support SSE. Use POST for JSON-RPC.', ['status' => 405]);
    }
    $body      = $request->get_body();
    $json      = json_decode($body, true);
    $rpc_method = $json['method'] ?? null;
    $rpc_id     = $json['id'] ?? null;
    $params     = $json['params'] ?? new stdClass();

    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error: invalid JSON']], 400);
    }

    $result = wp_mcp_dispatch($rpc_method, $params, $rpc_id);

    if (is_wp_error($result)) {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $rpc_id, 'error' => ['code' => $result->get_error_code() ?: -32603, 'message' => $result->get_error_message()]], 400);
    }
    if ($result === null) {
        return new WP_REST_Response(null, 202);
    }
    return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $rpc_id, 'result' => $result], 200);
}

function wp_mcp_dispatch($method, $params, $id) {
    switch ($method) {
        case 'initialize':
            return ['protocolVersion' => $params['protocolVersion'] ?? '2024-11-05', 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => ['name' => 'wp-mcp-server', 'version' => WP_MCP_VERSION]];
        case 'notifications/initialized':
            return null;
        case 'tools/list':
            return ['tools' => wp_mcp_get_tools()];
        case 'tools/call':
            return wp_mcp_call_tool($params);
        case 'ping':
            return [];
        default:
            return new WP_Error('method_not_found', "Unknown method: {$method}", ['status' => -32601]);
    }
}

function wp_mcp_get_tools() {
    static $tools = null;
    if ($tools !== null) return $tools;
    $tools = [
        ['name' => 'list_posts', 'description' => 'List recent posts. Optional: post_type, status, per_page, page.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_type' => ['type' => 'string', 'default' => 'post'], 'status' => ['type' => 'string', 'default' => 'publish'], 'per_page' => ['type' => 'integer', 'default' => 10], 'page' => ['type' => 'integer', 'default' => 1]]]],
        ['name' => 'get_post', 'description' => 'Get a single post or page by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
        ['name' => 'create_post', 'description' => 'Create a new post. Title and content required.', 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string', 'default' => 'draft'], 'post_type' => ['type' => 'string', 'default' => 'post']], 'required' => ['title', 'content']]],
        ['name' => 'update_post', 'description' => 'Update an existing post.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string']], 'required' => ['id']]],
        ['name' => 'get_option', 'description' => 'Get a WordPress option value.', 'inputSchema' => ['type' => 'object', 'properties' => ['option' => ['type' => 'string']], 'required' => ['option']]],
        ['name' => 'list_plugins', 'description' => 'List installed plugins with name, status, version.', 'inputSchema' => ['type' => 'object', 'properties' => []]],
        ['name' => 'site_info', 'description' => 'Get site URL, name, description, WordPress version.', 'inputSchema' => ['type' => 'object', 'properties' => []]],
    ];
    return $tools;
}

function wp_mcp_call_tool($params) {
    $name      = $params['name'] ?? '';
    $arguments = $params['arguments'] ?? [];
    $tools     = wp_mcp_get_tools();
    $def       = null;
    foreach ($tools as $t) {
        if ($t['name'] === $name) { $def = $t; break; }
    }
    if (!$def) return new WP_Error('unknown_tool', "Unknown tool: {$name}");
    $handler = 'wp_mcp_tool_' . str_replace(['-', '.'], '_', $name);
    if (!function_exists($handler)) return new WP_Error('tool_error', "Tool {$name} has no handler.");
    try {
        $result = $handler($arguments);
        if (is_wp_error($result)) {
            return ['content' => [['type' => 'text', 'text' => $result->get_error_message()]], 'isError' => true];
        }
        return ['content' => [['type' => 'text', 'text' => is_string($result) ? $result : wp_json_encode($result)]], 'isError' => false];
    } catch (Exception $e) {
        return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
    }
}

function wp_mcp_tool_list_posts($args) {
    $posts = get_posts(['post_type' => $args['post_type'] ?? 'post', 'post_status' => $args['status'] ?? 'publish', 'posts_per_page' => min((int)($args['per_page'] ?? 10), 50), 'paged' => max(1, (int)($args['page'] ?? 1)), 'orderby' => 'date', 'order' => 'DESC']);
    $out = [];
    foreach ($posts as $p) {
        $out[] = ['id' => $p->ID, 'title' => $p->post_title, 'date' => $p->post_date, 'status' => $p->post_status, 'post_type' => $p->post_type, 'permalink' => get_permalink($p)];
    }
    return wp_json_encode($out, JSON_PRETTY_PRINT);
}

function wp_mcp_tool_get_post($args) {
    $id = (int)($args['id'] ?? 0);
    if (!$id) return new WP_Error('invalid_id', 'Post ID required');
    $post = get_post($id);
    if (!$post) return new WP_Error('not_found', "Post {$id} not found");
    return wp_json_encode(['id' => $post->ID, 'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'status' => $post->post_status, 'date' => $post->post_date, 'post_type' => $post->post_type, 'permalink' => get_permalink($post)], JSON_PRETTY_PRINT);
}

function wp_mcp_tool_create_post($args) {
    $title = $args['title'] ?? '';
    if (empty($title)) return new WP_Error('invalid', 'Title required');
    $author = get_current_user_id();
    if (!$author) {
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        $author = !empty($admins) ? $admins[0]->ID : 1;
    }
    $id = wp_insert_post([
        'post_title'   => $title,
        'post_content' => $args['content'] ?? '',
        'post_status'  => $args['status'] ?? 'draft',
        'post_type'    => $args['post_type'] ?? 'post',
        'post_author'  => $author,
    ], true);
    return is_wp_error($id) ? $id : "Created post #{$id}: " . get_permalink($id);
}

function wp_mcp_tool_update_post($args) {
    $id = (int)($args['id'] ?? 0);
    if (!$id) return new WP_Error('invalid', 'Post ID required');
    $post = get_post($id);
    if (!$post) return new WP_Error('not_found', "Post {$id} not found");
    $data = ['ID' => $id];
    if (isset($args['title'])) $data['post_title'] = $args['title'];
    if (isset($args['content'])) $data['post_content'] = $args['content'];
    if (isset($args['status'])) $data['post_status'] = $args['status'];
    $result = wp_update_post($data, true);
    return is_wp_error($result) ? $result : "Updated post #{$id}: " . get_permalink($id);
}

function wp_mcp_tool_get_option($args) {
    $option = $args['option'] ?? '';
    if (empty($option)) return new WP_Error('invalid', 'Option name required');
    return wp_json_encode(['option' => $option, 'value' => get_option($option)]);
}

function wp_mcp_tool_list_plugins($args) {
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $all = get_plugins();
    $active = get_option('active_plugins', []);
    $out = [];
    foreach ($all as $path => $data) {
        $out[] = ['name' => $data['Name'], 'version' => $data['Version'], 'active' => in_array($path, $active, true), 'path' => $path];
    }
    return wp_json_encode($out, JSON_PRETTY_PRINT);
}

function wp_mcp_tool_site_info($args) {
    return wp_json_encode(['name' => get_bloginfo('name'), 'description' => get_bloginfo('description'), 'url' => home_url(), 'wp_version' => get_bloginfo('version')], JSON_PRETTY_PRINT);
}

function wp_mcp_add_menu() {
    add_options_page('WordPress MCP Server', 'MCP Server', 'manage_options', 'wp-mcp-server', 'wp_mcp_render_page');
}

function wp_mcp_register_settings() {
    register_setting('wp_mcp_settings', 'wp_mcp_bearer_token', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
}

function wp_mcp_get_github_release() {
    $cached = get_transient('wp_mcp_github_release');
    if ($cached !== false) {
        return $cached;
    }
    $resp = wp_remote_get('https://api.github.com/repos/' . WP_MCP_GITHUB_REPO . '/releases/latest', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github.v3+json'],
    ]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        set_transient('wp_mcp_github_release', ['version' => WP_MCP_VERSION], 3600);
        return null;
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $version = ltrim($body['tag_name'] ?? '', 'v');
    $zip_url = null;
    foreach ($body['assets'] ?? [] as $a) {
        if (isset($a['browser_download_url']) && substr($a['name'], -4) === '.zip') {
            $zip_url = $a['browser_download_url'];
            break;
        }
    }
    $data = ['version' => $version, 'zip_url' => $zip_url, 'url' => $body['html_url'] ?? '', 'changelog' => $body['body'] ?? ''];
    set_transient('wp_mcp_github_release', $data, 43200);
    return $data;
}

function wp_mcp_inject_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }
    $release = wp_mcp_get_github_release();
    if (!$release || !isset($release['zip_url']) || version_compare(WP_MCP_VERSION, $release['version'], '>=')) {
        return $transient;
    }
    $slug = plugin_basename(__FILE__);
    $transient->response[$slug] = (object) [
        'slug'        => 'wp-mcp-server',
        'plugin'      => $slug,
        'new_version' => $release['version'],
        'url'         => 'https://github.com/' . WP_MCP_GITHUB_REPO,
        'package'     => $release['zip_url'],
        'icons'       => [],
        'banners'     => [],
        'banners_rgb' => [],
        'tested'      => '',
        'requires_php'=> '7.4',
        'compatibility' => new stdClass(),
    ];
    return $transient;
}

function wp_mcp_plugin_info($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'wp-mcp-server') {
        return $result;
    }
    $release = wp_mcp_get_github_release();
    if (!$release) {
        return $result;
    }
    return (object) [
        'name'          => 'WordPress MCP Server',
        'slug'          => 'wp-mcp-server',
        'version'       => $release['version'],
        'author'        => 'Tarique Khan',
        'homepage'      => 'https://github.com/' . WP_MCP_GITHUB_REPO,
        'requires'      => '5.0',
        'tested'       => '6.4',
        'requires_php'  => '7.4',
        'last_updated'  => '',
        'sections'     => ['description' => 'Exposes your WordPress site as an MCP server for AI clients like Cursor.', 'changelog' => $release['changelog'] ?: 'See GitHub releases.'],
        'download_link' => $release['zip_url'],
    ];
}

function wp_mcp_render_page() {
    if (!current_user_can('manage_options')) return;
    $token = wp_mcp_ensure_token();
    $url = rest_url('wp-mcp/v1/mcp');
    if (isset($_POST['wp_mcp_regenerate']) && check_admin_referer('wp_mcp_regenerate')) {
        $token = wp_generate_password(48, true, true);
        update_option('wp_mcp_bearer_token', $token);
        echo '<div class="notice notice-success"><p>New token generated.</p></div>';
    }
    if (isset($_GET['wp_mcp_clear_cache']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wp_mcp_clear_cache')) {
        delete_transient('wp_mcp_github_release');
        echo '<div class="notice notice-success"><p>Update cache cleared. Check Plugins page for updates.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>WordPress MCP Server</h1>
        <p>This plugin exposes your WordPress site as an <a href="https://modelcontextprotocol.io" target="_blank">MCP</a> server. AI clients like Cursor can connect and interact with your content.</p>
        <h2>Setup</h2>
        <ol><li>Copy your <strong>Bearer token</strong> below.</li><li>Add this MCP server to Cursor: Settings → MCP → Add server</li><li>Use Streamable HTTP with the URL and token.</li></ol>
        <table class="form-table">
            <tr><th>MCP endpoint URL</th><td><code><?php echo esc_html($url); ?></code></td></tr>
            <tr><th>Bearer token</th><td>
                <code style="word-break:break-all;"><?php echo esc_html($token ?: '(not set)'); ?></code>
                <form method="post" style="display:inline;margin-left:12px;"><?php wp_nonce_field('wp_mcp_regenerate'); ?><input type="hidden" name="wp_mcp_regenerate" value="1" /><button type="submit" class="button">Regenerate token</button></form>
            </td></tr>
        </table>
        <p><strong>Updates:</strong> Checks <a href="https://github.com/<?php echo esc_attr(WP_MCP_GITHUB_REPO); ?>/releases" target="_blank">GitHub</a> for updates. Attach <code>wp-mcp-server.zip</code> to releases. <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('wp_mcp_clear_cache', '1'), 'wp_mcp_clear_cache')); ?>">Clear update cache</a></p>
        <h2>Cursor config</h2>
        <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;max-width:600px;">{
  "mcpServers": {
    "speakuplondon": {
      "type": "streamableHttp",
      "url": "<?php echo esc_html($url); ?>",
      "headers": { "Authorization": "Bearer YOUR_TOKEN_HERE" }
    }
  }
}</pre>
    </div>
    <?php
}
