(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        sendMessage: function() {
            if (this.isLoading || !this.isProviderConfigured()) return;

            var $input = $('#ai-assistant-input');
            var message = $input.val().trim();

            if (!message) return;

            if (this.pendingNewChat) {
                this.messages = [];
                this.pendingActions = [];
                this.conversationId = 0;
                this.conversationTitle = '';
                this.conversationProvider = this.getProvider();
                this.conversationModel = this.getModel();
                this.pendingNewChat = false;
                this.pendingChatOriginalHtml = null;
                $('#ai-assistant-messages').empty();
                $('#ai-token-count').show();
                $('#ai-assistant-pending-actions').empty().hide();
                $('#ai-assistant-undo-new-chat').text('New Chat').attr('id', 'ai-assistant-new-chat');
                this.updateSidebarSelection();
                this.loadWelcomeMessage();
            }

            this.clearToolCards();
            this.pendingToolResults = [];
            this.streamComplete = false;
            this.executingToolCount = 0;
            this.processedToolIds = {};
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
            var provider = this.conversationProvider || this.getProvider();

            this.hideToolProgress();
            this.setLoading(true);
            this.streamComplete = false;
            this.executingToolCount = 0;
            this.processedToolIds = {};

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
                                // Yield to browser between events to allow repaints
                                await new Promise(function(r) { requestAnimationFrame(r); });
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
                                // Yield to browser between events to allow repaints
                                await new Promise(function(r) { requestAnimationFrame(r); });
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

        callAnthropic: async function() {
            var self = this;
            var model = this.conversationModel || this.getModel();
            var apiKey = this.getApiKey('anthropic');

            try {
                var response = await fetch('https://api.anthropic.com/v1/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'x-api-key': apiKey,
                        'anthropic-version': '2023-06-01',
                        'anthropic-dangerous-direct-browser-access': 'true'
                    },
                    body: JSON.stringify({
                        model: model,
                        max_tokens: 16384,
                        stream: true,
                        system: this.systemPrompt,
                        messages: this.messages,
                        tools: this.getTools()
                    }),
                    signal: this.abortController ? this.abortController.signal : undefined
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
                var stopReason = null;

                for await (var event of this.readSSEStream(response)) {
                    switch (event.type) {
                        case 'content_block_start':
                            currentBlock = { ...event.content_block };
                            if (currentBlock.type === 'tool_use') {
                                currentBlock.input = '';
                                self.showToolProgress(currentBlock.name, 0, currentBlock.id);
                            } else if (currentBlock.type === 'text') {
                                currentBlock.text = '';
                            }
                            break;

                        case 'content_block_delta':
                            if (event.delta.type === 'text_delta') {
                                textContent += event.delta.text;
                                if (currentBlock && currentBlock.type === 'text') {
                                    currentBlock.text += event.delta.text;
                                }
                                this.updateReply($reply, textContent);
                            } else if (event.delta.type === 'input_json_delta') {
                                if (currentBlock) {
                                    currentBlock.input += event.delta.partial_json;
                                    self.showToolProgress(currentBlock.name, currentBlock.input.length, currentBlock.id, currentBlock.input);
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
                                    // Process tool immediately - don't wait for stream to end
                                    self.processToolCallImmediate(currentBlock.id, currentBlock.name, currentBlock.input, 'anthropic');
                                }
                                contentBlocks.push(currentBlock);
                                currentBlock = null;
                            }
                            break;

                        case 'message_delta':
                            if (event.delta && event.delta.stop_reason) {
                                stopReason = event.delta.stop_reason;
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
                } else {
                    this.finalizeReply($reply);
                }

                var filteredBlocks = contentBlocks.filter(function(block) {
                    return block.type !== 'text' || (block.text && block.text.length > 0);
                });
                this.messages.push({ role: 'assistant', content: filteredBlocks });
                this.updateTokenCount();

                // Save conversation before processing tools (in case user reloads while pending)
                this.autoSaveConversation();

                // Mark any incomplete tools (e.g., truncated by max_tokens)
                if (this.toolCardsState) {
                    var processedIds = this.processedToolIds || {};
                    Object.keys(this.toolCardsState).forEach(function(toolId) {
                        if (!processedIds[toolId] && self.toolCardsState[toolId].state === 'generating') {
                            var message = stopReason === 'max_tokens' ? 'Truncated (max tokens)' : 'Incomplete';
                            self.setToolCardState(toolId, 'error', { message: message });
                        }
                    });
                }

                // Mark stream as complete and check if all tools resolved
                this.streamComplete = true;
                if (toolCalls.length > 0) {
                    this.checkAllToolsResolved();
                } else {
                    this.setLoading(false);
                    this.autoSaveConversation();
                }

            } catch (error) {
                this.hideToolProgress();
                this.pendingToolResults = [];
                this.pendingActions = [];
                this.setLoading(false);
                if (error.name !== 'AbortError') {
                    this.addMessage('error', 'Anthropic API error: ' + error.message);
                }
            }
        },

        callOpenAI: async function() {
            var self = this;
            var model = this.conversationModel || this.getModel();
            var apiKey = this.getApiKey('openai');

            try {
                var requestMessages = [
                    { role: 'system', content: this.systemPrompt },
                    ...this.messages
                ];

                var response = await fetch('https://api.openai.com/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + apiKey
                    },
                    body: JSON.stringify({
                        model: model,
                        max_tokens: 16384,
                        stream: true,
                        messages: requestMessages,
                        tools: this.getToolsOpenAI()
                    }),
                    signal: this.abortController ? this.abortController.signal : undefined
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
                            var toolInfo = toolCallsMap[idx];
                            if (toolInfo.function.name && toolInfo.id) {
                                self.showToolProgress(toolInfo.function.name, toolInfo.function.arguments.length, toolInfo.id, toolInfo.function.arguments);
                            }
                        });
                    }
                }

                var toolCalls = [];
                Object.keys(toolCallsMap).forEach(function(idx) {
                    var tc = toolCallsMap[idx];
                    var parsedArgs = JSON.parse(tc.function.arguments || '{}');
                    toolCalls.push({
                        id: tc.id,
                        name: tc.function.name,
                        arguments: parsedArgs
                    });
                    self.updateToolCardDescription(tc.id, tc.function.name, parsedArgs);
                });

                if (!textContent) {
                    $reply.remove();
                } else {
                    this.finalizeReply($reply);
                }

                var message = { role: 'assistant', content: textContent || null };
                if (Object.keys(toolCallsMap).length > 0) {
                    message.tool_calls = Object.values(toolCallsMap);
                }
                this.messages.push(message);
                this.updateTokenCount();

                // Save conversation before processing tools (in case user reloads while pending)
                this.autoSaveConversation();

                if (toolCalls.length > 0) {
                    this.processToolCalls(toolCalls, 'openai');
                } else {
                    this.setLoading(false);
                }

            } catch (error) {
                this.hideToolProgress();
                this.pendingToolResults = [];
                this.pendingActions = [];
                this.setLoading(false);
                if (error.name !== 'AbortError') {
                    this.addMessage('error', 'OpenAI API error: ' + error.message);
                }
            }
        },

        callLocalLLM: async function() {
            var self = this;
            var endpoint = this.getLocalEndpoint().replace(/\/$/, '');

            try {
                var requestMessages = [
                    { role: 'system', content: this.systemPrompt },
                    ...this.messages
                ];

                var model = this.conversationModel || this.getModel();
                var useOllamaApi = false;

                var abortSignal = this.abortController ? this.abortController.signal : undefined;

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
                    }),
                    signal: abortSignal
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
                        }),
                        signal: abortSignal
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
                        if (chunk.error) {
                            throw new Error(chunk.error.message || chunk.error || 'Unknown error from Ollama');
                        }
                        if (chunk.message && chunk.message.content) {
                            textContent += chunk.message.content;
                            this.updateReply($reply, textContent);
                        }
                        if (chunk.message && chunk.message.tool_calls) {
                            chunk.message.tool_calls.forEach(function(tc, idx) {
                                toolCallsMap[idx] = tc;
                                if (tc.function && tc.function.name) {
                                    var toolId = tc.id || 'ollama_tool_' + idx;
                                    var argsStr = tc.function.arguments ? JSON.stringify(tc.function.arguments) : '';
                                    self.showToolProgress(tc.function.name, argsStr.length, toolId, argsStr);
                                }
                            });
                        }
                    }
                } else {
                    for await (var chunk of this.readSSEStream(response)) {
                        if (chunk.error) {
                            throw new Error(chunk.error.message || chunk.message || 'Unknown error from local LLM');
                        }

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
                                var toolInfo = toolCallsMap[idx];
                                if (toolInfo.function.name) {
                                    var toolId = toolInfo.id || 'local_tool_' + idx;
                                    self.showToolProgress(toolInfo.function.name, toolInfo.function.arguments.length, toolId, toolInfo.function.arguments);
                                }
                            });
                        }
                    }
                }

                var toolCalls = [];
                Object.keys(toolCallsMap).forEach(function(idx) {
                    var tc = toolCallsMap[idx];
                    if (tc.function) {
                        var toolId = tc.id || 'tool_' + idx;
                        var parsedArgs = JSON.parse(tc.function.arguments || '{}');
                        toolCalls.push({
                            id: toolId,
                            name: tc.function.name,
                            arguments: parsedArgs
                        });
                        self.updateToolCardDescription(toolId, tc.function.name, parsedArgs);
                    }
                });

                // Strip reasoning tokens from the final response
                var strippedContent = this.stripReasoningTokens(textContent);

                if (!strippedContent) {
                    $reply.remove();
                } else {
                    // Update display with stripped content if different
                    if (strippedContent !== textContent) {
                        this.updateReply($reply, strippedContent);
                    }
                    this.finalizeReply($reply);
                }

                var message = { role: 'assistant', content: strippedContent || null };
                if (Object.keys(toolCallsMap).length > 0) {
                    message.tool_calls = Object.values(toolCallsMap);
                }
                this.messages.push(message);
                this.updateTokenCount();

                // Save conversation before processing tools (in case user reloads while pending)
                this.autoSaveConversation();

                if (toolCalls.length > 0) {
                    this.processToolCalls(toolCalls, 'openai');
                } else {
                    this.setLoading(false);
                }

            } catch (error) {
                if ($reply) $reply.remove();
                this.hideToolProgress();
                this.pendingToolResults = [];
                this.pendingActions = [];
                this.setLoading(false);
                if (error.name !== 'AbortError') {
                    this.addMessage('error', 'Local LLM error: ' + error.message);
                }
            }
        },

        // Summarization API calls
        generateConversationSummary: function(convData) {
            var provider = this.getProvider();
            var model = this.getSummarizationModel() || this.getModel();

            var summaryPrompt = 'Summarize this conversation concisely. Include:\n' +
                '1. Main topics discussed\n' +
                '2. Key decisions or outcomes\n' +
                '3. Files created or modified (if any)\n' +
                '4. Important context for continuing this work later\n\n' +
                'Keep the summary under 500 words. Focus on information that would help someone resume this conversation.\n' +
                'Do NOT include a title or "Conversation Summary" heading - just start with the content.\n\n' +
                'Conversation:\n' + convData.messages_text;

            if (provider === 'anthropic') {
                return this.callAnthropicForSummary(model, summaryPrompt);
            } else if (provider === 'openai') {
                return this.callOpenAIForSummary(model, summaryPrompt);
            } else {
                return this.callLocalForSummary(model, summaryPrompt);
            }
        },

        callAnthropicForSummary: function(model, prompt) {
            var apiKey = this.getApiKey('anthropic');
            return new Promise(function(resolve, reject) {
                fetch('https://api.anthropic.com/v1/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'x-api-key': apiKey,
                        'anthropic-version': '2023-06-01',
                        'anthropic-dangerous-direct-browser-access': 'true'
                    },
                    body: JSON.stringify({
                        model: model,
                        max_tokens: 1024,
                        messages: [{ role: 'user', content: prompt }]
                    })
                }).then(function(response) {
                    return response.json();
                }).then(function(data) {
                    if (data.content && data.content[0] && data.content[0].text) {
                        resolve(data.content[0].text);
                    } else if (data.error) {
                        reject(new Error(data.error.message));
                    } else {
                        reject(new Error('Invalid response from Anthropic'));
                    }
                }).catch(reject);
            });
        },

        callOpenAIForSummary: function(model, prompt) {
            var apiKey = this.getApiKey('openai');
            return new Promise(function(resolve, reject) {
                fetch('https://api.openai.com/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + apiKey
                    },
                    body: JSON.stringify({
                        model: model,
                        max_tokens: 1024,
                        messages: [{ role: 'user', content: prompt }]
                    })
                }).then(function(response) {
                    return response.json();
                }).then(function(data) {
                    if (data.choices && data.choices[0] && data.choices[0].message) {
                        resolve(data.choices[0].message.content);
                    } else if (data.error) {
                        reject(new Error(data.error.message));
                    } else {
                        reject(new Error('Invalid response from OpenAI'));
                    }
                }).catch(reject);
            });
        },

        callLocalForSummary: function(model, prompt) {
            var self = this;
            var endpoint = this.getLocalEndpoint().replace(/\/$/, '');

            return new Promise(function(resolve, reject) {
                fetch(endpoint + '/v1/chat/completions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        model: model,
                        max_tokens: 1024,
                        messages: [{ role: 'user', content: prompt }]
                    })
                }).then(function(response) {
                    return response.json();
                }).then(function(data) {
                    if (data.choices && data.choices[0] && data.choices[0].message) {
                        resolve(self.stripReasoningTokens(data.choices[0].message.content));
                    } else {
                        reject(new Error('Invalid response from local LLM'));
                    }
                }).catch(function() {
                    fetch(endpoint + '/api/generate', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            model: model,
                            prompt: prompt,
                            stream: false
                        })
                    }).then(function(response) {
                        return response.json();
                    }).then(function(data) {
                        if (data.response) {
                            resolve(self.stripReasoningTokens(data.response));
                        } else {
                            reject(new Error('Invalid response from Ollama'));
                        }
                    }).catch(reject);
                });
            });
        }
    });

})(jQuery);
