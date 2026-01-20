(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        // Draft management
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

            if (this.draftHistory.length > 0 && this.draftHistory[0] === message) {
                return;
            }

            this.draftHistory.unshift(message);

            if (this.draftHistory.length > this.draftHistoryMax) {
                this.draftHistory = this.draftHistory.slice(0, this.draftHistoryMax);
            }

            this.saveDraftHistory();
        },

        navigateDraftHistory: function(direction) {
            if (this.draftHistory.length === 0) return;

            var newIndex = this.draftHistoryIndex + direction;

            if (newIndex < -1) newIndex = -1;
            if (newIndex >= this.draftHistory.length) newIndex = this.draftHistory.length - 1;

            if (newIndex === this.draftHistoryIndex) return;

            this.draftHistoryIndex = newIndex;

            var $input = $('#ai-assistant-input');
            if (newIndex === -1) {
                $input.val('');
            } else {
                $input.val(this.draftHistory[newIndex]);
            }

            var input = $input[0];
            input.selectionStart = input.selectionEnd = input.value.length;
        },

        // New chat
        newChat: function() {
            var self = this;

            if (this.isFullPage) {
                this.startNewChat();
                return;
            }

            if (this.messages.length > 0 && !this.pendingNewChat) {
                this.pendingNewChat = true;
                $('#ai-assistant-messages').addClass('ai-pending-new-chat');
                $('#ai-token-count').hide();
                $('#ai-assistant-new-chat').text('Undo').attr('id', 'ai-assistant-undo-new-chat');

                var $modelInfo = $('.ai-model-info');
                this.pendingChatOriginalModelInfo = $modelInfo.html();

                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_assistant_get_current_settings',
                        _wpnonce: aiAssistantConfig.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            aiAssistantConfig.provider = response.data.provider;
                            aiAssistantConfig.model = response.data.model;
                            var providerName = response.data.provider === 'anthropic' ? 'Anthropic' :
                                               response.data.provider === 'openai' ? 'OpenAI' :
                                               response.data.provider === 'local' ? 'Local LLM' : response.data.provider;
                            var modelInfo = response.data.model ? ' (' + response.data.model + ')' : '';
                            $modelInfo.find('.ai-message-content').html("You're chatting with <strong>" + providerName + '</strong>' + modelInfo);
                        }
                    }
                });

                $('#ai-assistant-input').focus();
                return;
            }

            this.startNewChat();
        },

        startNewChat: function() {
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
            this.updateSummarizeButton();
            $('#ai-assistant-input').focus();
        },

        undoNewChat: function() {
            this.pendingNewChat = false;
            $('#ai-assistant-messages').removeClass('ai-pending-new-chat');
            $('#ai-token-count').show();
            $('#ai-assistant-undo-new-chat').text('New Chat').attr('id', 'ai-assistant-new-chat');

            if (this.pendingChatOriginalModelInfo) {
                $('.ai-model-info').html(this.pendingChatOriginalModelInfo);
                this.pendingChatOriginalModelInfo = null;
            }

            this.scrollToBottom();
            $('#ai-assistant-input').focus();
        },

        // Conversation persistence
        saveConversation: function(silent) {
            var self = this;

            if (this.messages.length === 0) {
                if (!silent) {
                    this.addMessage('system', 'No messages to save.');
                }
                return;
            }

            var messagesToSave = this.messages.filter(function(m) { return !m._internal; });

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_save_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: this.conversationId,
                    messages: btoa(unescape(encodeURIComponent(JSON.stringify(messagesToSave)))),
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

                        if (isNew && self.isFullPage) {
                            self.loadSidebarConversations();
                        }

                        self.updateSummarizeButton();
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

        autoSaveConversation: function() {
            if (this.autoSave && this.messages.length > 0) {
                if (!this.conversationTitle && this.messages.length >= 2) {
                    this.generateConversationTitle();
                    return;
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

            var messagesToSave = this.messages.filter(function(m) { return !m._internal; });

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_save_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: this.conversationId,
                    messages: btoa(unescape(encodeURIComponent(JSON.stringify(messagesToSave)))),
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

                        $('#ai-assistant-messages').empty();

                        var convProvider = response.data.provider || aiAssistantConfig.provider;
                        var convModel = response.data.model || aiAssistantConfig.model;
                        self.conversationProvider = convProvider;
                        self.conversationModel = convModel;
                        self.updateSendButton();
                        self.updateTokenCount();

                        self.loadWelcomeMessage(convProvider, convModel);
                        if (response.data.summary) {
                            self.showConversationSummary(response.data.summary);
                        }
                        self.rebuildMessagesUI();
                        self.updateSidebarSelection();
                        $('#ai-assistant-input').focus();

                        self.autoReadConversationFiles();
                        self.updateSummarizeButton();

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
                }
            });
        },

        autoReadConversationFiles: function() {
            var self = this;
            var filePaths = new Set();
            var readFileToolIds = new Set();

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
                    if (Array.isArray(msg.content) && msg.content.length === 0) {
                        return false;
                    }
                    return true;
                });
            }

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
                    var contextMsg = 'Resuming conversation. Current state of previously accessed files:\n\n';
                    fileContents.forEach(function(file) {
                        var content = file.content.content || '';
                        if (content.length > 5000) {
                            content = content.substring(0, 5000) + '\n... (truncated, ' + (file.content.size - 5000) + ' more bytes)';
                        }
                        contextMsg += '=== ' + file.path + ' ===\n' + content + '\n\n';
                    });

                    self.messages.push({ role: 'user', content: contextMsg, _internal: true });
                    self.addMessage('system', 'Loaded ' + fileContents.length + ' file(s) from previous session for context.');
                }
            });
        },

        // Sidebar management
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
            $('.ai-conv-item').removeClass('active');
            if (this.conversationId > 0) {
                $('.ai-conv-item[data-id="' + this.conversationId + '"]').addClass('active');
            }
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
                        $('.ai-conv-item[data-id="' + conversationId + '"]').remove();
                        $('.ai-conversation-item [data-id="' + conversationId + '"]').closest('.ai-conversation-item').remove();

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

        // Title generation
        generateConversationTitle: function() {
            var self = this;
            var config = aiAssistantConfig;

            var firstUserMsg = this.messages.find(function(m) { return m.role === 'user'; });
            if (!firstUserMsg) return;

            var userContent = typeof firstUserMsg.content === 'string'
                ? firstUserMsg.content
                : (firstUserMsg.content[0]?.text || '');

            if (!userContent) return;

            var titlePrompt = 'Generate a very short title (3-6 words max) for a conversation that starts with this message. Return ONLY the title, nothing else. Do not explain or reason - just output the title directly.\n\n' + userContent.substring(0, 500);

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
                        max_tokens: 150,
                        messages: [{ role: 'user', content: titlePrompt }]
                    })
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.choices && data.choices[0] && data.choices[0].message) {
                        var title = self.stripReasoningTokens(data.choices[0].message.content);
                        self.conversationTitle = title.trim().replace(/^["']|["']$/g, '');
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
        },

        // Summarization
        manualSummarizeConversation: function() {
            var self = this;

            if (!this.conversationId || this.conversationId <= 0) {
                this.addMessage('system', 'Please save the conversation first before generating a summary.');
                return;
            }

            if (this.isLoading) {
                return;
            }

            var $btn = $('#ai-assistant-summarize');
            $btn.prop('disabled', true).addClass('loading');
            this.addMessage('system', 'Generating conversation summary...');
            var $generatingMsg = $('#ai-assistant-messages .ai-message-system').last();

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_get_conversation_for_summary',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: this.conversationId
                },
                success: function(response) {
                    if (!response.success) {
                        self.addMessage('error', 'Failed to load conversation: ' + (response.data?.message || 'Unknown error'));
                        $btn.prop('disabled', false).removeClass('loading');
                        return;
                    }

                    var convData = response.data;

                    if (convData.existing_summary) {
                        $generatingMsg.remove();
                        var $existing = $('#ai-assistant-messages .ai-conversation-summary');
                        if ($existing.length) {
                            $existing.removeClass('collapsed');
                            $existing[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                        } else {
                            self.showConversationSummary(convData.existing_summary);
                            self.scrollToBottom();
                        }
                        $btn.prop('disabled', false).removeClass('loading');
                        return;
                    }

                    self.generateConversationSummary(convData).then(function(summary) {
                        $generatingMsg.remove();
                        $.ajax({
                            url: aiAssistantConfig.ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'ai_assistant_save_summary',
                                _wpnonce: aiAssistantConfig.nonce,
                                conversation_id: self.conversationId,
                                summary: summary
                            },
                            success: function() {
                                self.addMessage('system', 'Summary generated and saved to post excerpt:\n\n' + summary);
                            },
                            error: function() {
                                self.addMessage('system', 'Summary generated (but failed to save):\n\n' + summary);
                            },
                            complete: function() {
                                $btn.prop('disabled', false).removeClass('loading');
                            }
                        });
                    }).catch(function(error) {
                        $generatingMsg.remove();
                        self.addMessage('error', 'Failed to generate summary: ' + error.message);
                        $btn.prop('disabled', false).removeClass('loading');
                    });
                },
                error: function() {
                    $generatingMsg.remove();
                    self.addMessage('error', 'Failed to load conversation data');
                    $btn.prop('disabled', false).removeClass('loading');
                }
            });
        },

        showConversationSummary: function(summary) {
            var $messages = $('#ai-assistant-messages');
            var html = '<div class="ai-conversation-summary">' +
                '<div class="ai-summary-header">' +
                '<span class="ai-summary-icon">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>' +
                '</span>' +
                '<span class="ai-summary-title">Conversation Summary</span>' +
                '<span class="ai-summary-toggle">&#9660;</span>' +
                '</div>' +
                '<div class="ai-summary-content">' + this.formatContent(summary) + '</div>' +
                '</div>';
            $messages.append(html);
        },

        updateSummarizeButton: function() {
            this.updateSummarizeVisibility();
        }
    });

})(jQuery);
