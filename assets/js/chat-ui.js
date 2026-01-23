(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        addMessage: function(role, content, extraClass) {
            var $messages = $('#ai-assistant-messages');

            var messageClass = 'ai-message ai-message-' + role;
            if (extraClass) {
                messageClass += ' ' + extraClass;
            }
            var formattedContent = this.formatContent(content);

            var $message = $('<div class="' + messageClass + '">' +
                '<div class="ai-message-content">' + formattedContent + '</div>' +
                '</div>');

            if (role === 'assistant' && !extraClass) {
                $message.attr('data-raw-content', content);
                $message.append(this.getMessageActions());
                this.updateSummarizeVisibility();
            } else if (role === 'user') {
                $message.attr('data-raw-content', content);
                $message.append(this.getUserMessageActions());
            }

            $messages.append($message);
            this.scrollToBottom();
        },

        getMessageActions: function() {
            return '<div class="ai-message-actions">' +
                '<button type="button" class="ai-action-btn ai-action-copy" title="Copy">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>' +
                '</button>' +
                '<button type="button" class="ai-action-btn ai-action-retry" title="Retry">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 005.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 013.51 15"/></svg>' +
                '</button>' +
                '<button type="button" class="ai-action-btn ai-action-summarize" title="Summarize conversation">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>' +
                '</button>' +
                '</div>';
        },

        getUserMessageActions: function() {
            return '<div class="ai-message-actions">' +
                '<button type="button" class="ai-action-btn ai-action-copy" title="Copy">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>' +
                '</button>' +
                '</div>';
        },

        updateSummarizeVisibility: function() {
            var $messages = $('#ai-assistant-messages');
            $messages.find('.ai-action-summarize').hide();
            var $lastAssistant = $messages.find('.ai-message-assistant').last();
            if ($lastAssistant.length && this.conversationId && this.conversationId > 0) {
                $lastAssistant.find('.ai-action-summarize').show();
            }
        },

        startReply: function() {
            var $messages = $('#ai-assistant-messages');
            var $message = $('<div class="ai-message ai-message-assistant ai-message-streaming">' +
                '<div class="ai-message-content"></div>' +
                '</div>');
            $messages.append($message);
            this.scrollToBottom();
            return $message;
        },

        updateReply: function($message, text) {
            var $content = $message.find('.ai-message-content');
            $content.html(this.formatContent(text));
            $message.attr('data-raw-content', text);
            this.scrollToBottom();
        },

        finalizeReply: function($message) {
            $message.removeClass('ai-message-streaming');
            if (!$message.find('.ai-message-actions').length) {
                $message.append(this.getMessageActions());
            }
            this.updateSummarizeVisibility();
        },

        addToolUseMessage: function(toolName, input) {
            var $messages = $('#ai-assistant-messages');
            var description = this.getActionDescription(toolName, input || {});

            var html = '<div class="ai-tool-card ai-tool-card-completed">' +
                '<div class="ai-tool-card-header">' +
                '<span class="ai-tool-card-name">' + this.escapeHtml(toolName) + '</span>' +
                '<span class="ai-tool-card-status">Completed</span>' +
                '</div>' +
                '<div class="ai-tool-card-desc">' + this.escapeHtml(description) + '</div>' +
                '</div>';

            $messages.append(html);
        },

        formatContent: function(content) {
            if (!content) return '';

            // Trim trailing whitespace/newlines before processing
            content = content.replace(/\s+$/, '');

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

            // Links
            content = content.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');

            // Headings (must be before line breaks)
            content = content.replace(/^### (.+)$/gm, '<h4>$1</h4>');
            content = content.replace(/^## (.+)$/gm, '<h3>$1</h3>');
            content = content.replace(/^# (.+)$/gm, '<h2>$1</h2>');

            // Line breaks (skip after block elements)
            content = content.replace(/(<\/h[234]>)\n/g, '$1');
            content = content.replace(/\n/g, '<br>');

            return content;
        },

        loadWelcomeMessage: function() {
            if (!this.isProviderConfigured()) {
                this.addMessage('system', 'Welcome! Please configure your API key in [Settings](' + aiAssistantConfig.settingsUrl + ') to start chatting.', 'ai-welcome-message');
            } else {
                var provider = this.getProvider();
                var model = this.getModel();
                var providerName = this.getProviderName(provider);
                var modelInfo = model ? ' (' + model + ')' : '';
                this.addMessage('assistant', 'Hello! I\'m your Playground AI Assistant. I can help you manage your WordPress installation - read and modify files, manage plugins, query the database, and more. What would you like to do?', 'ai-welcome-message');
                this.addMessage('system', 'You\'re chatting with **' + providerName + '**' + modelInfo, 'ai-model-info');
            }
        },

        loadConversationWelcome: function(provider, model) {
            this.addMessage('assistant', 'Hello! I\'m your Playground AI Assistant. I can help you manage your WordPress installation - read and modify files, manage plugins, query the database, and more. What would you like to do?', 'ai-welcome-message');
            // Only show model info if the conversation has it saved
            if (provider) {
                var providerName = this.getProviderName(provider);
                var modelInfo = model ? ' (' + model + ')' : '';
                this.addMessage('system', 'You\'re chatting with **' + providerName + '**' + modelInfo, 'ai-model-info');
            }
        },

        getProviderName: function(provider) {
            return provider === 'anthropic' ? 'Anthropic' :
                   provider === 'openai' ? 'OpenAI' :
                   provider === 'local' ? 'Local LLM' : provider;
        },

        rebuildMessagesUI: function() {
            var self = this;

            this.messages.forEach(function(msg) {
                if (msg.role === 'user') {
                    if (typeof msg.content === 'string' && msg.content.trim()) {
                        self.addMessage('user', msg.content);
                    } else if (Array.isArray(msg.content)) {
                        msg.content.forEach(function(block) {
                            if (block.type === 'tool_result') {
                                // Skip - shown inline with tool_use
                            } else if (block.type === 'text' && block.text && block.text.trim()) {
                                self.addMessage('user', block.text);
                            }
                        });
                    }
                } else if (msg.role === 'assistant') {
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
                    // OpenAI format
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
                    // Skip - shown with tool_use
                }
            });

            setTimeout(function() {
                self.scrollToBottom(true);
            }, 100);
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
                if (msg.tool_calls) {
                    totalChars += JSON.stringify(msg.tool_calls).length;
                }
            });

            return Math.ceil(totalChars / 4);
        },

        updateTokenCount: function() {
            var tokens = this.estimateTokens();
            var display = tokens.toLocaleString() + ' tokens';

            var $counter = $('#ai-token-count');
            $counter.text(display);
            $counter.removeClass('ai-tokens-warning ai-tokens-danger');

            if (tokens > 100000) {
                $counter.addClass('ai-tokens-danger');
            } else if (tokens > 50000) {
                $counter.addClass('ai-tokens-warning');
            }
        },

        toolCardsState: {},

        formatBytes: function(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },

        getToolCardsContainer: function() {
            var $container = $('#ai-assistant-tool-cards');
            if ($container.length === 0) {
                $container = $('<div id="ai-assistant-tool-cards"></div>');
                $('#ai-assistant-messages').append($container);
            } else {
                // Move container to end of messages if it already exists
                // This handles cases where LLM responds multiple times with tool calls
                $('#ai-assistant-messages').append($container);
            }
            return $container;
        },

        showToolProgress: function(toolName, bytesReceived, toolId, partialInput) {
            toolId = toolId || 'tool-' + toolName;

            if (!this.toolCardsState[toolId]) {
                this.toolCardsState[toolId] = {
                    name: toolName,
                    bytes: 0,
                    state: 'generating',
                    partialDesc: null
                };
                this.addToolCard(toolId, toolName);
            }

            this.toolCardsState[toolId].bytes = bytesReceived;
            this.updateToolCardProgress(toolId, bytesReceived);

            // Try to extract description from partial JSON
            if (partialInput && !this.toolCardsState[toolId].partialDesc) {
                var desc = this.extractPartialDescription(toolName, partialInput);
                if (desc) {
                    this.toolCardsState[toolId].partialDesc = desc;
                    var $card = $('[data-tool-id="' + toolId + '"]');
                    if ($card.length) {
                        $card.find('.ai-tool-card-desc').text(desc);
                    }
                }
            }
        },

        extractPartialDescription: function(toolName, partialJson) {
            var pathMatch, match;
            switch (toolName) {
                case 'write_file':
                case 'read_file':
                case 'delete_file':
                case 'edit_file':
                    pathMatch = partialJson.match(/"path"\s*:\s*"([^"]+)"/);
                    if (pathMatch) {
                        var verb = toolName === 'write_file' ? 'Write' :
                                   toolName === 'read_file' ? 'Read' :
                                   toolName === 'delete_file' ? 'Delete' : 'Edit';
                        return verb + ': ' + pathMatch[1];
                    }
                    break;
                case 'run_php':
                    // Can't really preview code meaningfully
                    return null;
                case 'search_content':
                    match = partialJson.match(/"needle"\s*:\s*"([^"]+)"/);
                    if (match) {
                        var needle = match[1].substring(0, 30);
                        return 'Search for: "' + needle + (match[1].length > 30 ? '...' : '') + '"';
                    }
                    break;
                case 'db_query':
                    match = partialJson.match(/"sql"\s*:\s*"([^"]+)"/);
                    if (match) {
                        var sql = match[1].substring(0, 40);
                        return 'Query: ' + sql + (match[1].length > 40 ? '...' : '');
                    }
                    break;
            }
            return null;
        },

        addToolCard: function(toolId, toolName) {
            var $container = this.getToolCardsContainer();
            var description = this.getActionDescription(toolName, {});

            var $card = $('<div class="ai-tool-card ai-tool-card-generating" data-tool-id="' + toolId + '">' +
                '<div class="ai-tool-card-header">' +
                '<span class="ai-tool-card-spinner"></span>' +
                '<span class="ai-tool-card-name">' + this.escapeHtml(toolName) + '</span>' +
                '<span class="ai-tool-card-status">Generating...</span>' +
                '<span class="ai-tool-card-size">0 B</span>' +
                '</div>' +
                '<div class="ai-tool-card-desc">' + this.escapeHtml(description) + '</div>' +
                '<div class="ai-tool-card-preview"></div>' +
                '<div class="ai-tool-card-actions"></div>' +
                '</div>');

            $container.append($card);
            this.scrollToBottom();
        },

        updateToolCardProgress: function(toolId, bytes) {
            if (this.toolCardsState[toolId]) {
                this.toolCardsState[toolId].bytes = bytes;
            }
            var $card = $('[data-tool-id="' + toolId + '"]');
            if ($card.length) {
                $card.find('.ai-tool-card-size').text(this.formatBytes(bytes));
            }
        },

        updateToolCardDescription: function(toolId, toolName, args) {
            var $card = $('[data-tool-id="' + toolId + '"]');

            // Create card if it doesn't exist (for providers that report tools at completion)
            if (!$card.length) {
                this.showToolProgress(toolName, JSON.stringify(args || {}).length, toolId);
                $card = $('[data-tool-id="' + toolId + '"]');
            }

            if ($card.length) {
                var description = this.getActionDescription(toolName, args);
                $card.find('.ai-tool-card-desc').text(description);

                // Show size now that tool is fully received
                var argsStr = JSON.stringify(args || {});
                var bytes = argsStr.length;
                $card.find('.ai-tool-card-size').text(this.formatBytes(bytes));

                var preview = this.getActionContentPreview(toolName, args);
                if (preview) {
                    var previewLabel = preview.isEdit ? 'Show changes' : 'Show content';
                    var contentStr = typeof preview.content === 'string' ? preview.content : String(preview.content || '');
                    contentStr = contentStr.trim();
                    var lineCount = (contentStr.match(/\n/g) || []).length + 1;
                    var previewHtml = '<div class="ai-action-preview">' +
                        '<button type="button" class="ai-action-preview-toggle">' +
                        '<span class="dashicons dashicons-arrow-right-alt2"></span>' +
                        previewLabel + ' (' + lineCount + ' lines)</button>' +
                        '<div class="ai-action-preview-content"><pre>' + preview.html + '</pre></div>' +
                        '</div>';
                    $card.find('.ai-tool-card-preview').html(previewHtml);
                }
            }
        },

        setToolCardState: function(toolId, state, options) {
            options = options || {};
            var $card = $('[data-tool-id="' + toolId + '"]');
            if (!$card.length) return;

            $card.removeClass('ai-tool-card-generating ai-tool-card-ready ai-tool-card-pending ai-tool-card-executing ai-tool-card-completed ai-tool-card-error ai-tool-card-skipped');
            $card.addClass('ai-tool-card-' + state);

            var $status = $card.find('.ai-tool-card-status');
            var $actions = $card.find('.ai-tool-card-actions');
            var $spinner = $card.find('.ai-tool-card-spinner');

            switch (state) {
                case 'ready':
                    $status.text('Ready');
                    $spinner.hide();
                    $actions.empty();
                    break;
                case 'pending':
                    $status.text('Waiting for approval');
                    $spinner.hide();
                    $actions.html(
                        '<button class="button button-primary button-small ai-tool-approve" data-tool-id="' + toolId + '">Approve</button>' +
                        '<button class="button button-small ai-tool-skip" data-tool-id="' + toolId + '">Skip</button>'
                    );
                    break;
                case 'executing':
                    $status.text('Executing...');
                    $spinner.show();
                    $actions.empty();
                    break;
                case 'completed':
                    $status.text(options.message || 'Completed');
                    $spinner.hide();
                    $actions.empty();
                    $card.find('.ai-tool-card-size').hide();
                    // Move completed card out of the tool-cards container so it persists
                    var $container = $('#ai-assistant-tool-cards');
                    if ($card.parent().is($container)) {
                        $card.insertBefore($container);
                    }
                    break;
                case 'error':
                    $status.text(options.message || 'Error');
                    $spinner.hide();
                    $actions.empty();
                    // Move error card out of the tool-cards container so it persists
                    var $errorContainer = $('#ai-assistant-tool-cards');
                    if ($card.parent().is($errorContainer)) {
                        $card.insertBefore($errorContainer);
                    }
                    break;
                case 'skipped':
                    $status.text('Skipped');
                    $spinner.hide();
                    $actions.empty();
                    // Move skipped card out of the tool-cards container so it persists
                    var $skipContainer = $('#ai-assistant-tool-cards');
                    if ($card.parent().is($skipContainer)) {
                        $card.insertBefore($skipContainer);
                    }
                    break;
            }

            if (this.toolCardsState[toolId]) {
                this.toolCardsState[toolId].state = state;
            }

            this.scrollToBottom();
        },

        clearToolCards: function() {
            this.toolCardsState = {};
            $('#ai-assistant-tool-cards').remove();
        },

        hideToolProgress: function() {
            // Legacy compatibility - now just clears incomplete cards
            var self = this;
            Object.keys(this.toolCardsState).forEach(function(toolId) {
                if (self.toolCardsState[toolId].state === 'generating') {
                    $('[data-tool-id="' + toolId + '"]').remove();
                    delete self.toolCardsState[toolId];
                }
            });
        },

        deduplicateFileReads: function(newResults) {
            var newReadPaths = {};
            newResults.forEach(function(r) {
                if (r.name === 'read_file' && r.success && r.result && r.result.path) {
                    newReadPaths[r.result.path] = r.id;
                }
            });

            if (Object.keys(newReadPaths).length === 0) return;

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
                if (msg.role === 'tool' && oldToolIds.has(msg.tool_call_id)) {
                    return null;
                }
                return msg;
            }).filter(function(msg) {
                if (msg === null) return false;
                if (Array.isArray(msg.content) && msg.content.length === 0) return false;
                return true;
            });
        }
    });

})(jQuery);
