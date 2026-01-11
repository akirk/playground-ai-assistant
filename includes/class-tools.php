<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Tool Definitions
 */
class Tools {

    /**
     * Get all available tools
     */
    public function get_all_tools(): array {
        return array_merge(
            $this->get_file_tools(),
            $this->get_database_tools(),
            $this->get_wordpress_tools(),
            $this->get_abilities_tools()
        );
    }

    /**
     * Get read-only tools
     */
    public function get_read_only_tools(): array {
        return [
            $this->tool_read_file(),
            $this->tool_list_directory(),
            $this->tool_search_files(),
            $this->tool_search_content(),
            $this->tool_db_query(),
            $this->tool_get_plugins(),
            $this->tool_get_themes(),
            $this->tool_list_abilities(),
            $this->tool_get_ability(),
        ];
    }

    /**
     * Get file operation tools
     */
    private function get_file_tools(): array {
        return [
            $this->tool_read_file(),
            $this->tool_write_file(),
            $this->tool_edit_file(),
            $this->tool_delete_file(),
            $this->tool_list_directory(),
            $this->tool_search_files(),
            $this->tool_search_content(),
        ];
    }

    /**
     * Get database tools
     */
    private function get_database_tools(): array {
        return [
            $this->tool_db_query(),
        ];
    }

    /**
     * Get WordPress-specific tools
     */
    private function get_wordpress_tools(): array {
        return [
            $this->tool_get_plugins(),
            $this->tool_get_themes(),
            $this->tool_run_php(),
        ];
    }

    // ===== FILE TOOLS =====

    private function tool_read_file(): array {
        return [
            'name' => 'read_file',
            'description' => 'Read the contents of a file within wp-content directory',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content (e.g., "plugins/my-plugin/file.php")',
                    ],
                ],
                'required' => ['path'],
            ],
        ];
    }

    private function tool_write_file(): array {
        return [
            'name' => 'write_file',
            'description' => 'Write or overwrite a file within wp-content directory. Use this only for creating NEW files. For modifying existing files, use edit_file instead.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content (e.g., "plugins/my-plugin/file.php")',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'The content to write to the file',
                    ],
                ],
                'required' => ['path', 'content'],
            ],
        ];
    }

    private function tool_edit_file(): array {
        return [
            'name' => 'edit_file',
            'description' => 'Edit an existing file by applying search and replace operations. More efficient than write_file for making targeted changes. Each edit finds a unique string and replaces it.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content (e.g., "plugins/my-plugin/file.php")',
                    ],
                    'edits' => [
                        'type' => 'array',
                        'description' => 'Array of edit operations to apply in order',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'search' => [
                                    'type' => 'string',
                                    'description' => 'The exact string to find (must be unique in the file)',
                                ],
                                'replace' => [
                                    'type' => 'string',
                                    'description' => 'The string to replace it with',
                                ],
                            ],
                            'required' => ['search', 'replace'],
                        ],
                    ],
                ],
                'required' => ['path', 'edits'],
            ],
        ];
    }

    private function tool_delete_file(): array {
        return [
            'name' => 'delete_file',
            'description' => 'Delete a file within wp-content directory',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content',
                    ],
                ],
                'required' => ['path'],
            ],
        ];
    }

    private function tool_list_directory(): array {
        return [
            'name' => 'list_directory',
            'description' => 'List files and directories within a directory in wp-content',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content (e.g., "plugins" or "themes/theme-name")',
                    ],
                ],
                'required' => ['path'],
            ],
        ];
    }

    private function tool_search_files(): array {
        return [
            'name' => 'search_files',
            'description' => 'Search for files matching a glob pattern within wp-content',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'pattern' => [
                        'type' => 'string',
                        'description' => 'Glob pattern (e.g., "plugins/*/*.php" or "themes/**/*.css")',
                    ],
                ],
                'required' => ['pattern'],
            ],
        ];
    }

    private function tool_search_content(): array {
        return [
            'name' => 'search_content',
            'description' => 'Search for text content within files in wp-content',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'needle' => [
                        'type' => 'string',
                        'description' => 'The text to search for',
                    ],
                    'directory' => [
                        'type' => 'string',
                        'description' => 'Directory to search in (relative to wp-content), default is entire wp-content',
                    ],
                    'file_pattern' => [
                        'type' => 'string',
                        'description' => 'File extension filter (e.g., "*.php")',
                    ],
                ],
                'required' => ['needle'],
            ],
        ];
    }

    // ===== DATABASE TOOLS =====

    private function tool_db_query(): array {
        return [
            'name' => 'db_query',
            'description' => 'Execute a SELECT query on the WordPress database. Only SELECT queries are allowed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'sql' => [
                        'type' => 'string',
                        'description' => 'The SELECT SQL query to execute. Use {prefix} as placeholder for table prefix.',
                    ],
                ],
                'required' => ['sql'],
            ],
        ];
    }

    // ===== WORDPRESS TOOLS =====

    private function tool_get_plugins(): array {
        return [
            'name' => 'get_plugins',
            'description' => 'List all installed WordPress plugins with their status',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
        ];
    }

    private function tool_get_themes(): array {
        return [
            'name' => 'get_themes',
            'description' => 'List all installed WordPress themes',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
        ];
    }

    private function tool_run_php(): array {
        return [
            'name' => 'run_php',
            'description' => 'Execute PHP code in the WordPress environment. Use this to call WordPress functions like wp_insert_post(), wp_update_post(), get_option(), update_option(), WP_Query, etc. The code runs with full WordPress context available.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'code' => [
                        'type' => 'string',
                        'description' => 'PHP code to execute. Do not include <?php tags. The code should return a value that will be sent back as the result.',
                    ],
                ],
                'required' => ['code'],
            ],
        ];
    }

    // ===== ABILITIES API TOOLS =====

    private function get_abilities_tools(): array {
        return [
            $this->tool_list_abilities(),
            $this->tool_get_ability(),
            $this->tool_execute_ability(),
        ];
    }

    private function tool_list_abilities(): array {
        return [
            'name' => 'list_abilities',
            'description' => 'List all available WordPress abilities (from plugins, themes, and core). Returns ability names and brief descriptions. Use get_ability to fetch full details for a specific ability before executing.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'Optional category to filter abilities (e.g., "content", "media", "users")',
                    ],
                ],
            ],
        ];
    }

    private function tool_get_ability(): array {
        return [
            'name' => 'get_ability',
            'description' => 'Get full details of a specific WordPress ability including its parameters schema, permissions, and usage information. Call this before execute_ability to understand what arguments are needed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'ability' => [
                        'type' => 'string',
                        'description' => 'The ability identifier (e.g., "core/create-post", "woocommerce/add-to-cart")',
                    ],
                ],
                'required' => ['ability'],
            ],
        ];
    }

    private function tool_execute_ability(): array {
        return [
            'name' => 'execute_ability',
            'description' => 'Execute a WordPress ability with the given arguments. Use get_ability first to understand required parameters.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'ability' => [
                        'type' => 'string',
                        'description' => 'The ability identifier to execute',
                    ],
                    'arguments' => [
                        'type' => 'object',
                        'description' => 'Arguments to pass to the ability (schema varies by ability)',
                    ],
                ],
                'required' => ['ability'],
            ],
        ];
    }
}
