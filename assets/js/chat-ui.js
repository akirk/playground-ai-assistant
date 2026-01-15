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

        showToolResults: function(results) {
            var self = this;
            var $messages = $('#ai-assistant-messages');

            results.forEach(function(result) {
                var statusClass = result.success ? 'success' : 'error';
                var statusIcon = result.success ? '&#10003;' : '&#10007;';
                var description = self.getActionDescription(result.name, result.input || {});

                var resultStr;
                if (typeof result.result === 'object') {
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
