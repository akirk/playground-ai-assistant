(function($) {
    'use strict';

    window.aiAssistant = {
        // State
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
        pendingChatOriginalHtml: null,
        consecutiveAjaxErrors: 0,
        ajaxErrorThreshold: 2,
        recoveryMessageShown: false,
        abortController: null,

        init: function() {
            this.setupAjaxErrorTracking();
            this.bindEvents();
            this.buildSystemPrompt();
            this.restoreDraft();
            this.restoreYoloMode();
            this.loadDraftHistory();

            this.conversationProvider = this.getProvider();
            this.conversationModel = this.getModel();
            this.updateSendButton();
            this.updateTokenCount();

            if (typeof aiAssistantPageConfig !== 'undefined') {
                this.isFullPage = aiAssistantPageConfig.isFullPage || false;
                if (this.isFullPage) {
                    this.loadSidebarConversations();
                    $('#ai-assistant-input').focus();
                }
                if (aiAssistantPageConfig.conversationId > 0) {
                    this.loadConversation(aiAssistantPageConfig.conversationId);
                } else {
                    this.loadMostRecentConversation();
                }
            } else {
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

            $(document).on('click', '#ai-assistant-stop', function(e) {
                e.preventDefault();
                self.stopGeneration();
            });

            $(document).on('keydown', '#ai-assistant-input', function(e) {
                if (e.which === 13 && !e.shiftKey && !e.altKey) {
                    e.preventDefault();
                    self.sendMessage();
                } else if (e.which === 38 && !e.shiftKey) {
                    var $input = $(this);
                    if ($input.val() === '' || $input[0].selectionStart === 0) {
                        e.preventDefault();
                        self.navigateDraftHistory(-1);
                    }
                } else if (e.which === 40 && !e.shiftKey) {
                    var $input = $(this);
                    var val = $input.val();
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

            $(document).on('click', '.ai-tool-approve', function(e) {
                e.preventDefault();
                var toolId = $(this).data('tool-id');
                self.confirmAction(toolId, true);
            });

            $(document).on('click', '.ai-tool-skip', function(e) {
                e.preventDefault();
                var toolId = $(this).data('tool-id');
                self.confirmAction(toolId, false);
            });

            $(document).on('keydown', function(e) {
                if (e.which === 27 && self.isOpen) {
                    self.close();
                }
            });

            $(document).on('input', '#ai-assistant-input', function() {
                self.saveDraft();
            });

            $(document).on('mouseenter', '#ai-assistant-link-wrap', function() {
                if (!self.isFullPage && !self.conversationPreloaded) {
                    self.conversationPreloaded = true;
                    self.loadMostRecentConversation();
                }
            });

            $(document).on('change', '#ai-assistant-yolo', function() {
                self.yoloMode = $(this).is(':checked');
                self.saveYoloMode();
                self.addMessage('system', self.yoloMode
                    ? 'YOLO Mode enabled - destructive actions will execute without confirmation.'
                    : 'YOLO Mode disabled - destructive actions will require confirmation.');
            });

            $(document).on('click', '#ai-assistant-expand', function() {
                var $container = $('.ai-assistant-chat-container');
                var isExpanded = $container.toggleClass('expanded').hasClass('expanded');
                $(this).find('.ai-expand-icon').toggle(!isExpanded);
                $(this).find('.ai-collapse-icon').toggle(isExpanded);
                $(this).attr('title', isExpanded ? 'Collapse' : 'Expand');
            });

            $(document).on('click', '#ai-assistant-save-chat', function(e) {
                e.preventDefault();
                self.saveConversation();
            });

            $(document).on('click', '#ai-assistant-summarize', function(e) {
                e.preventDefault();
                self.manualSummarizeConversation();
            });

            $(document).on('click', '.ai-action-copy', function(e) {
                e.preventDefault();
                var $msg = $(this).closest('.ai-message');
                var text = $msg.attr('data-raw-content') || $msg.find('.ai-message-content').text();
                navigator.clipboard.writeText(text).then(function() {
                    var $btn = $(e.currentTarget);
                    $btn.addClass('ai-action-success');
                    setTimeout(function() { $btn.removeClass('ai-action-success'); }, 1500);
                });
            });

            $(document).on('click', '.ai-action-retry', function(e) {
                e.preventDefault();
                if (self.isLoading) return;
                self.retryLastResponse();
            });

            $(document).on('click', '.ai-action-summarize', function(e) {
                e.preventDefault();
                self.manualSummarizeConversation();
            });

            $(document).on('click', '.ai-summary-header', function() {
                $(this).closest('.ai-conversation-summary').toggleClass('collapsed');
            });

            $(document).on('click', '#ai-assistant-load-chat', function(e) {
                e.preventDefault();
                self.showConversationList();
            });

            $(document).on('click', '.ai-modal-close', function() {
                $(this).closest('.ai-modal').hide();
            });

            $(document).on('click', '.ai-modal', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            $(document).on('click', '.ai-conversation-load', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var id = $(this).data('id');
                self.loadConversation(id);
                $('#ai-conversation-modal').hide();
            });

            $(document).on('click', '.ai-conversation-delete, .ai-conv-item-delete', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var id = $(this).data('id');
                if (confirm('Delete this conversation?')) {
                    self.deleteConversation(id);
                }
            });

            $(document).on('click', '.ai-conv-item', function(e) {
                if ($(e.target).hasClass('ai-conv-item-delete')) return;
                if ($(e.target).hasClass('ai-conv-rename-input')) return;
                var $item = $(this);
                var id = $item.data('id');

                if ($item.data('clickTimeout')) {
                    clearTimeout($item.data('clickTimeout'));
                }

                var timeout = setTimeout(function() {
                    self.loadConversation(id);
                }, 250);
                $item.data('clickTimeout', timeout);
            });

            $(document).on('click', '.ai-action-preview-toggle', function(e) {
                e.preventDefault();
                $(this).closest('.ai-action-preview').toggleClass('expanded');
            });

            $(document).on('dblclick', '.ai-conv-item-title', function(e) {
                e.stopPropagation();
                var $title = $(this);
                var $item = $title.closest('.ai-conv-item');
                var id = $item.data('id');
                var currentTitle = $title.text();

                if ($item.data('clickTimeout')) {
                    clearTimeout($item.data('clickTimeout'));
                    $item.removeData('clickTimeout');
                }

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
                    if (e.which === 13) {
                        e.preventDefault();
                        $input.off('blur');
                        saveRename();
                    } else if (e.which === 27) {
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

        isProviderConfigured: function() {
            return this.isConfigured();
        },

        updateSendButton: function() {
            var $btn = $('#ai-assistant-send');
            $btn.prop('disabled', !this.isProviderConfigured());
        },

        setLoading: function(loading) {
            this.isLoading = loading;
            var $loading = $('#ai-assistant-loading');
            var $send = $('#ai-assistant-send');
            var $stop = $('#ai-assistant-stop');
            var $input = $('#ai-assistant-input');

            if (loading) {
                this.abortController = new AbortController();
                $loading.show();
                $send.hide();
                $stop.show();
                $(window).on('beforeunload.aiAssistant', this.beforeUnloadHandler);
            } else {
                this.abortController = null;
                $loading.hide();
                $stop.hide();
                $send.show().prop('disabled', false);
                $input.focus();
                $(window).off('beforeunload.aiAssistant');
            }
        },

        stopGeneration: function() {
            if (this.abortController) {
                this.abortController.abort();
            }
            this.hideToolProgress();
            this.setLoading(false);

            var $streaming = $('#ai-assistant-messages .ai-message-streaming');
            if ($streaming.length) {
                this.finalizeReply($streaming);
            }
        },

        beforeUnloadHandler: function(e) {
            e.preventDefault();
            return e.returnValue = 'AI Assistant is still processing. Are you sure you want to leave?';
        },

        isNearBottom: function(threshold) {
            var $messages = $('#ai-assistant-messages');
            if ($messages.length === 0) return true;

            var scrollTop = $messages.scrollTop();
            var scrollHeight = $messages[0].scrollHeight;
            var clientHeight = $messages[0].clientHeight;
            threshold = threshold || 100;

            return (scrollHeight - scrollTop - clientHeight) < threshold;
        },

        scrollToBottom: function(force) {
            var $messages = $('#ai-assistant-messages');
            if ($messages.length === 0) return;

            // Use larger threshold during streaming so autoscroll re-engages more easily
            var threshold = this.isLoading ? 300 : 100;
            if (force || this.isNearBottom(threshold)) {
                $messages.scrollTop($messages[0].scrollHeight);
            }
        },

        retryLastResponse: function() {
            if (this.messages.length < 2) return;

            var lastAssistantIdx = -1;
            for (var i = this.messages.length - 1; i >= 0; i--) {
                if (this.messages[i].role === 'assistant') {
                    lastAssistantIdx = i;
                    break;
                }
            }

            if (lastAssistantIdx === -1) return;

            this.messages = this.messages.slice(0, lastAssistantIdx);

            var $messages = $('#ai-assistant-messages');
            var $lastAssistant = $messages.find('.ai-message-assistant').last();
            if ($lastAssistant.length) {
                $lastAssistant.remove();
            }

            this.updateSummarizeVisibility();
            this.callLLM();
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        stripReasoningTokens: function(text) {
            if (!text) return text;
            // Strip [THINK]...[/THINK] blocks (Ministral and similar reasoning models)
            var stripped = text.replace(/\[THINK\][\s\S]*?\[\/THINK\]/gi, '');
            // Strip <think>...</think> blocks (DeepSeek and similar)
            stripped = stripped.replace(/<think>[\s\S]*?<\/think>/gi, '');
            // Strip incomplete [THINK]... at the start (truncated response)
            stripped = stripped.replace(/^\s*\[THINK\][\s\S]*$/gi, '');
            // Strip incomplete <think>... at the start (truncated response)
            stripped = stripped.replace(/^\s*<think>[\s\S]*$/gi, '');
            return stripped.trim();
        },

        setupAjaxErrorTracking: function() {
            var self = this;

            $(document).ajaxSuccess(function(event, xhr, settings) {
                if (settings.url && settings.url.indexOf('admin-ajax.php') !== -1) {
                    self.consecutiveAjaxErrors = 0;
                    self.recoveryMessageShown = false;
                }
            });

            $(document).ajaxError(function(event, xhr, settings) {
                if (settings.url && settings.url.indexOf('admin-ajax.php') !== -1) {
                    self.consecutiveAjaxErrors++;

                    if (self.consecutiveAjaxErrors >= self.ajaxErrorThreshold && !self.recoveryMessageShown) {
                        self.showRecoveryMessage();
                    }
                }
            });
        },

        showRecoveryMessage: function() {
            this.recoveryMessageShown = true;
            this.setLoading(false);

            var message = '**WordPress may be broken** due to a recent file change.\n\n' +
                'Multiple requests have failed, which often indicates a PHP syntax error.\n\n' +
                'This page still works because it was already loaded, but navigating to any other WordPress page will likely fail. ' +
                'You can try navigating to confirm, but first remember how to recover:\n\n' +
                'Click the [[GRID_ICON]] grid icon in the top bar and use **Recovery Mode** to restore the last working state.';

            this.addMessage('error', message);

            var gridIcon = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="18" height="18" style="vertical-align: text-bottom; display: inline-block;">' +
                '<path d="M6 5.5h3a.5.5 0 01.5.5v3a.5.5 0 01-.5.5H6a.5.5 0 01-.5-.5V6a.5.5 0 01.5-.5zM4 6a2 2 0 012-2h3a2 2 0 012 2v3a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm11-.5h3a.5.5 0 01.5.5v3a.5.5 0 01-.5.5h-3a.5.5 0 01-.5-.5V6a.5.5 0 01.5-.5zM13 6a2 2 0 012-2h3a2 2 0 012 2v3a2 2 0 01-2 2h-3a2 2 0 01-2-2V6zm5 8.5h-3a.5.5 0 00-.5.5v3a.5.5 0 00.5.5h3a.5.5 0 00.5-.5v-3a.5.5 0 00-.5-.5zM15 13a2 2 0 00-2 2v3a2 2 0 002 2h3a2 2 0 002-2v-3a2 2 0 00-2-2h-3zm-9 1.5h3a.5.5 0 01.5.5v3a.5.5 0 01-.5.5H6a.5.5 0 01-.5-.5v-3a.5.5 0 01.5-.5zM4 15a2 2 0 012-2h3a2 2 0 012 2v3a2 2 0 01-2 2H6a2 2 0 01-2-2v-3z" fill-rule="evenodd" clip-rule="evenodd" fill="currentColor"></path>' +
                '</svg>';

            var $lastError = $('#ai-assistant-messages .ai-message-error').last();
            var html = $lastError.find('.ai-message-content').html();
            $lastError.find('.ai-message-content').html(html.replace('[[GRID_ICON]]', gridIcon));

            $lastError[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    $(document).ready(function() {
        window.aiAssistant.init();
    });

})(jQuery);
