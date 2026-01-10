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
            $this->get_wordpress_tools()
        );
    }

    /**
     * Get read-only tools
     */
    public function get_read_only_tools(): array {
        return [
            $this->tool_read_file(),
            $this->tool_list_directory(),
            $this->tool_file_exists(),
            $this->tool_search_files(),
            $this->tool_search_content(),
            $this->tool_db_query(),
            $this->tool_get_option(),
            $this->tool_get_plugins(),
            $this->tool_get_themes(),
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
            $this->tool_append_file(),
            $this->tool_delete_file(),
            $this->tool_list_directory(),
            $this->tool_create_directory(),
            $this->tool_file_exists(),
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
            $this->tool_db_insert(),
            $this->tool_db_update(),
            $this->tool_db_delete(),
            $this->tool_get_option(),
            $this->tool_update_option(),
        ];
    }

    /**
     * Get WordPress-specific tools
     */
    private function get_wordpress_tools(): array {
        return [
            $this->tool_get_plugins(),
            $this->tool_activate_plugin(),
            $this->tool_deactivate_plugin(),
            $this->tool_get_themes(),
            $this->tool_switch_theme(),
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

    private function tool_append_file(): array {
        return [
            'name' => 'append_file',
            'description' => 'Append content to an existing file',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'The content to append',
                    ],
                ],
                'required' => ['path', 'content'],
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

    private function tool_create_directory(): array {
        return [
            'name' => 'create_directory',
            'description' => 'Create a new directory within wp-content',
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

    private function tool_file_exists(): array {
        return [
            'name' => 'file_exists',
            'description' => 'Check if a file or directory exists',
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

    private function tool_db_insert(): array {
        return [
            'name' => 'db_insert',
            'description' => 'Insert a new row into a WordPress database table',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Table name without prefix (e.g., "posts", "postmeta")',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Key-value pairs of column names and values to insert',
                    ],
                ],
                'required' => ['table', 'data'],
            ],
        ];
    }

    private function tool_db_update(): array {
        return [
            'name' => 'db_update',
            'description' => 'Update rows in a WordPress database table',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Table name without prefix',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Key-value pairs of columns to update',
                    ],
                    'where' => [
                        'type' => 'object',
                        'description' => 'Key-value pairs for WHERE clause conditions',
                    ],
                ],
                'required' => ['table', 'data', 'where'],
            ],
        ];
    }

    private function tool_db_delete(): array {
        return [
            'name' => 'db_delete',
            'description' => 'Delete rows from a WordPress database table',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Table name without prefix',
                    ],
                    'where' => [
                        'type' => 'object',
                        'description' => 'Key-value pairs for WHERE clause conditions',
                    ],
                ],
                'required' => ['table', 'where'],
            ],
        ];
    }

    private function tool_get_option(): array {
        return [
            'name' => 'get_option',
            'description' => 'Get a WordPress option value',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'The option name (e.g., "blogname", "siteurl")',
                    ],
                ],
                'required' => ['name'],
            ],
        ];
    }

    private function tool_update_option(): array {
        return [
            'name' => 'update_option',
            'description' => 'Update a WordPress option value',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'The option name',
                    ],
                    'value' => [
                        'type' => 'string',
                        'description' => 'The new value for the option',
                    ],
                ],
                'required' => ['name', 'value'],
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

    private function tool_activate_plugin(): array {
        return [
            'name' => 'activate_plugin',
            'description' => 'Activate a WordPress plugin',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'plugin' => [
                        'type' => 'string',
                        'description' => 'Plugin file path relative to plugins directory (e.g., "hello-dolly/hello.php")',
                    ],
                ],
                'required' => ['plugin'],
            ],
        ];
    }

    private function tool_deactivate_plugin(): array {
        return [
            'name' => 'deactivate_plugin',
            'description' => 'Deactivate a WordPress plugin',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'plugin' => [
                        'type' => 'string',
                        'description' => 'Plugin file path relative to plugins directory',
                    ],
                ],
                'required' => ['plugin'],
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

    private function tool_switch_theme(): array {
        return [
            'name' => 'switch_theme',
            'description' => 'Switch to a different WordPress theme',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'theme' => [
                        'type' => 'string',
                        'description' => 'Theme slug (directory name)',
                    ],
                ],
                'required' => ['theme'],
            ],
        ];
    }
}
