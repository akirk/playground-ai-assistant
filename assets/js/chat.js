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
        draftHistoryKey: 'aiAssistant_draftHistory',
        yoloStorageKey: 'aiAssistant_yoloMode',
        conversationPreloaded: false,
        yoloMode: false,
        conversationProvider: '',
        conversationModel: '',
        draftHistory: [],
        draftHistoryIndex: -1,
        draftHistoryMax: 50,
        pendingNewChat: false,

        init: function() {
            this.bindEvents();
            this.buildSystemPrompt();
            this.restoreDraft();
            this.restoreYoloMode();
            this.loadDraftHistory();

            // Set default provider/model from settings (will be overwritten if loading a conversation)
            this.conversationProvider = aiAssistantConfig.provider;
            this.conversationModel = aiAssistantConfig.model;
            this.updateSendButton();
            this.updateTokenCount();

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
                } else if (e.which === 38 && !e.shiftKey) { // Up arrow
                    var $input = $(this);
                    // Only navigate history if cursor is at the start or input is empty
                    if ($input.val() === '' || $input[0].selectionStart === 0) {
                        e.preventDefault();
                        self.navigateDraftHistory(-1);
                    }
                } else if (e.which === 40 && !e.shiftKey) { // Down arrow
                    var $input = $(this);
                    var val = $input.val();
                    // Only navigate history if cursor is at the end or input is empty
                    if (val === '' || $input[0].selectionStart === val.length) {
                        e.preventDefault();
                        self.navigateDraftHistory(1);
                    }
                }
            });

            $(document).on('click', '#ai-assistant-new-chat', function(e) {
                e.preventDefault();
                self.newChat();
            });

            $(document).on('click', '#ai-assistant-undo-new-chat', function(e) {
                e.preventDefault();
                self.undoNewChat();
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

            // YOLO mode toggle
            $(document).on('change', '#ai-assistant-yolo', function() {
                self.yoloMode = $(this).is(':checked');
                self.saveYoloMode();
                self.addMessage('system', self.yoloMode
                    ? 'YOLO Mode enabled - destructive actions will execute without confirmation.'
                    : 'YOLO Mode disabled - destructive actions will require confirmation.');
            });

            // Toggle tool result expansion
            $(document).on('click', '.ai-tool-header', function() {
                $(this).closest('.ai-tool-result').toggleClass('expanded');
            });

            // Toggle full height mode
            $(document).on('click', '#ai-assistant-expand', function() {
                var $container = $('.ai-assistant-chat-container');
                var isExpanded = $container.toggleClass('expanded').hasClass('expanded');
                $(this).find('.ai-expand-icon').toggle(!isExpanded);
                $(this).find('.ai-collapse-icon').toggle(isExpanded);
                $(this).attr('title', isExpanded ? 'Collapse' : 'Expand');
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
            this.scrollToBottom(true);
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
            this.systemPrompt = aiAssistantConfig.systemPrompt || '';
            if (!this.systemPrompt) {
                console.error('[AI Assistant] No system prompt provided');
                this.addMessage('error', 'Configuration error: system prompt not available. Please check plugin settings.');
            }
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
            if (this.isLoading || !this.isProviderConfigured()) return;

            var $input = $('#ai-assistant-input');
            var message = $input.val().trim();

            if (!message) return;

            // If we have a pending new chat, commit it now
            if (this.pendingNewChat) {
                this.messages = [];
                this.pendingActions = [];
                this.conversationId = 0;
                this.conversationTitle = '';
                this.conversationProvider = aiAssistantConfig.provider;
                this.conversationModel = aiAssistantConfig.model;
                this.pendingNewChat = false;
                $('#ai-assistant-messages').removeClass('ai-pending-new-chat').empty();
                $('#ai-token-count').show();
                $('#ai-assistant-pending-actions').empty().hide();
                $('#ai-assistant-undo-new-chat').text('New Chat').attr('id', 'ai-assistant-new-chat');
                this.updateSidebarSelection();
                this.loadWelcomeMessage();
            }

            this.addToDraftHistory(message);
            this.addMessage('user', message);
            this.messages.push({ role: 'user', content: message });
            $input.val('');
            this.clearDraft();
            this.draftHistoryIndex = -1;

            this.updateTokenCount();
            this.callLLM();
        },

        callLLM: function() {
            var self = this;
            var provider = this.conversationProvider || aiAssistantConfig.provider || 'anthropic';

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

        readSSEStream: async function*(response) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            try {
                while (true) {
                    var { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (line.startsWith('data: ')) {
                            var data = line.slice(6);
                            if (data === '[DONE]') return;
                            try {
                                yield JSON.parse(data);
                            } catch (e) {
                                // Skip non-JSON data lines
                            }
                        }
                    }
                }
            } finally {
                reader.releaseLock();
            }
        },

        callAnthropic: async function() {
            var self = this;
            var config = aiAssistantConfig;
            var model = this.conversationModel || config.model;

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
                        model: model,
                        max_tokens: 4096,
                        stream: true,
                        system: this.systemPrompt,
                        messages: this.messages,
                        tools: this.getTools()
                    })
                });

                if (!response.ok) {
                    var error = await response.json();
                    throw new Error(error.error?.message || 'API request failed');
                }

                var $reply = this.startReply();
                var textContent = '';
                var contentBlocks = [];
                var currentBlock = null;
                var toolCalls = [];

                for await (var event of this.readSSEStream(response)) {
                    switch (event.type) {
                        case 'content_block_start':
                            currentBlock = { index: event.index, ...event.content_block };
                            if (currentBlock.type === 'tool_use') {
                                currentBlock.input = '';
                            }
                            break;

                        case 'content_block_delta':
                            if (event.delta.type === 'text_delta') {
                                textContent += event.delta.text;
                                this.updateReply($reply, textContent);
                            } else if (event.delta.type === 'input_json_delta') {
                                if (currentBlock) {
                                    currentBlock.input += event.delta.partial_json;
                                }
                            }
                            break;

                        case 'content_block_stop':
                            if (currentBlock) {
                                if (currentBlock.type === 'tool_use') {
                                    try {
                                        currentBlock.input = JSON.parse(currentBlock.input);
                                    } catch (e) {
                                        currentBlock.input = {};
                                    }
                                }
                                contentBlocks.push(currentBlock);
                                currentBlock = null;
                            }
                            break;
                    }
                }

                contentBlocks.forEach(function(block) {
                    if (block.type === 'tool_use') {
                        toolCalls.push({
                            id: block.id,
                            name: block.name,
                            arguments: block.input
                        });
                    }
                });

                if (!textContent) {
                    $reply.remove();
                }

                this.messages.push({ role: 'assistant', content: contentBlocks });
                this.updateTokenCount();

                if (toolCalls.length > 0) {
                    this.processToolCalls(toolCalls, 'anthropic');
                } else {
                    this.setLoading(false);
                    this.autoSaveConversation();
                }

            } catch (error) {
                this.setLoading(false);
                this.addMessage('error', 'Anthropic API error: ' + error.message);
            }
        },

        callOpenAI: async function() {
            var self = this;
            var config = aiAssistantConfig;
            var model = this.conversationModel || config.model;

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
                        model: model,
                        max_tokens: 4096,
                        stream: true,
                        messages: requestMessages,
                        tools: this.getToolsOpenAI()
                    })
                });

                if (!response.ok) {
                    var error = await response.json();
                    throw new Error(error.error?.message || 'API request failed');
                }

                var $reply = this.startReply();
                var textContent = '';
                var toolCallsMap = {};

                for await (var chunk of this.readSSEStream(response)) {
                    var delta = chunk.choices && chunk.choices[0] && chunk.choices[0].delta;
                    if (!delta) continue;

                    if (delta.content) {
                        textContent += delta.content;
                        this.updateReply($reply, textContent);
                    }

                    if (delta.tool_calls) {
                        delta.tool_calls.forEach(function(tc) {
                            var idx = tc.index;
                            if (!toolCallsMap[idx]) {
                                toolCallsMap[idx] = { id: '', type: 'function', function: { name: '', arguments: '' } };
                            }
                            if (tc.id) toolCallsMap[idx].id = tc.id;
                            if (tc.function) {
                                if (tc.function.name) toolCallsMap[idx].function.name = tc.function.name;
                                if (tc.function.arguments) toolCallsMap[idx].function.arguments += tc.function.arguments;
                            }
                        });
                    }
                }

                var toolCalls = [];
                Object.keys(toolCallsMap).forEach(function(idx) {
                    var tc = toolCallsMap[idx];
                    toolCalls.push({
                        id: tc.id,
                        name: tc.function.name,
                        arguments: JSON.parse(tc.function.arguments || '{}')
                    });
                });

                if (!textContent) {
                    $reply.remove();
                }

                var message = { role: 'assistant', content: textContent || null };
                if (Object.keys(toolCallsMap).length > 0) {
                    message.tool_calls = Object.values(toolCallsMap);
                }
                this.messages.push(message);
                this.updateTokenCount();

                if (toolCalls.length > 0) {
                    this.processToolCalls(toolCalls, 'openai');
                } else {
                    this.setLoading(false);
                    this.autoSaveConversation();
                }

            } catch (error) {
                this.setLoading(false);
                this.addMessage('error', 'OpenAI API error: ' + error.message);
            }
        },

        callLocalLLM: async function() {
            var self = this;
            var config = aiAssistantConfig;
            var endpoint = (config.localEndpoint || 'http://localhost:11434').replace(/\/$/, '');

            try {
                var requestMessages = [
                    { role: 'system', content: this.systemPrompt },
                    ...this.messages
                ];

                var model = this.conversationModel || config.model;
                var useOllamaApi = false;

                var response = await fetch(endpoint + '/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        model: model,
                        stream: true,
                        messages: requestMessages,
                        tools: this.getToolsOpenAI()
                    })
                });

                if (!response.ok) {
                    useOllamaApi = true;
                    response = await fetch(endpoint + '/api/chat', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            model: model,
                            messages: requestMessages,
                            tools: this.getToolsOpenAI(),
                            stream: true
                        })
                    });
                }

                if (!response.ok) {
                    throw new Error('Local LLM request failed. Make sure Ollama or LM Studio is running.');
                }

                var $reply = this.startReply();
                var textContent = '';
                var toolCallsMap = {};

                if (useOllamaApi) {
                    for await (var chunk of this.readOllamaStream(response)) {
                        if (chunk.message && chunk.message.content) {
                            textContent += chunk.message.content;
                            this.updateReply($reply, textContent);
                        }
                        if (chunk.message && chunk.message.tool_calls) {
                            chunk.message.tool_calls.forEach(function(tc, idx) {
                                toolCallsMap[idx] = tc;
                            });
                        }
                    }
                } else {
                    for await (var chunk of this.readSSEStream(response)) {
                        var delta = chunk.choices && chunk.choices[0] && chunk.choices[0].delta;
                        if (!delta) continue;

                        if (delta.content) {
                            textContent += delta.content;
                            this.updateReply($reply, textContent);
                        }

                        if (delta.tool_calls) {
                            delta.tool_calls.forEach(function(tc) {
                                var idx = tc.index;
                                if (!toolCallsMap[idx]) {
                                    toolCallsMap[idx] = { id: '', type: 'function', function: { name: '', arguments: '' } };
                                }
                                if (tc.id) toolCallsMap[idx].id = tc.id;
                                if (tc.function) {
                                    if (tc.function.name) toolCallsMap[idx].function.name = tc.function.name;
                                    if (tc.function.arguments) toolCallsMap[idx].function.arguments += tc.function.arguments;
                                }
                            });
                        }
                    }
                }

                var toolCalls = [];
                Object.keys(toolCallsMap).forEach(function(idx) {
                    var tc = toolCallsMap[idx];
                    if (tc.function) {
                        toolCalls.push({
                            id: tc.id || 'tool_' + idx,
                            name: tc.function.name,
                            arguments: JSON.parse(tc.function.arguments || '{}')
                        });
                    }
                });

                if (!textContent) {
                    $reply.remove();
                }

                var message = { role: 'assistant', content: textContent || null };
                if (Object.keys(toolCallsMap).length > 0) {
                    message.tool_calls = Object.values(toolCallsMap);
                }
                this.messages.push(message);
                this.updateTokenCount();

                if (toolCalls.length > 0) {
                    this.processToolCalls(toolCalls, 'openai');
                } else {
                    this.setLoading(false);
                    this.autoSaveConversation();
                }

            } catch (error) {
                this.setLoading(false);
                this.addMessage('error', 'Local LLM error: ' + error.message);
            }
        },

        readOllamaStream: async function*(response) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            try {
                while (true) {
                    var { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (line) {
                            try {
                                yield JSON.parse(line);
                            } catch (e) {
                                // Skip non-JSON lines
                            }
                        }
                    }
                }
            } finally {
                reader.releaseLock();
            }
        },

        processToolCalls: function(toolCalls, provider) {
            var self = this;
            var destructiveTools = ['write_file', 'edit_file', 'delete_file', 'run_php', 'install_plugin'];
            var alwaysConfirmTools = ['navigate']; // Always confirm, even in YOLO mode

            var needsConfirmation = [];
            var executeImmediately = [];

            toolCalls.forEach(function(tc) {
                if (alwaysConfirmTools.indexOf(tc.name) >= 0) {
                    needsConfirmation.push(tc);
                } else if (self.yoloMode || destructiveTools.indexOf(tc.name) < 0) {
                    executeImmediately.push(tc);
                } else {
                    needsConfirmation.push(tc);
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

            // Handle client-side tools
            if (toolName === 'get_page_html') {
                return this.executeGetPageHtml(toolCall);
            }

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

                        var toolResult = {
                            id: toolCall.id,
                            name: toolName,
                            input: toolCall.arguments,
                            result: response.success ? response.data : { error: errorMessage },
                            success: response.success
                        };
                        resolve(toolResult);
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
                            input: toolCall.arguments,
                            result: { error: errorMessage },
                            success: false
                        });
                    }
                });
            });
        },

        executeGetPageHtml: function(toolCall) {
            var args = toolCall.arguments || {};
            var selector = args.selector || 'body';
            var maxLength = args.max_length || 5000;

            var isAiAssistantElement = function(el) {
                if (!el) return false;
                if (el.id && el.id.indexOf('ai-assistant') === 0) return true;
                if (el.id === 'ai-conversation-modal') return true;
                if (el.className && typeof el.className === 'string' && el.className.indexOf('ai-assistant') >= 0) return true;
                return false;
            };

            var removeAiAssistantElements = function(container) {
                var aiElements = container.querySelectorAll('[id^="ai-assistant"], [class*="ai-assistant"], #ai-conversation-modal');
                aiElements.forEach(function(el) { el.remove(); });
            };

            return new Promise(function(resolve) {
                try {
                    var elements = document.querySelectorAll(selector);
                    var results = [];
                    var totalLength = 0;
                    var skippedCount = 0;

                    if (elements.length === 0) {
                        resolve({
                            id: toolCall.id,
                            name: 'get_page_html',
                            input: args,
                            result: {
                                error: 'No elements found matching selector: ' + selector,
                                selector: selector,
                                url: window.location.href
                            },
                            success: false
                        });
                        return;
                    }

                    elements.forEach(function(el, index) {
                        if (totalLength >= maxLength * 3) return;

                        if (isAiAssistantElement(el)) {
                            skippedCount++;
                            return;
                        }

                        var html;
                        if (el.tagName === 'BODY' || el.tagName === 'HTML' || el.id === 'wpwrap' || el.id === 'wpcontent') {
                            var clone = el.cloneNode(true);
                            removeAiAssistantElements(clone);
                            html = clone.outerHTML;
                        } else {
                            html = el.outerHTML;
                        }

                        if (html.length > maxLength) {
                            html = html.substring(0, maxLength) + '\n... (truncated, ' + (html.length - maxLength) + ' more chars)';
                        }
                        totalLength += html.length;

                        results.push({
                            index: index,
                            tagName: el.tagName.toLowerCase(),
                            id: el.id || null,
                            className: el.className || null,
                            html: html
                        });
                    });

                    resolve({
                        id: toolCall.id,
                        name: 'get_page_html',
                        input: args,
                        result: {
                            selector: selector,
                            url: window.location.href,
                            title: document.title,
                            matchCount: elements.length - skippedCount,
                            elements: results
                        },
                        success: true
                    });
                } catch (e) {
                    resolve({
                        id: toolCall.id,
                        name: 'get_page_html',
                        input: args,
                        result: {
                            error: 'Invalid selector or error: ' + e.message,
                            selector: selector
                        },
                        success: false
                    });
                }
            });
        },

        handleToolResults: function(results, provider) {
            var self = this;

            // Check for navigate result - handle specially since it causes page reload
            var navigateResult = results.find(function(r) {
                return r.name === 'navigate' && r.success && r.result && r.result.url;
            });

            // Deduplicate file reads to save context
            this.deduplicateFileReads(results);

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

            // Update token count
            this.updateTokenCount();

            // Handle navigate specially - save conversation then redirect
            if (navigateResult) {
                var targetUrl = navigateResult.result.url;
                this.addMessage('system', 'Navigating to: ' + targetUrl);
                this.setLoading(false);

                // Save conversation before navigating (page will reload)
                this.saveConversationThenNavigate(targetUrl);
                return;
            }

            // Save after tool execution so work can be resumed if interrupted
            this.autoSaveConversation();

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
                    input: action.arguments,
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
                        input: action.arguments,
                        result: { skipped: true, message: 'User declined to execute this action' },
                        success: false
                    };
                });
                this.handleToolResults(skippedResults, actions[0].provider);
            }
        },

        getActionDescription: function(toolName, args) {
            switch (toolName) {
                // File tools
                case 'read_file':
                    return 'Read: ' + (args.path || 'unknown');
                case 'write_file':
                    return 'Write: ' + (args.path || 'unknown');
                case 'edit_file':
                    var editCount = args.edits ? args.edits.length : 0;
                    return 'Edit: ' + (args.path || 'unknown') + ' (' + editCount + ' change' + (editCount !== 1 ? 's' : '') + ')';
                case 'delete_file':
                    return 'Delete: ' + (args.path || 'unknown');
                case 'list_directory':
                    return 'List: ' + (args.path || 'wp-content');
                case 'search_files':
                    return 'Search files: ' + (args.pattern || 'unknown');
                case 'search_content':
                    return 'Search for: "' + (args.needle || '').substring(0, 30) + (args.needle && args.needle.length > 30 ? '...' : '') + '"';
                // Database tools
                case 'db_query':
                    var sql = (args.sql || '').substring(0, 40);
                    return 'Query: ' + sql + (args.sql && args.sql.length > 40 ? '...' : '');
                // WordPress tools
                case 'get_plugins':
                    return 'List plugins';
                case 'get_themes':
                    return 'List themes';
                case 'install_plugin':
                    return 'Install plugin: ' + (args.slug || 'unknown') + (args.activate ? ' (+ activate)' : '');
                case 'run_php':
                    return 'Run PHP code';
                case 'navigate':
                    return 'Navigate to: ' + (args.url || 'unknown');
                case 'get_page_html':
                    return 'Get page HTML: ' + (args.selector || 'body');
                // Abilities tools
                case 'list_abilities':
                    return 'List abilities' + (args.category ? ' (' + args.category + ')' : '');
                case 'get_ability':
                    return 'Get ability: ' + (args.ability || 'unknown');
                case 'execute_ability':
                    return 'Execute: ' + (args.ability || 'unknown');
                default:
                    return toolName;
            }
        },

        addMessage: function(role, content, extraClass) {
            var $messages = $('#ai-assistant-messages');

            var messageClass = 'ai-message ai-message-' + role;
            if (extraClass) {
                messageClass += ' ' + extraClass;
            }
            content = this.formatContent(content);

            var $message = $('<div class="' + messageClass + '">' +
                '<div class="ai-message-content">' + content + '</div>' +
                '</div>');

            $messages.append($message);
            this.scrollToBottom();
        },

        startReply: function() {
            var $messages = $('#ai-assistant-messages');
            var $message = $('<div class="ai-message ai-message-assistant">' +
                '<div class="ai-message-content"></div>' +
                '</div>');
            $messages.append($message);
            this.scrollToBottom();
            return $message;
        },

        updateReply: function($message, text) {
            var $content = $message.find('.ai-message-content');
            $content.html(this.formatContent(text));
            this.scrollToBottom();
        },

        addToolUseMessage: function(toolName, input) {
            var $messages = $('#ai-assistant-messages');
            var inputStr = typeof input === 'object' ? JSON.stringify(input, null, 2) : String(input);
            var description = this.getActionDescription(toolName, input || {});

            var html = '<div class="ai-tool-result ai-tool-result-success">' +
                '<div class="ai-tool-header">' +
                '<span class="ai-tool-toggle">&#9654;</span>' +
                '<span class="ai-tool-icon">&#9881;</span>' +
                '<span class="ai-tool-name">' + $('<div>').text(description).html() + '</span>' +
                '</div>' +
                '<pre class="ai-tool-output">' + $('<div>').text(inputStr).html() + '</pre>' +
                '</div>';

            $messages.append(html);
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

            // Links [text](url)
            content = content.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');

            // Line breaks
            content = content.replace(/\n/g, '<br>');

            return content;
        },

        showToolResults: function(results) {
            var self = this;
            var $messages = $('#ai-assistant-messages');

            results.forEach(function(result) {
                var statusClass = result.success ? 'success' : 'error';
                var statusIcon = result.success ? '&#10003;' : '&#10007;';
                var description = self.getActionDescription(result.name, result.input || {});

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

                var inputHtml = '';
                var showInput = !result.success || result.name === 'run_php';
                if (showInput && result.input) {
                    var inputLabel = result.name === 'run_php' ? 'Code executed:' : 'Input parameters:';
                    var inputDisplay;

                    if (result.name === 'run_php' && result.input.code) {
                        inputDisplay = result.input.code;
                    } else {
                        var truncatedInput = {};
                        var maxValueLength = 500;
                        for (var key in result.input) {
                            if (result.input.hasOwnProperty(key)) {
                                var val = result.input[key];
                                if (typeof val === 'string' && val.length > maxValueLength) {
                                    truncatedInput[key] = val.substring(0, maxValueLength) + '... (' + val.length + ' chars total)';
                                } else if (Array.isArray(val)) {
                                    truncatedInput[key] = val.map(function(item) {
                                        if (typeof item === 'string' && item.length > maxValueLength) {
                                            return item.substring(0, maxValueLength) + '... (' + item.length + ' chars total)';
                                        } else if (typeof item === 'object' && item !== null) {
                                            var truncatedItem = {};
                                            for (var itemKey in item) {
                                                if (item.hasOwnProperty(itemKey)) {
                                                    var itemVal = item[itemKey];
                                                    if (typeof itemVal === 'string' && itemVal.length > maxValueLength) {
                                                        truncatedItem[itemKey] = itemVal.substring(0, maxValueLength) + '... (' + itemVal.length + ' chars total)';
                                                    } else {
                                                        truncatedItem[itemKey] = itemVal;
                                                    }
                                                }
                                            }
                                            return truncatedItem;
                                        }
                                        return item;
                                    });
                                } else {
                                    truncatedInput[key] = val;
                                }
                            }
                        }
                        inputDisplay = JSON.stringify(truncatedInput, null, 2);
                    }

                    inputHtml = '<div class="ai-tool-input-label">' + inputLabel + '</div>' +
                        '<pre class="ai-tool-input">' + $('<div>').text(inputDisplay).html() + '</pre>';
                }

                var content = '<div class="ai-tool-result ai-tool-result-' + statusClass + '">' +
                    '<div class="ai-tool-header">' +
                    '<span class="ai-tool-toggle">&#9654;</span>' +
                    '<span class="ai-tool-icon">' + statusIcon + '</span>' +
                    '<span class="ai-tool-name">' + self.escapeHtml(description) + '</span>' +
                    '</div>' +
                    inputHtml +
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
                aiAssistantConfig.strings.confirm + '</button>' +
                '<button id="ai-skip-all" class="button button-small">' +
                aiAssistantConfig.strings.cancel + '</button>' +
                '</div></div><div class="ai-pending-list">';

            actions.forEach(function(action) {
                var preview = self.getActionContentPreview(action.tool, action.arguments);
                var previewHtml = '';

                if (preview) {
                    var previewLabel = preview.isEdit ? 'Show changes' : 'Show content';
                    var contentStr = typeof preview.content === 'string' ? preview.content : String(preview.content || '');
                    var lineCount = (contentStr.match(/\n/g) || []).length + 1;
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
                        content = typeof args.content === 'string' ? args.content : JSON.stringify(args.content, null, 2);
                        if (content.length > 2000) {
                            content = content.substring(0, 2000) + '\n... (' + (content.length - 2000) + ' more characters)';
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

                            var search = typeof edit.search === 'string' ? edit.search : String(edit.search || '');
                            var replace = typeof edit.replace === 'string' ? edit.replace : String(edit.replace || '');
                            var diff = self.generateSmartDiff(search, replace);
                            diffLines = diffLines.concat(diff);
                        });
                        content = diffLines.join('\n');
                    }
                    break;
                case 'run_php':
                    if (args.code) {
                        content = typeof args.code === 'string' ? args.code : JSON.stringify(args.code, null, 2);
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
            // If there's an existing conversation, just hide it visually (pending new chat)
            if (this.messages.length > 0 && !this.pendingNewChat) {
                this.pendingNewChat = true;
                $('#ai-assistant-messages').addClass('ai-pending-new-chat');
                $('#ai-token-count').hide();
                $('#ai-assistant-new-chat').text('Undo').attr('id', 'ai-assistant-undo-new-chat');
                $('#ai-assistant-input').focus();
                return;
            }

            // Actually start a new chat
            this.messages = [];
            this.pendingActions = [];
            this.conversationId = 0;
            this.conversationTitle = '';
            this.conversationProvider = aiAssistantConfig.provider;
            this.conversationModel = aiAssistantConfig.model;
            this.pendingNewChat = false;
            this.updateSendButton();
            this.updateTokenCount();
            $('#ai-assistant-messages').removeClass('ai-pending-new-chat').empty();
            $('#ai-token-count').show();
            $('#ai-assistant-pending-actions').empty().hide();
            $('#ai-assistant-undo-new-chat').text('New Chat').attr('id', 'ai-assistant-new-chat');
            this.updateSidebarSelection();
            this.loadWelcomeMessage();
            $('#ai-assistant-input').focus();
        },

        undoNewChat: function() {
            this.pendingNewChat = false;
            $('#ai-assistant-messages').removeClass('ai-pending-new-chat');
            $('#ai-token-count').show();
            $('#ai-assistant-undo-new-chat').text('New Chat').attr('id', 'ai-assistant-new-chat');
            this.scrollToBottom();
            $('#ai-assistant-input').focus();
        },

        loadWelcomeMessage: function(provider, model) {
            var config = aiAssistantConfig;
            var useProvider = provider || config.provider;
            var useModel = model || config.model;

            if (!config.apiKey && useProvider !== 'local') {
                this.addMessage('system', 'Welcome! Please configure your API key in [Settings](' + config.settingsUrl + ') to start chatting.', 'ai-welcome-message');
            } else {
                var providerName = useProvider === 'anthropic' ? 'Anthropic' :
                                   useProvider === 'openai' ? 'OpenAI' :
                                   useProvider === 'local' ? 'Local LLM' : useProvider;
                var modelInfo = useModel ? ' (' + useModel + ')' : '';
                this.addMessage('assistant', 'Hello! I\'m your Playground AI Assistant. I can help you manage your WordPress installation - read and modify files, manage plugins, query the database, and more. What would you like to do?', 'ai-welcome-message');
                this.addMessage('system', 'You\'re chatting with **' + providerName + '**' + modelInfo, 'ai-model-info');
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

        saveYoloMode: function() {
            try {
                localStorage.setItem(this.yoloStorageKey, this.yoloMode ? '1' : '0');
            } catch (e) {
                console.warn('[AI Assistant] Could not save YOLO mode:', e);
            }
        },

        restoreYoloMode: function() {
            try {
                var stored = localStorage.getItem(this.yoloStorageKey);
                this.yoloMode = stored === '1';
                $('#ai-assistant-yolo').prop('checked', this.yoloMode);
            } catch (e) {
                console.warn('[AI Assistant] Could not restore YOLO mode:', e);
            }
        },

        loadDraftHistory: function() {
            try {
                var stored = localStorage.getItem(this.draftHistoryKey);
                if (stored) {
                    this.draftHistory = JSON.parse(stored);
                }
            } catch (e) {
                console.warn('[AI Assistant] Could not load draft history:', e);
                this.draftHistory = [];
            }
        },

        saveDraftHistory: function() {
            try {
                localStorage.setItem(this.draftHistoryKey, JSON.stringify(this.draftHistory));
            } catch (e) {
                console.warn('[AI Assistant] Could not save draft history:', e);
            }
        },

        addToDraftHistory: function(message) {
            if (!message || message.trim() === '') return;

            // Don't add duplicates of the most recent entry
            if (this.draftHistory.length > 0 && this.draftHistory[0] === message) {
                return;
            }

            // Add to the beginning
            this.draftHistory.unshift(message);

            // Trim to max size
            if (this.draftHistory.length > this.draftHistoryMax) {
                this.draftHistory = this.draftHistory.slice(0, this.draftHistoryMax);
            }

            this.saveDraftHistory();
        },

        navigateDraftHistory: function(direction) {
            if (this.draftHistory.length === 0) return;

            var newIndex = this.draftHistoryIndex + direction;

            // Clamp to valid range (-1 means current/empty)
            if (newIndex < -1) newIndex = -1;
            if (newIndex >= this.draftHistory.length) newIndex = this.draftHistory.length - 1;

            if (newIndex === this.draftHistoryIndex) return;

            this.draftHistoryIndex = newIndex;

            var $input = $('#ai-assistant-input');
            if (newIndex === -1) {
                // Back to empty/current draft
                $input.val('');
            } else {
                $input.val(this.draftHistory[newIndex]);
            }

            // Move cursor to end
            var input = $input[0];
            input.selectionStart = input.selectionEnd = input.value.length;
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

        isProviderConfigured: function() {
            return !!(this.conversationProvider && this.conversationModel && this.systemPrompt);
        },

        updateSendButton: function() {
            var $send = $('#ai-assistant-send');
            var disabled = this.isLoading || !this.isProviderConfigured();
            $send.prop('disabled', disabled);
        },

        setLoading: function(loading) {
            this.isLoading = loading;
            var $loading = $('#ai-assistant-loading');

            if (loading) {
                $loading.show();
                window.addEventListener('beforeunload', this.beforeUnloadHandler);
            } else {
                $loading.hide();
                window.removeEventListener('beforeunload', this.beforeUnloadHandler);
            }
            this.updateSendButton();
        },

        beforeUnloadHandler: function(e) {
            e.preventDefault();
            e.returnValue = 'AI Assistant is still processing. Are you sure you want to leave?';
            return e.returnValue;
        },

        isNearBottom: function() {
            var $messages = $('#ai-assistant-messages');
            if (!$messages.length || !$messages[0]) return true;

            var threshold = 100; // pixels from bottom
            var scrollTop = $messages.scrollTop();
            var scrollHeight = $messages[0].scrollHeight;
            var clientHeight = $messages[0].clientHeight;

            return (scrollHeight - scrollTop - clientHeight) < threshold;
        },

        scrollToBottom: function(force) {
            var $messages = $('#ai-assistant-messages');
            if (!$messages.length || !$messages[0]) return;

            // Don't auto-scroll if user has scrolled up (unless forced)
            if (!force && !this.isNearBottom()) {
                return;
            }

            // On mobile (full page), the page scrolls instead of the container
            if (this.isFullPage && window.innerWidth <= 782) {
                window.scrollTo(0, document.body.scrollHeight);
            } else {
                $messages.scrollTop($messages[0].scrollHeight);
            }
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
                    messages: btoa(unescape(encodeURIComponent(JSON.stringify(this.messages)))),
                    title: this.conversationTitle,
                    provider: aiAssistantConfig.provider,
                    model: aiAssistantConfig.model
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
                        // Decode base64 and parse JSON
                        try {
                            var base64 = response.data.messages_base64 || '';
                            if (base64) {
                                var json = decodeURIComponent(escape(atob(base64)));
                                self.messages = JSON.parse(json);
                            } else {
                                self.messages = [];
                            }
                        } catch (e) {
                            console.error('[AI Assistant] Failed to decode messages:', e);
                            self.messages = [];
                        }

                        // Clear and rebuild UI
                        $('#ai-assistant-messages').empty();

                        // Set provider/model from loaded conversation (or fall back to current settings)
                        var convProvider = response.data.provider || aiAssistantConfig.provider;
                        var convModel = response.data.model || aiAssistantConfig.model;
                        self.conversationProvider = convProvider;
                        self.conversationModel = convModel;
                        self.updateSendButton();
                        self.updateTokenCount();

                        // Show welcome message with provider/model info at the top
                        self.loadWelcomeMessage(convProvider, convModel);

                        // Then rebuild the conversation messages
                        self.rebuildMessagesUI();

                        self.updateSidebarSelection();
                        $('#ai-assistant-input').focus();

                        // Auto-read files that were worked on in this conversation
                        self.autoReadConversationFiles();

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
                }
            });
        },

        rebuildMessagesUI: function() {
            var self = this;

            this.messages.forEach(function(msg, index) {
                if (msg.role === 'user') {
                    // User messages can be string or array (tool_result)
                    if (typeof msg.content === 'string' && msg.content.trim()) {
                        self.addMessage('user', msg.content);
                    } else if (Array.isArray(msg.content)) {
                        msg.content.forEach(function(block) {
                            if (block.type === 'tool_result') {
                                // Tool results are shown inline with tool_use, skip
                            } else if (block.type === 'text' && block.text && block.text.trim()) {
                                self.addMessage('user', block.text);
                            }
                        });
                    }
                } else if (msg.role === 'assistant') {
                    // Handle both string content and array content (with tool_use blocks)
                    if (typeof msg.content === 'string' && msg.content.trim()) {
                        self.addMessage('assistant', msg.content);
                    } else if (Array.isArray(msg.content)) {
                        msg.content.forEach(function(block) {
                            if (block.type === 'text' && block.text && block.text.trim()) {
                                self.addMessage('assistant', block.text);
                            } else if (block.type === 'tool_use') {
                                self.addToolUseMessage(block.name, block.input || block.arguments || {});
                            }
                        });
                    }
                    // OpenAI format: assistant with tool_calls
                    if (msg.tool_calls && Array.isArray(msg.tool_calls)) {
                        msg.tool_calls.forEach(function(tc) {
                            var args = tc.function ? tc.function.arguments : tc.arguments;
                            var name = tc.function ? tc.function.name : tc.name;
                            try {
                                args = JSON.parse(args);
                            } catch(e) {}
                            self.addToolUseMessage(name, args || {});
                        });
                    }
                } else if (msg.role === 'tool') {
                    // OpenAI format tool result - skip, shown with tool_use
                }
            });

            // Delay scroll to ensure DOM has updated
            var self = this;
            setTimeout(function() {
                self.scrollToBottom(true);
            }, 100);
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

        estimateTokens: function() {
            var totalChars = this.systemPrompt.length;

            this.messages.forEach(function(msg) {
                if (typeof msg.content === 'string') {
                    totalChars += msg.content.length;
                } else if (Array.isArray(msg.content)) {
                    msg.content.forEach(function(block) {
                        if (block.type === 'text' && block.text) {
                            totalChars += block.text.length;
                        } else if (block.type === 'tool_use' && block.input) {
                            totalChars += JSON.stringify(block.input).length;
                        } else if (block.type === 'tool_result' && block.content) {
                            totalChars += block.content.length;
                        }
                    });
                }
                // OpenAI format tool calls
                if (msg.tool_calls) {
                    totalChars += JSON.stringify(msg.tool_calls).length;
                }
            });

            // Rough estimate: ~4 characters per token
            return Math.ceil(totalChars / 4);
        },

        updateTokenCount: function() {
            var tokens = this.estimateTokens();
            var display = tokens.toLocaleString() + ' tokens';

            // Color coding based on token count
            var $counter = $('#ai-token-count');
            $counter.text(display);
            $counter.removeClass('ai-tokens-warning ai-tokens-danger');

            if (tokens > 100000) {
                $counter.addClass('ai-tokens-danger');
            } else if (tokens > 50000) {
                $counter.addClass('ai-tokens-warning');
            }
        },

        deduplicateFileReads: function(newResults) {
            var self = this;

            // Find paths of files being read in new results
            var newReadPaths = {};
            newResults.forEach(function(r) {
                if (r.name === 'read_file' && r.success && r.result && r.result.path) {
                    newReadPaths[r.result.path] = r.id;
                }
            });

            if (Object.keys(newReadPaths).length === 0) return;

            // Find and remove old read_file tool uses and results for these paths
            var oldToolIds = new Set();

            this.messages.forEach(function(msg) {
                if (msg.role === 'assistant' && Array.isArray(msg.content)) {
                    msg.content.forEach(function(block) {
                        if (block.type === 'tool_use' && block.name === 'read_file' &&
                            block.input && block.input.path && newReadPaths[block.input.path] &&
                            block.id !== newReadPaths[block.input.path]) {
                            oldToolIds.add(block.id);
                        }
                    });
                }
            });

            if (oldToolIds.size === 0) return;

            // Remove old tool_use blocks and their results
            this.messages = this.messages.map(function(msg) {
                if (msg.role === 'assistant' && Array.isArray(msg.content)) {
                    msg.content = msg.content.filter(function(block) {
                        return !(block.type === 'tool_use' && oldToolIds.has(block.id));
                    });
                }
                if (msg.role === 'user' && Array.isArray(msg.content)) {
                    msg.content = msg.content.filter(function(block) {
                        return !(block.type === 'tool_result' && oldToolIds.has(block.tool_use_id));
                    });
                }
                // OpenAI format
                if (msg.role === 'tool' && oldToolIds.has(msg.tool_call_id)) {
                    return null;
                }
                return msg;
            }).filter(function(msg) {
                if (msg === null) return false;
                if (Array.isArray(msg.content) && msg.content.length === 0) return false;
                return true;
            });
        },

        autoSaveConversation: function() {
            if (this.autoSave && this.messages.length > 0) {
                // Generate title if this is a new conversation
                if (!this.conversationTitle && this.messages.length >= 2) {
                    this.generateConversationTitle();
                    return; // generateConversationTitle will save after title is generated
                }
                this.saveConversation(true);
            }
        },

        saveConversationThenNavigate: function(targetUrl) {
            var self = this;

            if (this.messages.length === 0) {
                window.location.href = targetUrl;
                return;
            }

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_save_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: this.conversationId,
                    messages: btoa(unescape(encodeURIComponent(JSON.stringify(this.messages)))),
                    title: this.conversationTitle,
                    provider: this.conversationProvider,
                    model: this.conversationModel
                },
                success: function() {
                    window.location.href = targetUrl;
                },
                error: function() {
                    console.error('[AI Assistant] Failed to save conversation before navigation');
                    window.location.href = targetUrl;
                }
            });
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
                    }
                })
                .catch(function(err) {
                    console.error('[AI Assistant] Title generation failed:', err);
                });
            } else {
                var endpoint = (config.localEndpoint || 'http://localhost:11434').replace(/\/$/, '');
                var model = self.conversationModel || config.model;

                fetch(endpoint + '/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        model: model,
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
                    }
                })
                .catch(function(err) {
                    console.error('[AI Assistant] Title generation failed:', err);
                    var words = userContent.split(/\s+/).slice(0, 6).join(' ');
                    self.conversationTitle = words.length > 50 ? words.substring(0, 50) + '...' : words;
                    self.saveConversation(true);
                    self.loadSidebarConversations();
                });
            }
        }
    };

    $(document).ready(function() {
        window.aiAssistant.init();
    });

})(jQuery);
