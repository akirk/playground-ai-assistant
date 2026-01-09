(function($) {
    'use strict';

    window.aiAssistant = {
        isOpen: false,
        conversationId: 0,
        messages: [],
        pendingActions: [],
        isLoading: false,
        systemPrompt: '',

        init: function() {
            this.bindEvents();
            this.buildSystemPrompt();
            this.loadWelcomeMessage();
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

            $(document).on('keypress', '#ai-assistant-input', function(e) {
                if (e.which === 13 && !e.shiftKey) {
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

            // Toggle tool result expansion
            $(document).on('click', '.ai-tool-header', function() {
                $(this).closest('.ai-tool-result').toggleClass('expanded');
            });

            // Toggle full height mode
            $(document).on('click', '#ai-assistant-expand', function() {
                $('.ai-assistant-chat-container').toggleClass('expanded');
                $(this).text($(this).text() === '⤢' ? '⤡' : '⤢');
            });
        },

        toggle: function() {
            this.isOpen ? this.close() : this.open();
        },

        open: function() {
            $('#ai-assistant-drawer').addClass('open');
            $('#ai-assistant-input').focus();
            this.isOpen = true;
            this.scrollToBottom();
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

IMPORTANT: For any destructive operations (file deletion, database modification, file overwriting), the user will be asked to confirm before execution. Be clear about what changes you're proposing.

Always explain what you're about to do before using tools. When modifying files, show the changes you're making.`;
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
                    description: 'Write or overwrite a file within wp-content directory',
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
                    name: 'get_option',
                    description: 'Get a WordPress option value',
                    input_schema: {
                        type: 'object',
                        properties: {
                            name: { type: 'string', description: 'The option name' }
                        },
                        required: ['name']
                    }
                },
                {
                    name: 'update_option',
                    description: 'Update a WordPress option value',
                    input_schema: {
                        type: 'object',
                        properties: {
                            name: { type: 'string', description: 'The option name' },
                            value: { type: 'string', description: 'The new value' }
                        },
                        required: ['name', 'value']
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
            var destructiveTools = ['write_file', 'delete_file', 'create_directory', 'db_insert', 'db_update', 'db_delete', 'update_option', 'activate_plugin', 'deactivate_plugin', 'switch_theme'];

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
                    self.showToolResults([result]);
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
                    self.showToolResults(results);
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
                case 'update_option':
                    return 'Update option: ' + (args.name || 'unknown');
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
            var messageClass = 'ai-message ai-message-' + role;
            content = this.formatContent(content);

            var $message = $('<div class="' + messageClass + '">' +
                '<div class="ai-message-content">' + content + '</div>' +
                '</div>');

            $messages.append($message);
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
                html += '<div class="ai-pending-action" data-action-id="' + action.id + '">' +
                    '<div class="ai-action-info">' +
                    '<span class="ai-action-tool">' + action.tool + '</span>' +
                    '<span class="ai-action-desc">' + action.description + '</span>' +
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

        newChat: function() {
            this.messages = [];
            this.pendingActions = [];
            $('#ai-assistant-messages').empty();
            $('#ai-assistant-pending-actions').empty().hide();
            this.loadWelcomeMessage();
        },

        loadWelcomeMessage: function() {
            var config = aiAssistantConfig;
            if (!config.apiKey && config.provider !== 'local') {
                this.addMessage('system', 'Welcome! Please configure your API key in Settings to start chatting.');
            } else {
                this.addMessage('assistant', 'Hello! I\'m your Playground AI Assistant. I can help you manage your WordPress installation - read and modify files, manage plugins, query the database, and more. What would you like to do?');
            }
        },

        setLoading: function(loading) {
            this.isLoading = loading;
            var $loading = $('#ai-assistant-loading');
            var $send = $('#ai-assistant-send');

            if (loading) {
                $loading.show();
                $send.prop('disabled', true);
            } else {
                $loading.hide();
                $send.prop('disabled', false);
            }
        },

        scrollToBottom: function() {
            var $messages = $('#ai-assistant-messages');
            $messages.scrollTop($messages[0].scrollHeight);
        }
    };

    $(document).ready(function() {
        window.aiAssistant.init();
    });

})(jQuery);
