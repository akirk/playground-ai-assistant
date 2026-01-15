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
                } else {
                    this.finalizeReply($reply);
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
                } else {
                    this.finalizeReply($reply);
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
                } else {
                    this.finalizeReply($reply);
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

        // Summarization API calls
        generateConversationSummary: function(convData) {
            var provider = aiAssistantConfig.provider;
            var model = aiAssistantConfig.summarizationModel || aiAssistantConfig.model;

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
            return new Promise(function(resolve, reject) {
                fetch('https://api.anthropic.com/v1/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'x-api-key': aiAssistantConfig.apiKey,
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
            return new Promise(function(resolve, reject) {
                fetch('https://api.openai.com/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + aiAssistantConfig.apiKey
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
            var endpoint = aiAssistantConfig.localEndpoint || 'http://localhost:11434';
            endpoint = endpoint.replace(/\/$/, '');

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
                        resolve(data.choices[0].message.content);
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
                            resolve(data.response);
                        } else {
                            reject(new Error('Invalid response from Ollama'));
                        }
                    }).catch(reject);
                });
            });
        }
    });

})(jQuery);
