<?php
/**
 * Admin settings – bearer token, instructions
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_MCP_Admin {

    const OPTION_TOKEN = 'wp_mcp_bearer_token';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function add_menu() {
        add_options_page(
            'WordPress MCP Server',
            'MCP Server',
            'manage_options',
            'wp-mcp-server',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting('wp_mcp_settings', self::OPTION_TOKEN, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $token = wp_mcp_ensure_token();
        $url   = rest_url('wp-mcp/v1/mcp');

        if (isset($_POST['wp_mcp_regenerate']) && check_admin_referer('wp_mcp_regenerate')) {
            $new = wp_generate_password(48, true, true);
            update_option(self::OPTION_TOKEN, $new);
            $token = $new;
            echo '<div class="notice notice-success"><p>New token generated.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>WordPress MCP Server</h1>

            <p>This plugin exposes your WordPress site as an <a href="https://modelcontextprotocol.io" target="_blank">MCP (Model Context Protocol)</a> server. AI clients like Cursor can connect and interact with your content.</p>

            <h2>Setup</h2>
            <ol>
                <li>Copy your <strong>Bearer token</strong> below (keep it secret).</li>
                <li>Add this MCP server to Cursor: <code>Settings → MCP → Add server</code></li>
                <li>Use <strong>Streamable HTTP</strong> or <strong>Fetch</strong> transport with the URL and token.</li>
            </ol>

            <table class="form-table">
                <tr>
                    <th>MCP endpoint URL</th>
                    <td><code><?php echo esc_html($url); ?></code></td>
                </tr>
                <tr>
                    <th>Bearer token</th>
                    <td>
                        <code style="word-break: break-all;"><?php echo esc_html($token ?: '(not set – generate below)'); ?></code>
                        <form method="post" style="display:inline; margin-left:12px;">
                            <?php wp_nonce_field('wp_mcp_regenerate'); ?>
                            <input type="hidden" name="wp_mcp_regenerate" value="1" />
                            <button type="submit" class="button">Regenerate token</button>
                        </form>
                    </td>
                </tr>
            </table>

            <h2>Cursor configuration</h2>
            <p>Add to your Cursor MCP config (Settings → Tools & MCP → Add new MCP server, or <code>.cursor/mcp.json</code>):</p>
            <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;overflow-x:auto;max-width:600px;">{
  "mcpServers": {
    "speakuplondon": {
      "type": "streamableHttp",
      "url": "<?php echo esc_html($url); ?>",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN_HERE"
      }
    }
  }
}</pre>
            <p>Replace <code>YOUR_TOKEN_HERE</code> with your actual bearer token. Restart Cursor after adding the server.</p>

            <h2>Available tools</h2>
            <ul>
                <li><strong>list_posts</strong> – List posts/pages</li>
                <li><strong>get_post</strong> – Get a single post by ID</li>
                <li><strong>create_post</strong> – Create a new post</li>
                <li><strong>update_post</strong> – Update an existing post</li>
                <li><strong>get_option</strong> – Get a WordPress option</li>
                <li><strong>list_plugins</strong> – List installed plugins</li>
                <li><strong>site_info</strong> – Site URL, name, WP version</li>
            </ul>

            <p><a href="https://modelcontextprotocol.io" target="_blank">Learn more about MCP →</a></p>
        </div>
        <?php
    }
}
