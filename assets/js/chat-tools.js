(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        getTools: function() {
            return [
                {
                    name: 'read_file',
                    description: 'Read the contents of a file within wp-content directory',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content (e.g., "plugins/my-plugin/file.php")' }
                        },
                        required: ['path']
                    }
                },
                {
                    name: 'write_file',
                    description: 'Write or overwrite a file within wp-content directory. Use ONLY for creating NEW files.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' },
                            content: { type: 'string', description: 'The content to write to the file' }
                        },
                        required: ['path', 'content']
                    }
                },
                {
                    name: 'edit_file',
                    description: 'Edit an existing file by applying search and replace operations. Use this for modifying existing files instead of write_file. Each edit finds a unique string and replaces it.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' },
                            edits: {
                                type: 'array',
                                description: 'Array of edit operations to apply in order',
                                items: {
                                    type: 'object',
                                    properties: {
                                        search: { type: 'string', description: 'The exact string to find (must be unique in the file)' },
                                        replace: { type: 'string', description: 'The string to replace it with' }
                                    },
                                    required: ['search', 'replace']
                                }
                            }
                        },
                        required: ['path', 'edits']
                    }
                },
                {
                    name: 'delete_file',
                    description: 'Delete a file within wp-content directory',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' }
                        },
                        required: ['path']
                    }
                },
                {
                    name: 'list_directory',
                    description: 'List files and directories within a directory in wp-content',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' }
                        },
                        required: ['path']
                    }
                },
                {
                    name: 'search_files',
                    description: 'Search for files matching a glob pattern within wp-content',
                    input_schema: {
                        type: 'object',
                        properties: {
                            pattern: { type: 'string', description: 'Glob pattern (e.g., "plugins/*/*.php")' }
                        },
                        required: ['pattern']
                    }
                },
                {
                    name: 'search_content',
                    description: 'Search for text content within files in wp-content',
                    input_schema: {
                        type: 'object',
                        properties: {
                            needle: { type: 'string', description: 'The text to search for' },
                            directory: { type: 'string', description: 'Directory to search in (relative to wp-content)' },
                            file_pattern: { type: 'string', description: 'File extension filter (e.g., "*.php")' }
                        },
                        required: ['needle']
                    }
                },
                {
                    name: 'db_query',
                    description: 'Execute a SELECT query on the WordPress database',
                    input_schema: {
                        type: 'object',
                        properties: {
                            sql: { type: 'string', description: 'The SELECT SQL query. Use {prefix} for table prefix.' }
                        },
                        required: ['sql']
                    }
                },
                {
                    name: 'get_plugins',
                    description: 'List all installed WordPress plugins with their status',
                    input_schema: { type: 'object', properties: {} }
                },
                {
                    name: 'get_themes',
                    description: 'List all installed WordPress themes',
                    input_schema: { type: 'object', properties: {} }
                },
                {
                    name: 'install_plugin',
                    description: 'Install a plugin from the WordPress.org plugin directory. The slug is typically the plugin URL path on wordpress.org (e.g., wordpress.org/plugins/contact-form-7 → slug is "contact-form-7").',
                    input_schema: {
                        type: 'object',
                        properties: {
                            slug: { type: 'string', description: 'The plugin slug from wordpress.org (e.g., "akismet", "contact-form-7", "woocommerce")' },
                            activate: { type: 'boolean', description: 'Whether to activate the plugin after installation (default: false)' }
                        },
                        required: ['slug']
                    }
                },
                {
                    name: 'run_php',
                    description: 'Execute PHP code in the WordPress environment. Use for standard WordPress functions like wp_insert_post(), get_option(), WP_Query, etc.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            code: { type: 'string', description: 'PHP code to execute. Do not include <?php tags. The code should return a value that will be sent back as the result.' }
                        },
                        required: ['code']
                    }
                },
                {
                    name: 'list_abilities',
                    description: 'List all available WordPress abilities from plugins, themes, and core. Returns ability names and brief descriptions. Use this first to discover what actions are available.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            category: { type: 'string', description: 'Optional category filter (e.g., "content", "media", "users")' }
                        }
                    }
                },
                {
                    name: 'get_ability',
                    description: 'Get full details of a specific WordPress ability including its parameters, permissions, and usage. Call this before execute_ability to understand required arguments.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            ability: { type: 'string', description: 'The ability identifier (e.g., "core/create-post")' }
                        },
                        required: ['ability']
                    }
                },
                {
                    name: 'execute_ability',
                    description: 'Execute a WordPress ability with the given arguments. Use get_ability first to understand required parameters.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            ability: { type: 'string', description: 'The ability identifier to execute' },
                            arguments: { type: 'object', description: 'Arguments to pass to the ability' }
                        },
                        required: ['ability']
                    }
                },
                {
                    name: 'navigate',
                    description: 'Navigate the user to a URL within the WordPress site. Use this to take the user to specific admin pages, posts, or frontend pages. The URL must be within the current WordPress site. Note: This will reload the page, so it should typically be the last action in a conversation turn.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            url: { type: 'string', description: 'The URL to navigate to. Can be a full URL (must start with the site\'s home URL) or a relative path (e.g., "/wp-admin/edit.php" or "/sample-page/").' }
                        },
                        required: ['url']
                    }
                },
                {
                    name: 'get_page_html',
                    description: 'Get the HTML content of elements on the current page the user is viewing. Use this to understand what the user is seeing, inspect page structure, or help debug frontend issues. Returns the outer HTML of matched elements.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            selector: { type: 'string', description: 'CSS selector to query (e.g., "#main-content", ".entry-title", "article", "body"). Use "body" to get the full page content.' },
                            max_length: { type: 'number', description: 'Maximum characters to return per element (default: 5000). Use a smaller value for large pages.' }
                        },
                        required: ['selector']
                    }
                },
                {
                    name: 'summarize_conversation',
                    description: 'Generate a compact summary of a conversation and store it for future reference. Use this when a conversation is getting long or when the user wants to preserve context before starting a new chat. The summary captures key topics, decisions, files modified, and important context.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            conversation_id: { type: 'number', description: 'The conversation ID to summarize. Use 0 or omit to summarize the current conversation.' }
                        }
                    }
                }
            ];
        },

        getToolsOpenAI: function() {
            return this.getTools().map(function(tool) {
                return {
                    type: 'function',
                    function: {
                        name: tool.name,
                        description: tool.description,
                        parameters: tool.input_schema
                    }
                };
            });
        }
    });

})(jQuery);
