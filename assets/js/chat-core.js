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
        pendingChatOriginalModelInfo: null,

        init: function() {
            this.bindEvents();
            this.buildSystemPrompt();
            this.restoreDraft();
            this.restoreYoloMode();
            this.loadDraftHistory();

            this.conversationProvider = aiAssistantConfig.provider;
            this.conversationModel = aiAssistantConfig.model;
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

            $(document).on('click', '.ai-tool-header', function() {
                $(this).closest('.ai-tool-result').toggleClass('expanded');
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
            var provider = aiAssistantConfig.provider;
            if (provider === 'local') {
                return true;
            }
            return aiAssistantConfig.apiKey && aiAssistantConfig.apiKey.length > 0;
        },

        updateSendButton: function() {
            var $btn = $('#ai-assistant-send');
            $btn.prop('disabled', !this.isProviderConfigured());
        },

        setLoading: function(loading) {
            this.isLoading = loading;
            var $loading = $('#ai-assistant-loading');
            var $send = $('#ai-assistant-send');
            var $input = $('#ai-assistant-input');

            if (loading) {
                $loading.show();
                $send.prop('disabled', true);
                $input.prop('disabled', true);
                $(window).on('beforeunload.aiAssistant', this.beforeUnloadHandler);
            } else {
                $loading.hide();
                $send.prop('disabled', false);
                $input.prop('disabled', false).focus();
                $(window).off('beforeunload.aiAssistant');
            }
        },

        beforeUnloadHandler: function(e) {
            e.preventDefault();
            return e.returnValue = 'AI Assistant is still processing. Are you sure you want to leave?';
        },

        isNearBottom: function() {
            var $messages = $('#ai-assistant-messages');
            if ($messages.length === 0) return true;

            var scrollTop = $messages.scrollTop();
            var scrollHeight = $messages[0].scrollHeight;
            var clientHeight = $messages[0].clientHeight;
            var threshold = 100;

            return (scrollHeight - scrollTop - clientHeight) < threshold;
        },

        scrollToBottom: function(force) {
            var $messages = $('#ai-assistant-messages');
            if ($messages.length === 0) return;

            if (force || this.isNearBottom()) {
                $messages.scrollTop($messages[0].scrollHeight);
            }
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        window.aiAssistant.init();
    });

})(jQuery);
