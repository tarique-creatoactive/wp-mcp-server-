# WordPress MCP Server

A WordPress plugin that exposes your site as an **MCP (Model Context Protocol)** server. AI clients like Cursor can connect and interact with your WordPress content (posts, pages, options, plugins).

## Installation

1. **Zip the plugin** – Create a zip of the `wordpress-mcp-plugin` folder (the folder name in the zip should be `wordpress-mcp-plugin`).
2. **Upload to WordPress** – Plugins → Add New → Upload Plugin → Choose the zip.
3. **Activate** – Activate "WordPress MCP Server".
4. **Configure** – Go to Settings → MCP Server. Copy your Bearer token (it's auto-generated on activation).

## Cursor Setup

1. Open Cursor Settings → **Tools & MCP** → **Add new MCP server**.
2. Choose **URL** / **streamableHttp** type.
3. Set:
   - **URL:** `https://yoursite.com/wp-json/wp-mcp/v1/mcp`
   - **Headers:** `Authorization: Bearer YOUR_TOKEN`
4. Restart Cursor.

Or add to `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "yoursite": {
      "type": "streamableHttp",
      "url": "https://yoursite.com/wp-json/wp-mcp/v1/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN_HERE"
      }
    }
  }
}
```

## Available Tools

| Tool          | Description                              |
|---------------|------------------------------------------|
| `list_posts`  | List posts/pages (pagination, filters)   |
| `get_post`    | Get a single post by ID                  |
| `create_post` | Create a new post                       |
| `update_post` | Update an existing post                  |
| `get_option`  | Get a WordPress option                  |
| `list_plugins` | List installed plugins                  |
| `site_info`   | Site URL, name, WP version              |

## WP Engine Notes

- Works on WP Engine. Ensure the REST API is not disabled.
- If you use WP Engine's "Disable REST API" or similar security plugins, whitelist `/wp-json/wp-mcp/`.
- Keep your Bearer token private. Regenerate it from Settings → MCP Server if it's ever exposed.

## GitHub Updates

The plugin checks [GitHub releases](https://github.com/tarique-creatoactive/wp-mcp-server-/releases) for updates. To release a new version:

1. Update the version in `wp-mcp-server-single.php` (line 7 and 17)
2. Build the zip: zip only `wp-mcp-server.php` (or `wp-mcp-server/wp-mcp-server.php` folder structure)
3. Create a [GitHub Release](https://github.com/tarique-creatoactive/wp-mcp-server-/releases/new)
4. Tag: `v1.0.1` (must match version in plugin)
5. Attach `wp-mcp-server.zip` as a release asset
6. Publish. WordPress will show the update in Plugins → Updates

## Security

- All requests require a valid Bearer token.
- Token is generated on activation; regenerate from the plugin settings if needed.
- Only use over HTTPS in production.
