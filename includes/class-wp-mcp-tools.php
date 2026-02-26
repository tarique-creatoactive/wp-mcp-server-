<?php
/**
 * MCP tools – WordPress operations exposed to AI clients
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_MCP_Tools {

    private static $tools = null;

    public static function list_tools($params) {
        return [
            'tools' => self::get_tools(),
        ];
    }

    public static function call_tool($params) {
        $name      = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        $tools = self::get_tools();
        $def   = null;
        foreach ($tools as $t) {
            if ($t['name'] === $name) {
                $def = $t;
                break;
            }
        }

        if (!$def) {
            return new WP_Error('unknown_tool', "Unknown tool: {$name}");
        }

        $handler = 'tool_' . str_replace(['-', '.'], '_', $name);
        if (!method_exists(__CLASS__, $handler)) {
            return new WP_Error('tool_error', "Tool {$name} has no handler.");
        }

        try {
            $result = call_user_func([__CLASS__, $handler], $arguments);
            if (is_wp_error($result)) {
                return [
                    'content' => [['type' => 'text', 'text' => $result->get_error_message()]],
                    'isError' => true,
                ];
            }
            return [
                'content' => [['type' => 'text', 'text' => is_string($result) ? $result : wp_json_encode($result)]],
                'isError' => false,
            ];
        } catch (Exception $e) {
            return [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    private static function get_tools() {
        if (self::$tools !== null) {
            return self::$tools;
        }

        self::$tools = [
            [
                'name'        => 'list_posts',
                'description' => 'List recent posts. Optional: post_type, status, per_page, page.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => ['type' => 'string', 'default' => 'post', 'description' => 'post, page, or custom type'],
                        'status'    => ['type' => 'string', 'default' => 'publish'],
                        'per_page'  => ['type' => 'integer', 'default' => 10],
                        'page'      => ['type' => 'integer', 'default' => 1],
                    ],
                ],
            ],
            [
                'name'        => 'get_post',
                'description' => 'Get a single post or page by ID.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Post ID'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name'        => 'create_post',
                'description' => 'Create a new post. Title and content required.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'   => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'status'  => ['type' => 'string', 'default' => 'draft', 'enum' => ['draft', 'publish', 'private']],
                        'post_type' => ['type' => 'string', 'default' => 'post'],
                    ],
                    'required' => ['title', 'content'],
                ],
            ],
            [
                'name'        => 'update_post',
                'description' => 'Update an existing post.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'      => ['type' => 'integer'],
                        'title'   => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'status'  => ['type' => 'string', 'enum' => ['draft', 'publish', 'private', 'trash']],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name'        => 'get_option',
                'description' => 'Get a WordPress option value.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'option' => ['type' => 'string', 'description' => 'Option name'],
                    ],
                    'required' => ['option'],
                ],
            ],
            [
                'name'        => 'list_plugins',
                'description' => 'List installed plugins with name, status, version.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'site_info',
                'description' => 'Get site URL, name, description, WordPress version.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ];

        return self::$tools;
    }

    private static function tool_list_posts($args) {
        $post_type = $args['post_type'] ?? 'post';
        $status    = $args['status'] ?? 'publish';
        $per_page  = (int) ($args['per_page'] ?? 10);
        $page      = max(1, (int) ($args['page'] ?? 1));

        $posts = get_posts([
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => min($per_page, 50),
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $out = [];
        foreach ($posts as $p) {
            $out[] = [
                'id'         => $p->ID,
                'title'      => $p->post_title,
                'date'       => $p->post_date,
                'status'     => $p->post_status,
                'post_type'  => $p->post_type,
                'permalink'  => get_permalink($p),
            ];
        }
        return wp_json_encode($out, JSON_PRETTY_PRINT);
    }

    private static function tool_get_post($args) {
        $id = (int) ($args['id'] ?? 0);
        if (!$id) {
            return new WP_Error('invalid_id', 'Post ID required');
        }
        $post = get_post($id);
        if (!$post) {
            return new WP_Error('not_found', "Post {$id} not found");
        }
        return wp_json_encode([
            'id'         => $post->ID,
            'title'      => $post->post_title,
            'content'    => $post->post_content,
            'excerpt'    => $post->post_excerpt,
            'status'     => $post->post_status,
            'date'       => $post->post_date,
            'post_type'  => $post->post_type,
            'permalink'  => get_permalink($post),
        ], JSON_PRETTY_PRINT);
    }

    private static function tool_create_post($args) {
        $title     = $args['title'] ?? '';
        $content   = $args['content'] ?? '';
        $status    = $args['status'] ?? 'draft';
        $post_type = $args['post_type'] ?? 'post';

        if (empty($title)) {
            return new WP_Error('invalid', 'Title required');
        }

        $author = get_current_user_id();
        if (!$author) {
            $admins = get_users(['role' => 'administrator', 'number' => 1]);
            $author = !empty($admins) ? $admins[0]->ID : 1;
        }

        $id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => $post_type,
            'post_author'  => $author,
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }
        return "Created post #{$id}: " . get_permalink($id);
    }

    private static function tool_update_post($args) {
        $id      = (int) ($args['id'] ?? 0);
        $title   = $args['title'] ?? null;
        $content = $args['content'] ?? null;
        $status  = $args['status'] ?? null;

        if (!$id) {
            return new WP_Error('invalid', 'Post ID required');
        }
        $post = get_post($id);
        if (!$post) {
            return new WP_Error('not_found', "Post {$id} not found");
        }

        $data = ['ID' => $id];
        if ($title !== null) {
            $data['post_title'] = $title;
        }
        if ($content !== null) {
            $data['post_content'] = $content;
        }
        if ($status !== null) {
            $data['post_status'] = $status;
        }

        $result = wp_update_post($data, true);
        if (is_wp_error($result)) {
            return $result;
        }
        return "Updated post #{$id}: " . get_permalink($id);
    }

    private static function tool_get_option($args) {
        $option = $args['option'] ?? '';
        if (empty($option)) {
            return new WP_Error('invalid', 'Option name required');
        }
        $value = get_option($option);
        return wp_json_encode(['option' => $option, 'value' => $value]);
    }

    private static function tool_list_plugins($args) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all    = get_plugins();
        $active = get_option('active_plugins', []);
        $out    = [];
        foreach ($all as $path => $data) {
            $out[] = [
                'name'    => $data['Name'],
                'version' => $data['Version'],
                'active'  => in_array($path, $active, true),
                'path'    => $path,
            ];
        }
        return wp_json_encode($out, JSON_PRETTY_PRINT);
    }

    private static function tool_site_info($args) {
        return wp_json_encode([
            'name'        => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url'         => home_url(),
            'wp_version'  => get_bloginfo('version'),
        ], JSON_PRETTY_PRINT);
    }
}
