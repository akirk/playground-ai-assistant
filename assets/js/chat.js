(function($) {
    'use strict';

    window.aiAssistant = {
        isOpen: false,
        conversationId: 0,
        conversationTitle: '',
        messages: [],
        pendingActions: [],
        isLoading: false,
        systemPrompt: '',
        isFullPage: false,
        autoSave: true,
        draftStorageKey: 'aiAssistant_draftMessage',
        conversationPreloaded: false,

        init: function() {
            this.bindEvents();
            this.buildSystemPrompt();
            this.restoreDraft();

            // Check if we're on the full page and have a conversation to load
            if (typeof aiAssistantPageConfig !== 'undefined') {
                this.isFullPage = aiAssistantPageConfig.isFullPage || false;
                if (this.isFullPage) {
                    this.loadSidebarConversations();
                    $('#ai-assistant-input').focus();
                }
                if (aiAssistantPageConfig.conversationId > 0) {
                    this.loadConversation(aiAssistantPageConfig.conversationId);
                } else {
                    // Load most recent conversation on full page
                    this.loadMostRecentConversation();
                }
            } else {
                // Screen-meta mode: show welcome initially, load conversation on hover
                this.loadWelcomeMessage();
            }
        },

        bindEvents: function() {
            var self = this;

            $(document).on('click', '.ai-assistant-toggle', function(e) {
                e.preventDefault();
                self.toggle();
            });

            $(document).on('click', '#ai-assistant-close', function(e) {
                e.preventDefault();
                self.close();
            });

            $(document).on('click', '#ai-assistant-send', function(e) {
                e.preventDefault();
                self.sendMessage();
            });

            $(document).on('keydown', '#ai-assistant-input', function(e) {
                if (e.which === 13 && !e.shiftKey && !e.altKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            $(document).on('click', '#ai-assistant-new-chat', function(e) {
                e.preventDefault();
                self.newChat();
            });

            $(document).on('click', '.ai-confirm-action', function(e) {
                e.preventDefault();
                var actionId = $(this).data('action-id');
                self.confirmAction(actionId, true);
            });

            $(document).on('click', '.ai-skip-action', function(e) {
                e.preventDefault();
                var actionId = $(this).data('action-id');
                self.confirmAction(actionId, false);
            });

            $(document).on('click', '#ai-confirm-all', function(e) {
                e.preventDefault();
                self.confirmAllActions(true);
            });

            $(document).on('click', '#ai-skip-all', function(e) {
                e.preventDefault();
                self.confirmAllActions(false);
            });

            $(document).on('keydown', function(e) {
                if (e.which === 27 && self.isOpen) {
                    self.close();
                }
            });

            // Save draft on input change
            $(document).on('input', '#ai-assistant-input', function() {
                self.saveDraft();
            });

            // Preload most recent conversation on hover (screen-meta mode only)
            $(document).on('mouseenter', '#ai-assistant-link-wrap', function() {
                if (!self.isFullPage && !self.conversationPreloaded) {
                    self.conversationPreloaded = true;
                    self.loadMostRecentConversation();
                }
            });

            // Toggle tool result expansion
            $(document).on('click', '.ai-tool-header', function() {
                $(this).closest('.ai-tool-result').toggleClass('expanded');
            });

            // Toggle full height mode
            $(document).on('click', '#ai-assistant-expand', function() {
                $('.ai-assistant-chat-container').toggleClass('expanded');
                $(this).text($(this).text() === '⤢' ? '⤡' : '⤢');
            });

            // Save conversation button
            $(document).on('click', '#ai-assistant-save-chat', function(e) {
                e.preventDefault();
                self.saveConversation();
            });

            // Load conversation button
            $(document).on('click', '#ai-assistant-load-chat', function(e) {
                e.preventDefault();
                self.showConversationList();
            });

            // Modal close button
            $(document).on('click', '.ai-modal-close', function() {
                $(this).closest('.ai-modal').hide();
            });

            // Modal backdrop click
            $(document).on('click', '.ai-modal', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            // Load conversation from list
            $(document).on('click', '.ai-conversation-load', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var id = $(this).data('id');
                self.loadConversation(id);
                $('#ai-conversation-modal').hide();
            });

            // Delete conversation from list
            $(document).on('click', '.ai-conversation-delete, .ai-conv-item-delete', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var id = $(this).data('id');
                if (confirm('Delete this conversation?')) {
                    self.deleteConversation(id);
                }
            });

            // Sidebar conversation click (delayed to allow double-click for rename)
            $(document).on('click', '.ai-conv-item', function(e) {
                if ($(e.target).hasClass('ai-conv-item-delete')) return;
                if ($(e.target).hasClass('ai-conv-rename-input')) return;
                var $item = $(this);
                var id = $item.data('id');

                // Clear any existing timeout
                if ($item.data('clickTimeout')) {
                    clearTimeout($item.data('clickTimeout'));
                }

                // Delay load to allow double-click detection
                var timeout = setTimeout(function() {
                    self.loadConversation(id);
                }, 250);
                $item.data('clickTimeout', timeout);
            });

            // Preview toggle in pending actions
            $(document).on('click', '.ai-action-preview-toggle', function(e) {
                e.preventDefault();
                $(this).closest('.ai-action-preview').toggleClass('expanded');
            });

            // Double-click to rename conversation
            $(document).on('dblclick', '.ai-conv-item-title', function(e) {
                e.stopPropagation();
                var $title = $(this);
                var $item = $title.closest('.ai-conv-item');
                var id = $item.data('id');
                var currentTitle = $title.text();

                // Cancel the pending load
                if ($item.data('clickTimeout')) {
                    clearTimeout($item.data('clickTimeout'));
                    $item.removeData('clickTimeout');
                }

                // Replace with input
                var $input = $('<input type="text" class="ai-conv-rename-input">')
                    .val(currentTitle)
                    .css({
                        'width': '100%',
                        'font-size': '13px',
                        'padding': '2px 4px',
                        'border': '1px solid #2271b1',
                        'border-radius': '3px',
                        'outline': 'none'
                    });

                $title.html($input);
                $input.focus().select();

                // Save on blur or enter
                function saveRename() {
                    var newTitle = $input.val().trim();
                    if (newTitle && newTitle !== currentTitle) {
                        self.renameConversation(id, newTitle);
                        $title.text(newTitle);
                    } else {
                        $title.text(currentTitle);
                    }
                }

                $input.on('blur', saveRename);
                $input.on('keydown', function(e) {
                    if (e.which === 13) { // Enter
                        e.preventDefault();
                        $input.off('blur');
                        saveRename();
                    } else if (e.which === 27) { // Escape
                        e.preventDefault();
                        $input.off('blur');
                        $title.text(currentTitle);
                    }
                });
            });
        },

        toggle: function() {
            this.isOpen ? this.close() : this.open();
        },

        open: function() {
            $('#ai-assistant-drawer').addClass('open');
            this.isOpen = true;
            this.scrollToBottom();
            $('#ai-assistant-input').focus();

            // Backup: load most recent conversation if hover didn't trigger
            if (!this.isFullPage && !this.conversationPreloaded) {
                this.conversationPreloaded = true;
                this.loadMostRecentConversation();
            }
        },

        close: function() {
            $('#ai-assistant-drawer').removeClass('open');
            this.isOpen = false;
        },

        buildSystemPrompt: function() {
            var wpInfo = aiAssistantConfig.wpInfo || {};
            this.systemPrompt = `You are the Playground AI Assistant integrated into WordPress. You help users manage and modify their WordPress installation.

Current WordPress Information:
- Site URL: ${wpInfo.siteUrl || 'Unknown'}
- WordPress Version: ${wpInfo.wpVersion || 'Unknown'}
- Active Theme: ${wpInfo.theme || 'Unknown'}
- PHP Version: ${wpInfo.phpVersion || 'Unknown'}

You have access to tools that let you interact with the WordPress filesystem and database. All file paths are relative to wp-content/.

IMPORTANT FILE EDITING RULES:
- Use write_file ONLY for creating NEW files
- Use edit_file for modifying EXISTING files - it uses search/replace operations which is more efficient and easier to review
- The edit_file tool takes an array of {search, replace} pairs - each search string must be unique in the file
- If an edit_file operation fails (string not found or not unique), use read_file to see the current content and retry

IMPORTANT: For any destructive operations (file deletion, database modification, file overwriting), the user will be asked to confirm before execution. Be clear about what changes you're proposing.

Always explain what you're about to do before using tools.`;
        },

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
                    name: 'create_directory',
                    description: 'Create a new directory within wp-content',
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
                    name: 'db_insert',
                    description: 'Insert a new row into a WordPress database table',
                    input_schema: {
                        type: 'object',
                        properties: {
                            table: { type: 'string', description: 'Table name without prefix' },
                            data: { type: 'object', description: 'Key-value pairs to insert' }
                        },
                        required: ['table', 'data']
                    }
                },
                {
                    name: 'db_update',
                    description: 'Update rows in a WordPress database table',
                    input_schema: {
                        type: 'object',
                        properties: {
                            table: { type: 'string', description: 'Table name without prefix' },
                            data: { type: 'object', description: 'Key-value pairs to update' },
                            where: { type: 'object', description: 'WHERE clause conditions' }
                        },
                        required: ['table', 'data', 'where']
                    }
                },
                {
                    name: 'db_delete',
                    description: 'Delete rows from a WordPress database table',
                    input_schema: {
                        type: 'object',
                        properties: {
                            table: { type: 'string', description: 'Table name without prefix' },
                            where: { type: 'object', description: 'WHERE clause conditions' }
                        },
                        required: ['table', 'where']
                    }
                },
                {
                    name: 'get_plugins',
                    description: 'List all installed WordPress plugins with their status',
                    input_schema: { type: 'object', properties: {} }
                },
                {
                    name: 'activate_plugin',
                    description: 'Activate a WordPress plugin',
                    input_schema: {
                        type: 'object',
                        properties: {
                            plugin: { type: 'string', description: 'Plugin file path (e.g., "hello-dolly/hello.php")' }
                        },
                        required: ['plugin']
                    }
                },
                {
                    name: 'deactivate_plugin',
                    description: 'Deactivate a WordPress plugin',
                    input_schema: {
                        type: 'object',
                        properties: {
                            plugin: { type: 'string', description: 'Plugin file path' }
                        },
                        required: ['plugin']
                    }
                },
                {
                    name: 'get_themes',
                    description: 'List all installed WordPress themes',
                    input_schema: { type: 'object', properties: {} }
                },
                {
                    name: 'switch_theme',
                    description: 'Switch to a different WordPress theme',
                    input_schema: {
                        type: 'object',
                        properties: {
                            theme: { type: 'string', description: 'Theme slug (directory name)' }
                        },
                        required: ['theme']
                    }
                },
                {
                    name: 'run_php',
                    description: 'Execute PHP code in the WordPress environment. Use this to call WordPress functions like wp_insert_post(), wp_update_post(), get_option(), update_option(), WP_Query, etc. The code runs with full WordPress context available.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            code: { type: 'string', description: 'PHP code to execute. Do not include <?php tags. The code should return a value that will be sent back as the result.' }
                        },
                        required: ['code']
                    }
                }
            ];
        },

        // Convert tools to OpenAI format
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
        },

        sendMessage: function() {
            if (this.isLoading) return;

            var $input = $('#ai-assistant-input');
            var message = $input.val().trim();

            if (!message) return;

            this.addMessage('user', message);
            this.messages.push({ role: 'user', content: message });
            $input.val('');
            this.clearDraft();

            this.callLLM();
        },

        callLLM: function() {
            var self = this;
            var config = aiAssistantConfig;
            var provider = config.provider || 'anthropic';

            this.setLoading(true);

            switch (provider) {
                case 'anthropic':
                    this.callAnthropic();
                    break;
                case 'openai':
                    this.callOpenAI();
                    break;
                case 'local':
                    this.callLocalLLM();
                    break;
                default:
                    this.addMessage('error', 'Unknown provider: ' + provider);
                    this.setLoading(false);
            }
        },

        callAnthropic: async function() {
            var self = this;
            var config = aiAssistantConfig;

            try {
                var response = await fetch('https://api.anthropic.com/v1/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'x-api-key': config.apiKey,
                        'anthropic-version': '2023-06-01',
                        'anthropic-dangerous-direct-browser-access': 'true'
                    },
                    body: JSON.stringify({
                        model: config.model || 'claude-sonnet-4-5-20250929',
                        max_tokens: 4096,
                        system: this.systemPrompt,
                        messages: this.messages,
                        tools: this.getTools()
                    })
                });

                if (!response.ok) {
                    var error = await response.json();
                    throw new Error(error.error?.message || 'API request failed');
                }

                var data = await response.json();
                this.handleAnthropicResponse(data);

            } catch (error) {
                this.setLoading(false);
                this.addMessage('error', 'Anthropic API error: ' + error.message);
            }
        },

        handleAnthropicResponse: function(data) {
            var self = this;
            var textContent = '';
            var toolCalls = [];

            if (data.content) {
                data.content.forEach(function(block) {
                    if (block.type === 'text') {
                        textContent += block.text;
                    } else if (block.type === 'tool_use') {
                        toolCalls.push({
                            id: block.id,
                            name: block.name,
                            arguments: block.input
                        });
                    }
                });
            }

            if (textContent) {
                this.addMessage('assistant', textContent);
            }

            // Add assistant message to history
            this.messages.push({ role: 'assistant', content: data.content });

            if (toolCalls.length > 0) {
                this.processToolCalls(toolCalls, 'anthropic');
            } else {
                this.setLoading(false);
                this.autoSaveConversation();
            }
        },

        callOpenAI: async function() {
            var self = this;
            var config = aiAssistantConfig;

            try {
                var requestMessages = [
                    { role: 'system', content: this.systemPrompt },
                    ...this.messages
                ];

                var response = await fetch('https://api.openai.com/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + config.apiKey
                    },
                    body: JSON.stringify({
                        model: config.model || 'gpt-4o',
                        max_tokens: 4096,
                        messages: requestMessages,
                        tools: this.getToolsOpenAI()
                    })
                });

                if (!response.ok) {
                    var error = await response.json();
                    throw new Error(error.error?.message || 'API request failed');
                }

                var data = await response.json();
                this.handleOpenAIResponse(data);

            } catch (error) {
                this.setLoading(false);
                this.addMessage('error', 'OpenAI API error: ' + error.message);
            }
        },

        handleOpenAIResponse: function(data) {
            var choice = data.choices && data.choices[0];
            if (!choice) {
                this.setLoading(false);
                this.addMessage('error', 'No response from OpenAI');
                return;
            }

            var message = choice.message;
            var textContent = message.content || '';
            var toolCalls = [];

            if (message.tool_calls) {
                message.tool_calls.forEach(function(tc) {
                    if (tc.type === 'function') {
                        toolCalls.push({
                            id: tc.id,
                            name: tc.function.name,
                            arguments: JSON.parse(tc.function.arguments || '{}')
                        });
                    }
                });
            }

            if (textContent) {
                this.addMessage('assistant', textContent);
            }

            // Add to message history
            this.messages.push(message);

            if (toolCalls.length > 0) {
                this.processToolCalls(toolCalls, 'openai');
            } else {
                this.setLoading(false);
                this.autoSaveConversation();
            }
        },

        callLocalLLM: async function() {
            var self = this;
            var config = aiAssistantConfig;
            var endpoint = (config.localEndpoint || 'http://localhost:11434').replace(/\/$/, '');

            try {
                // Try OpenAI-compatible endpoint first (works with LM Studio and Ollama)
                var requestMessages = [
                    { role: 'system', content: this.systemPrompt },
                    ...this.messages
                ];

                var response = await fetch(endpoint + '/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        model: config.model || 'llama2',
                        messages: requestMessages,
                        tools: this.getToolsOpenAI()
                    })
                });

                if (!response.ok) {
                    // Try Ollama native API
                    response = await fetch(endpoint + '/api/chat', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            model: config.model || 'llama2',
                            messages: requestMessages,
                            tools: this.getToolsOpenAI(),
                            stream: false
                        })
                    });
                }

                if (!response.ok) {
                    throw new Error('Local LLM request failed. Make sure Ollama or LM Studio is running.');
                }

                var data = await response.json();

                // Handle Ollama native response format
                if (data.message) {
                    data = {
                        choices: [{
                            message: data.message
                        }]
                    };
                }

                this.handleOpenAIResponse(data);

            } catch (error) {
                this.setLoading(false);
                this.addMessage('error', 'Local LLM error: ' + error.message);
            }
        },

        processToolCalls: function(toolCalls, provider) {
            var self = this;
            var destructiveTools = ['write_file', 'edit_file', 'delete_file', 'create_directory', 'db_insert', 'db_update', 'db_delete', 'activate_plugin', 'deactivate_plugin', 'switch_theme', 'run_php'];

            var needsConfirmation = [];
            var executeImmediately = [];

            toolCalls.forEach(function(tc) {
                if (destructiveTools.indexOf(tc.name) >= 0) {
                    needsConfirmation.push(tc);
                } else {
                    executeImmediately.push(tc);
                }
            });

            // Execute non-destructive tools immediately
            if (executeImmediately.length > 0) {
                this.executeTools(executeImmediately, provider);
            }

            // Queue destructive tools for confirmation
            if (needsConfirmation.length > 0) {
                this.pendingActions = needsConfirmation.map(function(tc) {
                    return {
                        id: tc.id,
                        tool: tc.name,
                        arguments: tc.arguments,
                        description: self.getActionDescription(tc.name, tc.arguments),
                        provider: provider
                    };
                });
                this.showPendingActions(this.pendingActions);
            } else if (executeImmediately.length === 0) {
                this.setLoading(false);
            }
        },

        executeTools: function(toolCalls, provider) {
            var self = this;
            var promises = toolCalls.map(function(tc) {
                return self.executeSingleTool(tc);
            });

            Promise.all(promises).then(function(results) {
                self.handleToolResults(results, provider);
            }).catch(function(error) {
                self.setLoading(false);
                self.addMessage('error', 'Tool execution error: ' + error.message);
            });
        },

        executeSingleTool: function(toolCall) {
            var self = this;
            var toolName = toolCall.name || toolCall.tool;
            console.log('[AI Assistant] Executing tool:', toolName, 'with arguments:', toolCall.arguments);

            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_assistant_execute_tool',
                        _wpnonce: aiAssistantConfig.nonce,
                        tool: toolName,
                        arguments: JSON.stringify(toolCall.arguments)
                    },
                    success: function(response) {
                        console.log('[AI Assistant] Tool response for', toolName, ':', response);

                        var errorMessage = '';
                        if (!response.success) {
                            errorMessage = response.data?.message || response.data?.error || 'Unknown error';
                            if (!errorMessage && typeof response.data === 'string') {
                                errorMessage = response.data;
                            }
                            if (!errorMessage) {
                                errorMessage = 'Tool execution failed (no error message provided)';
                                console.warn('[AI Assistant] No error message in response:', response);
                            }
                        }

                        resolve({
                            id: toolCall.id,
                            name: toolName,
                            result: response.success ? response.data : { error: errorMessage },
                            success: response.success
                        });
                    },
                    error: function(xhr, status, errorThrown) {
                        var errorMessage = 'AJAX error: ';

                        // Try to get detailed error info
                        if (xhr.responseJSON && xhr.responseJSON.data) {
                            errorMessage += xhr.responseJSON.data.message || JSON.stringify(xhr.responseJSON.data);
                        } else if (xhr.responseText) {
                            // Might be PHP error or HTML
                            errorMessage += xhr.responseText.substring(0, 500);
                        } else if (errorThrown) {
                            errorMessage += errorThrown;
                        } else {
                            errorMessage += 'status=' + status + ', HTTP ' + xhr.status;
                        }

                        console.error('[AI Assistant] Tool execution failed:', {
                            tool: toolName,
                            status: status,
                            error: errorThrown,
                            httpStatus: xhr.status,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });

                        resolve({
                            id: toolCall.id,
                            name: toolName,
                            result: { error: errorMessage },
                            success: false
                        });
                    }
                });
            });
        },

        handleToolResults: function(results, provider) {
            var self = this;

            // Show results in UI
            this.showToolResults(results);

            // Add tool results to message history and continue conversation
            if (provider === 'anthropic') {
                var toolResults = results.map(function(r) {
                    return {
                        type: 'tool_result',
                        tool_use_id: r.id,
                        content: JSON.stringify(r.result)
                    };
                });
                this.messages.push({ role: 'user', content: toolResults });
            } else {
                // OpenAI format
                results.forEach(function(r) {
                    self.messages.push({
                        role: 'tool',
                        tool_call_id: r.id,
                        content: JSON.stringify(r.result)
                    });
                });
            }

            // Continue the conversation
            this.callLLM();
        },

        confirmAction: function(actionId, confirmed) {
            var self = this;
            var action = this.pendingActions.find(function(a) {
                return a.id === actionId;
            });

            if (!action) return;

            // Remove from pending
            this.pendingActions = this.pendingActions.filter(function(a) {
                return a.id !== actionId;
            });
            $('[data-action-id="' + actionId + '"]').remove();

            if (this.pendingActions.length === 0) {
                $('#ai-assistant-pending-actions').hide();
            }

            if (confirmed) {
                this.executeSingleTool(action).then(function(result) {
                    // handleToolResults already calls showToolResults, don't duplicate
                    self.handleToolResults([result], action.provider);
                });
            } else {
                this.addMessage('system', 'Skipped: ' + action.description);
                // Still need to send a result back to the LLM
                var skippedResult = {
                    id: action.id,
                    name: action.tool,
                    result: { skipped: true, message: 'User declined to execute this action' },
                    success: false
                };
                this.handleToolResults([skippedResult], action.provider);
            }
        },

        confirmAllActions: function(confirmed) {
            var self = this;
            var actions = this.pendingActions.slice();
            this.pendingActions = [];
            $('#ai-assistant-pending-actions').hide();

            if (confirmed) {
                var promises = actions.map(function(action) {
                    return self.executeSingleTool(action);
                });

                Promise.all(promises).then(function(results) {
                    // handleToolResults already calls showToolResults, don't duplicate
                    self.handleToolResults(results, actions[0].provider);
                });
            } else {
                var skippedResults = actions.map(function(action) {
                    self.addMessage('system', 'Skipped: ' + action.description);
                    return {
                        id: action.id,
                        name: action.tool,
                        result: { skipped: true, message: 'User declined to execute this action' },
                        success: false
                    };
                });
                this.handleToolResults(skippedResults, actions[0].provider);
            }
        },

        getActionDescription: function(toolName, args) {
            switch (toolName) {
                case 'write_file':
                    return 'Write to file: ' + (args.path || 'unknown');
                case 'edit_file':
                    var editCount = args.edits ? args.edits.length : 0;
                    return 'Edit file: ' + (args.path || 'unknown') + ' (' + editCount + ' change' + (editCount !== 1 ? 's' : '') + ')';
                case 'delete_file':
                    return 'Delete file: ' + (args.path || 'unknown');
                case 'create_directory':
                    return 'Create directory: ' + (args.path || 'unknown');
                case 'db_insert':
                    return 'Insert row into table: ' + (args.table || 'unknown');
                case 'db_update':
                    return 'Update rows in table: ' + (args.table || 'unknown');
                case 'db_delete':
                    return 'Delete rows from table: ' + (args.table || 'unknown');
                case 'run_php':
                    return 'Run PHP code';
                case 'activate_plugin':
                    return 'Activate plugin: ' + (args.plugin || 'unknown');
                case 'deactivate_plugin':
                    return 'Deactivate plugin: ' + (args.plugin || 'unknown');
                case 'switch_theme':
                    return 'Switch theme to: ' + (args.theme || 'unknown');
                default:
                    return 'Execute: ' + toolName;
            }
        },

        addMessage: function(role, content) {
            var $messages = $('#ai-assistant-messages');
            console.log('[AI Assistant] addMessage called, role:', role, '$messages.length:', $messages.length);

            var messageClass = 'ai-message ai-message-' + role;
            content = this.formatContent(content);

            var $message = $('<div class="' + messageClass + '">' +
                '<div class="ai-message-content">' + content + '</div>' +
                '</div>');

            $messages.append($message);
            console.log('[AI Assistant] Message appended, $messages children:', $messages.children().length);
            this.scrollToBottom();
        },

        formatContent: function(content) {
            if (!content) return '';

            // Escape HTML
            content = $('<div>').text(content).html();

            // Code blocks
            content = content.replace(/```(\w+)?\n([\s\S]*?)```/g, function(match, lang, code) {
                return '<pre><code class="language-' + (lang || '') + '">' + code.trim() + '</code></pre>';
            });

            // Inline code
            content = content.replace(/`([^`]+)`/g, '<code>$1</code>');

            // Bold
            content = content.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

            // Italic
            content = content.replace(/\*([^*]+)\*/g, '<em>$1</em>');

            // Line breaks
            content = content.replace(/\n/g, '<br>');

            return content;
        },

        showToolResults: function(results) {
            var $messages = $('#ai-assistant-messages');

            results.forEach(function(result) {
                var statusClass = result.success ? 'success' : 'error';
                var statusIcon = result.success ? '&#10003;' : '&#10007;';

                var resultStr;
                if (typeof result.result === 'object') {
                    // Check for empty error messages
                    if (result.result.error === '' || result.result.error === undefined) {
                        result.result.error = result.result.error === ''
                            ? '(empty error - check browser console for details)'
                            : undefined;
                    }
                    resultStr = JSON.stringify(result.result, null, 2);
                } else {
                    resultStr = result.result || '(no result data)';
                }

                var content = '<div class="ai-tool-result ai-tool-result-' + statusClass + '">' +
                    '<div class="ai-tool-header">' +
                    '<span class="ai-tool-toggle">&#9654;</span>' +
                    '<span class="ai-tool-icon">' + statusIcon + '</span>' +
                    '<span class="ai-tool-name">' + result.name + '</span>' +
                    '</div>' +
                    '<pre class="ai-tool-output">' + $('<div>').text(resultStr).html() + '</pre>' +
                    '</div>';

                $messages.append(content);
            });

            this.scrollToBottom();
        },

        showPendingActions: function(actions) {
            var self = this;
            var $container = $('#ai-assistant-pending-actions');
            $container.empty();

            if (actions.length === 0) {
                $container.hide();
                return;
            }

            var html = '<div class="ai-pending-header">' +
                '<h4>' + aiAssistantConfig.strings.confirmTitle + '</h4>' +
                '<div class="ai-pending-bulk-actions">' +
                '<button id="ai-confirm-all" class="button button-primary button-small">' +
                aiAssistantConfig.strings.confirmAll + '</button>' +
                '<button id="ai-skip-all" class="button button-small">' +
                aiAssistantConfig.strings.skipAll + '</button>' +
                '</div></div><div class="ai-pending-list">';

            actions.forEach(function(action) {
                var preview = self.getActionContentPreview(action.tool, action.arguments);
                var previewHtml = '';

                if (preview) {
                    var previewLabel = preview.isEdit ? 'Show changes' : 'Show content';
                    var lineCount = (preview.content.match(/\n/g) || []).length + 1;
                    previewHtml = '<div class="ai-action-preview">' +
                        '<button type="button" class="ai-action-preview-toggle">' +
                        '<span class="dashicons dashicons-arrow-right-alt2"></span>' +
                        previewLabel + ' (' + lineCount + ' lines)</button>' +
                        '<div class="ai-action-preview-content"><pre>' + preview.html + '</pre></div>' +
                        '</div>';
                }

                html += '<div class="ai-pending-action" data-action-id="' + action.id + '">' +
                    '<div class="ai-action-info">' +
                    '<span class="ai-action-tool">' + action.tool + '</span>' +
                    '<span class="ai-action-desc">' + self.escapeHtml(action.description) + '</span>' +
                    previewHtml +
                    '</div>' +
                    '<div class="ai-action-buttons">' +
                    '<button class="button button-primary button-small ai-confirm-action" data-action-id="' + action.id + '">' +
                    aiAssistantConfig.strings.confirm + '</button>' +
                    '<button class="button button-small ai-skip-action" data-action-id="' + action.id + '">' +
                    aiAssistantConfig.strings.cancel + '</button>' +
                    '</div></div>';
            });

            html += '</div>';
            $container.html(html).show();
            this.scrollToBottom();
        },

        getActionContentPreview: function(toolName, args) {
            var self = this;
            var content = null;
            var isEdit = false;

            switch (toolName) {
                case 'write_file':
                case 'append_file':
                    if (args.content) {
                        content = args.content;
                        if (content.length > 2000) {
                            content = content.substring(0, 2000) + '\n... (' + (args.content.length - 2000) + ' more characters)';
                        }
                    }
                    break;
                case 'edit_file':
                    isEdit = true;
                    if (args.edits && Array.isArray(args.edits)) {
                        var diffLines = [];
                        args.edits.forEach(function(edit, i) {
                            if (i > 0) diffLines.push('');
                            diffLines.push('--- Edit ' + (i + 1) + ' ---');

                            // Generate a smart diff showing only actual changes
                            var diff = self.generateSmartDiff(edit.search || '', edit.replace || '');
                            diffLines = diffLines.concat(diff);
                        });
                        content = diffLines.join('\n');
                    }
                    break;
                case 'db_insert':
                case 'db_update':
                    if (args.data) {
                        content = JSON.stringify(args.data, null, 2);
                    }
                    break;
                case 'run_php':
                    if (args.code) {
                        content = args.code;
                    }
                    break;
            }

            if (!content) return null;

            // Generate HTML with diff highlighting for edit_file
            var html;
            if (isEdit) {
                // For diffs, wrap every line in a block span (no newlines needed since display:block)
                html = content.split('\n').map(function(line) {
                    var escaped = self.escapeHtml(line);
                    if (line.startsWith('+ ')) {
                        return '<span class="ai-diff-line ai-diff-add">' + escaped + '</span>';
                    } else if (line.startsWith('- ')) {
                        return '<span class="ai-diff-line ai-diff-remove">' + escaped + '</span>';
                    } else if (line.startsWith('---')) {
                        return '<span class="ai-diff-line ai-diff-header">' + escaped + '</span>';
                    } else if (line.startsWith('  ')) {
                        return '<span class="ai-diff-line ai-diff-context">' + escaped + '</span>';
                    }
                    return '<span class="ai-diff-line">' + escaped + '</span>';
                }).join('');
            } else {
                html = self.escapeHtml(content);
            }

            return { content: content, html: html, isEdit: isEdit };
        },

        generateSmartDiff: function(search, replace) {
            var searchLines = search.split('\n');
            var replaceLines = replace.split('\n');
            var result = [];

            // Find common prefix (unchanged lines at start)
            var prefixCount = 0;
            while (prefixCount < searchLines.length &&
                   prefixCount < replaceLines.length &&
                   searchLines[prefixCount] === replaceLines[prefixCount]) {
                prefixCount++;
            }

            // Find common suffix (unchanged lines at end)
            var suffixCount = 0;
            while (suffixCount < (searchLines.length - prefixCount) &&
                   suffixCount < (replaceLines.length - prefixCount) &&
                   searchLines[searchLines.length - 1 - suffixCount] === replaceLines[replaceLines.length - 1 - suffixCount]) {
                suffixCount++;
            }

            // Show up to 2 context lines from prefix
            var contextBefore = Math.min(prefixCount, 2);
            var prefixStart = prefixCount - contextBefore;

            // Add ellipsis if we're skipping prefix lines
            if (prefixStart > 0) {
                result.push('  ... (' + prefixStart + ' unchanged lines)');
            }

            // Add context lines before changes
            for (var i = prefixStart; i < prefixCount; i++) {
                result.push('  ' + searchLines[i]);
            }

            // Add removed lines (middle section from search)
            var searchMiddleEnd = searchLines.length - suffixCount;
            for (var i = prefixCount; i < searchMiddleEnd; i++) {
                result.push('- ' + searchLines[i]);
            }

            // Add added lines (middle section from replace)
            var replaceMiddleEnd = replaceLines.length - suffixCount;
            for (var i = prefixCount; i < replaceMiddleEnd; i++) {
                result.push('+ ' + replaceLines[i]);
            }

            // Show up to 2 context lines from suffix
            var contextAfter = Math.min(suffixCount, 2);
            var suffixStart = searchLines.length - suffixCount;

            // Add context lines after changes
            for (var i = suffixStart; i < suffixStart + contextAfter; i++) {
                result.push('  ' + searchLines[i]);
            }

            // Add ellipsis if we're skipping suffix lines
            if (suffixCount > contextAfter) {
                result.push('  ... (' + (suffixCount - contextAfter) + ' unchanged lines)');
            }

            return result;
        },

        newChat: function() {
            this.messages = [];
            this.pendingActions = [];
            this.conversationId = 0;
            this.conversationTitle = '';
            $('#ai-assistant-messages').empty();
            $('#ai-assistant-pending-actions').empty().hide();
            this.updateSidebarSelection();
            this.loadWelcomeMessage();
            $('#ai-assistant-input').focus();
        },

        loadWelcomeMessage: function() {
            var config = aiAssistantConfig;
            if (!config.apiKey && config.provider !== 'local') {
                this.addMessage('system', 'Welcome! Please configure your API key in Settings to start chatting.');
            } else {
                this.addMessage('assistant', 'Hello! I\'m your Playground AI Assistant. I can help you manage your WordPress installation - read and modify files, manage plugins, query the database, and more. What would you like to do?');
            }
        },

        saveDraft: function() {
            var content = $('#ai-assistant-input').val();
            try {
                if (content) {
                    localStorage.setItem(this.draftStorageKey, content);
                } else {
                    localStorage.removeItem(this.draftStorageKey);
                }
            } catch (e) {
                console.warn('[AI Assistant] Could not save draft:', e);
            }
        },

        restoreDraft: function() {
            try {
                var draft = localStorage.getItem(this.draftStorageKey);
                if (draft) {
                    $('#ai-assistant-input').val(draft);
                }
            } catch (e) {
                console.warn('[AI Assistant] Could not restore draft:', e);
            }
        },

        clearDraft: function() {
            try {
                localStorage.removeItem(this.draftStorageKey);
            } catch (e) {
                console.warn('[AI Assistant] Could not clear draft:', e);
            }
        },

        loadMostRecentConversation: function() {
            var self = this;

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_list_conversations',
                    _wpnonce: aiAssistantConfig.nonce
                },
                success: function(response) {
                    if (response.success && response.data.conversations && response.data.conversations.length > 0) {
                        var mostRecent = response.data.conversations[0];
                        self.loadConversation(mostRecent.id);
                    }
                    // If no conversations, welcome message is already shown
                }
            });
        },

        setLoading: function(loading) {
            this.isLoading = loading;
            var $loading = $('#ai-assistant-loading');
            var $send = $('#ai-assistant-send');

            if (loading) {
                $loading.show();
                $send.prop('disabled', true);
                window.addEventListener('beforeunload', this.beforeUnloadHandler);
            } else {
                $loading.hide();
                $send.prop('disabled', false);
                window.removeEventListener('beforeunload', this.beforeUnloadHandler);
            }
        },

        beforeUnloadHandler: function(e) {
            e.preventDefault();
            e.returnValue = 'AI Assistant is still processing. Are you sure you want to leave?';
            return e.returnValue;
        },

        scrollToBottom: function() {
            var $messages = $('#ai-assistant-messages');
            $messages.scrollTop($messages[0].scrollHeight);
        },

        // Conversation persistence methods
        saveConversation: function(silent) {
            var self = this;

            if (this.messages.length === 0) {
                if (!silent) {
                    this.addMessage('system', 'No messages to save.');
                }
                return;
            }

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_save_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: this.conversationId,
                    messages: JSON.stringify(this.messages),
                    title: this.conversationTitle
                },
                success: function(response) {
                    if (response.success) {
                        var isNew = self.conversationId === 0;
                        self.conversationId = response.data.conversation_id;
                        self.conversationTitle = response.data.title;
                        self.updateSidebarSelection();
                        if (!silent) {
                            self.addMessage('system', 'Conversation saved.');
                        }
                        console.log('[AI Assistant] Conversation saved:', response.data);

                        // Refresh sidebar if new conversation
                        if (isNew && self.isFullPage) {
                            self.loadSidebarConversations();
                        }
                    } else {
                        console.error('[AI Assistant] Save failed:', response.data);
                        if (!silent) {
                            self.addMessage('error', 'Failed to save: ' + (response.data.message || 'Unknown error'));
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[AI Assistant] Save error:', error);
                    if (!silent) {
                        self.addMessage('error', 'Failed to save conversation.');
                    }
                }
            });
        },

        loadSidebarConversations: function() {
            var self = this;
            var $container = $('#ai-sidebar-conversations');

            if ($container.length === 0) return;

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_list_conversations',
                    _wpnonce: aiAssistantConfig.nonce
                },
                success: function(response) {
                    if (response.success && response.data.conversations) {
                        self.renderSidebarConversations(response.data.conversations);
                    } else {
                        $container.html('<div class="ai-sidebar-empty">No conversations yet</div>');
                    }
                },
                error: function() {
                    $container.html('<div class="ai-sidebar-empty">Failed to load</div>');
                }
            });
        },

        renderSidebarConversations: function(conversations) {
            var self = this;
            var $container = $('#ai-sidebar-conversations');

            if (conversations.length === 0) {
                $container.html('<div class="ai-sidebar-empty">No conversations yet</div>');
                return;
            }

            // Group by date
            var today = new Date().toDateString();
            var yesterday = new Date(Date.now() - 86400000).toDateString();
            var groups = { today: [], yesterday: [], older: [] };

            conversations.forEach(function(conv) {
                var convDate = new Date(conv.date).toDateString();
                if (convDate === today) {
                    groups.today.push(conv);
                } else if (convDate === yesterday) {
                    groups.yesterday.push(conv);
                } else {
                    groups.older.push(conv);
                }
            });

            var html = '';

            if (groups.today.length > 0) {
                html += '<div class="ai-conv-date-group">Today</div>';
                html += self.renderConversationGroup(groups.today);
            }
            if (groups.yesterday.length > 0) {
                html += '<div class="ai-conv-date-group">Yesterday</div>';
                html += self.renderConversationGroup(groups.yesterday);
            }
            if (groups.older.length > 0) {
                html += '<div class="ai-conv-date-group">Previous</div>';
                html += self.renderConversationGroup(groups.older);
            }

            $container.html(html);
            this.updateSidebarSelection();
        },

        renderConversationGroup: function(conversations) {
            var self = this;
            var html = '';
            conversations.forEach(function(conv) {
                var activeClass = conv.id === self.conversationId ? ' active' : '';
                html += '<div class="ai-conv-item' + activeClass + '" data-id="' + conv.id + '">';
                html += '<div class="ai-conv-item-title">' + self.escapeHtml(conv.title) + '</div>';
                html += '<button type="button" class="ai-conv-item-delete" data-id="' + conv.id + '" title="Delete">&times;</button>';
                html += '</div>';
            });
            return html;
        },

        updateSidebarSelection: function() {
            var self = this;
            $('.ai-conv-item').removeClass('active');
            if (this.conversationId > 0) {
                $('.ai-conv-item[data-id="' + this.conversationId + '"]').addClass('active');
            }
        },

        loadConversation: function(conversationId) {
            var self = this;

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_load_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: conversationId
                },
                success: function(response) {
                    if (response.success) {
                        self.conversationId = response.data.conversation_id;
                        self.conversationTitle = response.data.title;
                        self.messages = self.sanitizeMessages(response.data.messages || []);

                        // Clear and rebuild UI
                        $('#ai-assistant-messages').empty();
                        self.rebuildMessagesUI();
                        self.updateSidebarSelection();
                        $('#ai-assistant-input').focus();

                        // Auto-read files that were worked on in this conversation
                        self.autoReadConversationFiles();

                        console.log('[AI Assistant] Conversation loaded:', response.data);
                    } else {
                        self.addMessage('error', 'Failed to load: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[AI Assistant] Load error:', error);
                    self.addMessage('error', 'Failed to load conversation.');
                }
            });
        },

        autoReadConversationFiles: function() {
            var self = this;
            var filePaths = new Set();
            var readFileToolIds = new Set();

            // Extract file paths and track read_file tool IDs for removal
            this.messages.forEach(function(msg) {
                if (msg.role === 'assistant' && Array.isArray(msg.content)) {
                    msg.content.forEach(function(block) {
                        if (block.type === 'tool_use' && block.input && block.input.path) {
                            if (block.name === 'read_file') {
                                filePaths.add(block.input.path);
                                readFileToolIds.add(block.id);
                            } else if (['write_file', 'edit_file', 'append_file'].indexOf(block.name) >= 0) {
                                filePaths.add(block.input.path);
                            }
                        }
                    });
                }
            });

            if (filePaths.size === 0) return;

            // Remove old read_file tool calls and their results to reduce context
            if (readFileToolIds.size > 0) {
                console.log('[AI Assistant] Removing ' + readFileToolIds.size + ' old read_file tool calls to reduce context');
                this.messages = this.messages.map(function(msg) {
                    if (msg.role === 'assistant' && Array.isArray(msg.content)) {
                        msg.content = msg.content.filter(function(block) {
                            if (block.type === 'tool_use' && block.name === 'read_file' && readFileToolIds.has(block.id)) {
                                return false;
                            }
                            return true;
                        });
                    }
                    if (msg.role === 'user' && Array.isArray(msg.content)) {
                        msg.content = msg.content.filter(function(block) {
                            if (block.type === 'tool_result' && readFileToolIds.has(block.tool_use_id)) {
                                return false;
                            }
                            return true;
                        });
                    }
                    return msg;
                }).filter(function(msg) {
                    // Remove empty messages (assistant messages with no content blocks)
                    if (Array.isArray(msg.content) && msg.content.length === 0) {
                        return false;
                    }
                    return true;
                });
            }

            console.log('[AI Assistant] Auto-reading files from conversation:', Array.from(filePaths));

            // Read each file and store results (silently, for context)
            var fileContents = [];
            var promises = Array.from(filePaths).map(function(path) {
                return self.executeSingleTool({ name: 'read_file', arguments: { path: path } })
                    .then(function(result) {
                        if (result.success) {
                            fileContents.push({ path: path, content: result.result });
                        }
                        return result;
                    });
            });

            Promise.all(promises).then(function() {
                if (fileContents.length > 0) {
                    // Add a system context message with current file states
                    var contextMsg = 'Resuming conversation. Current state of previously accessed files:\n\n';
                    fileContents.forEach(function(file) {
                        // result.result is {path, content, size, modified} - extract actual content
                        var content = file.content.content || '';
                        // Truncate very long files
                        if (content.length > 5000) {
                            content = content.substring(0, 5000) + '\n... (truncated, ' + (file.content.size - 5000) + ' more bytes)';
                        }
                        contextMsg += '=== ' + file.path + ' ===\n' + content + '\n\n';
                    });

                    // Add as a hidden context message (not shown in UI, but in API messages)
                    self.messages.push({ role: 'user', content: contextMsg });
                    self.addMessage('system', 'Loaded ' + fileContents.length + ' file(s) from previous session for context.');
                    console.log('[AI Assistant] Added file context to messages, total messages:', self.messages.length);
                }
            });
        },

        rebuildMessagesUI: function() {
            var self = this;

            console.log('[AI Assistant] rebuildMessagesUI called, messages:', this.messages);
            console.log('[AI Assistant] #ai-assistant-messages element:', $('#ai-assistant-messages').length);

            this.messages.forEach(function(msg, index) {
                console.log('[AI Assistant] Processing message', index, ':', msg.role, typeof msg.content);
                if (msg.role === 'user') {
                    // User messages can be string or array (tool_result)
                    if (typeof msg.content === 'string') {
                        console.log('[AI Assistant] Adding user message:', msg.content.substring(0, 50));
                        self.addMessage('user', msg.content);
                    } else if (Array.isArray(msg.content)) {
                        // Check if it's a tool_result (don't show) or actual user content
                        msg.content.forEach(function(block) {
                            if (block.type === 'tool_result') {
                                // Show tool result
                                var resultContent = block.content;
                                try {
                                    resultContent = JSON.parse(block.content);
                                } catch(e) {}
                                self.showToolResults([{
                                    id: block.tool_use_id,
                                    name: 'tool_result',
                                    result: resultContent,
                                    success: !block.is_error
                                }]);
                            } else if (block.type === 'text') {
                                self.addMessage('user', block.text);
                            }
                        });
                    }
                } else if (msg.role === 'assistant') {
                    // Handle both string content and array content (with tool_use blocks)
                    if (typeof msg.content === 'string') {
                        self.addMessage('assistant', msg.content);
                    } else if (Array.isArray(msg.content)) {
                        msg.content.forEach(function(block) {
                            if (block.type === 'text' && block.text) {
                                self.addMessage('assistant', block.text);
                            } else if (block.type === 'tool_use') {
                                // Show tool usage indicator
                                self.showToolResults([{
                                    id: block.id,
                                    name: block.name,
                                    result: { input: block.input },
                                    success: true
                                }]);
                            }
                        });
                    }
                }
            });

            this.scrollToBottom();
        },

        showConversationList: function() {
            var self = this;
            var $modal = $('#ai-conversation-modal');
            var $list = $('#ai-conversation-list');

            $list.html('<p>Loading...</p>');
            $modal.show();

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_list_conversations',
                    _wpnonce: aiAssistantConfig.nonce
                },
                success: function(response) {
                    if (response.success && response.data.conversations) {
                        var conversations = response.data.conversations;
                        if (conversations.length === 0) {
                            $list.html('<p>No saved conversations.</p>');
                            return;
                        }

                        var html = '';
                        conversations.forEach(function(conv) {
                            html += '<div class="ai-conversation-item">';
                            html += '<div class="ai-conversation-item-title">' + self.escapeHtml(conv.title) + '</div>';
                            html += '<div class="ai-conversation-item-meta">' + conv.message_count + ' messages &bull; ' + conv.date + '</div>';
                            html += '<div class="ai-conversation-item-actions">';
                            html += '<button class="button button-primary button-small ai-conversation-load" data-id="' + conv.id + '">Load</button>';
                            html += '<button class="button button-small ai-conversation-delete" data-id="' + conv.id + '">Delete</button>';
                            html += '</div>';
                            html += '</div>';
                        });
                        $list.html(html);
                    } else {
                        $list.html('<p>Failed to load conversations.</p>');
                    }
                },
                error: function() {
                    $list.html('<p>Error loading conversations.</p>');
                }
            });
        },

        deleteConversation: function(conversationId) {
            var self = this;

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_delete_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: conversationId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove from sidebar and modal list
                        $('.ai-conv-item[data-id="' + conversationId + '"]').remove();
                        $('.ai-conversation-item [data-id="' + conversationId + '"]').closest('.ai-conversation-item').remove();

                        // If we deleted the current conversation, reset
                        if (self.conversationId === conversationId) {
                            self.newChat();
                        }
                    }
                }
            });
        },

        renameConversation: function(conversationId, newTitle) {
            var self = this;

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_rename_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: conversationId,
                    title: newTitle
                },
                success: function(response) {
                    if (response.success) {
                        // Update local title if this is the current conversation
                        if (self.conversationId === conversationId) {
                            self.conversationTitle = newTitle;
                        }
                        console.log('[AI Assistant] Conversation renamed:', newTitle);
                    } else {
                        console.error('[AI Assistant] Rename failed:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[AI Assistant] Rename error:', error);
                }
            });
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        sanitizeMessages: function(messages) {
            // Fix tool_use blocks that may have corrupted input fields after serialization
            console.log('[AI Assistant] sanitizeMessages: raw messages from server:', JSON.parse(JSON.stringify(messages)));

            var sanitized = messages.map(function(msg, msgIndex) {
                if (msg.role === 'assistant' && Array.isArray(msg.content)) {
                    msg.content = msg.content.map(function(block, blockIndex) {
                        if (block.type === 'tool_use') {
                            console.log('[AI Assistant] Found tool_use block at msg[' + msgIndex + '].content[' + blockIndex + ']:', {
                                name: block.name,
                                id: block.id,
                                inputType: typeof block.input,
                                inputIsArray: Array.isArray(block.input),
                                inputValue: block.input
                            });

                            // Ensure input is an object
                            if (typeof block.input === 'string') {
                                console.warn('[AI Assistant] Fixing: input is string, parsing JSON');
                                try {
                                    block.input = JSON.parse(block.input);
                                } catch(e) {
                                    console.error('[AI Assistant] Failed to parse input string:', e);
                                    block.input = {};
                                }
                            } else if (Array.isArray(block.input) && block.input.length === 0) {
                                console.warn('[AI Assistant] Fixing: input is empty array, converting to object');
                                block.input = {};
                            } else if (!block.input || typeof block.input !== 'object') {
                                console.warn('[AI Assistant] Fixing: input is invalid (' + typeof block.input + '), setting to empty object');
                                block.input = {};
                            } else {
                                console.log('[AI Assistant] tool_use input OK:', block.input);
                            }
                        }
                        return block;
                    });
                }
                // Also handle user messages with tool_result blocks
                if (msg.role === 'user' && Array.isArray(msg.content)) {
                    msg.content = msg.content.map(function(block, blockIndex) {
                        if (block.type === 'tool_result') {
                            console.log('[AI Assistant] Found tool_result block at msg[' + msgIndex + '].content[' + blockIndex + ']:', {
                                tool_use_id: block.tool_use_id,
                                contentType: typeof block.content
                            });
                            // Ensure content is a string
                            if (typeof block.content !== 'string') {
                                console.warn('[AI Assistant] Fixing: tool_result content is not string, stringifying');
                                block.content = JSON.stringify(block.content);
                            }
                        }
                        return block;
                    });
                }
                return msg;
            });

            console.log('[AI Assistant] sanitizeMessages: sanitized messages:', sanitized);
            return sanitized;
        },

        autoSaveConversation: function() {
            if (this.autoSave && this.messages.length > 0) {
                // Generate title if this is a new conversation
                if (!this.conversationTitle && this.messages.length >= 2) {
                    this.generateConversationTitle();
                }
                this.saveConversation(true);
            }
        },

        generateConversationTitle: function() {
            var self = this;
            var config = aiAssistantConfig;

            // Get the first user message for context
            var firstUserMsg = this.messages.find(function(m) { return m.role === 'user'; });
            if (!firstUserMsg) return;

            var userContent = typeof firstUserMsg.content === 'string'
                ? firstUserMsg.content
                : (firstUserMsg.content[0]?.text || '');

            if (!userContent) return;

            console.log('[AI Assistant] Generating conversation title...');

            // Make a lightweight API call to generate title
            var titlePrompt = 'Generate a very short title (3-6 words max) for a conversation that starts with this message. Return ONLY the title, nothing else:\n\n' + userContent.substring(0, 500);

            if (config.provider === 'anthropic' && config.apiKey) {
                fetch('https://api.anthropic.com/v1/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'x-api-key': config.apiKey,
                        'anthropic-version': '2023-06-01',
                        'anthropic-dangerous-direct-browser-access': 'true'
                    },
                    body: JSON.stringify({
                        model: 'claude-3-5-haiku-20241022',
                        max_tokens: 30,
                        messages: [{ role: 'user', content: titlePrompt }]
                    })
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.content && data.content[0] && data.content[0].text) {
                        self.conversationTitle = data.content[0].text.trim().replace(/^["']|["']$/g, '');
                        self.saveConversation(true);
                        self.loadSidebarConversations();
                        console.log('[AI Assistant] Generated title:', self.conversationTitle);
                    }
                })
                .catch(function(err) {
                    console.error('[AI Assistant] Title generation failed:', err);
                });
            } else if (config.provider === 'openai' && config.apiKey) {
                fetch('https://api.openai.com/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + config.apiKey
                    },
                    body: JSON.stringify({
                        model: 'gpt-4o-mini',
                        max_tokens: 30,
                        messages: [{ role: 'user', content: titlePrompt }]
                    })
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.choices && data.choices[0] && data.choices[0].message) {
                        self.conversationTitle = data.choices[0].message.content.trim().replace(/^["']|["']$/g, '');
                        self.saveConversation(true);
                        self.loadSidebarConversations();
                        console.log('[AI Assistant] Generated title:', self.conversationTitle);
                    }
                })
                .catch(function(err) {
                    console.error('[AI Assistant] Title generation failed:', err);
                });
            }
        }
    };

    $(document).ready(function() {
        window.aiAssistant.init();
    });

})(jQuery);
