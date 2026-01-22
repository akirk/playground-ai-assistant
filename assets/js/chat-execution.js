(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        processToolCalls: function(toolCalls, provider) {
            var self = this;
            var destructiveTools = ['write_file', 'edit_file', 'delete_file', 'run_php', 'install_plugin'];
            var alwaysConfirmTools = ['navigate'];

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

            if (executeImmediately.length > 0) {
                this.executeTools(executeImmediately, provider);
            }

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

            if (toolName === 'get_page_html') {
                return this.executeGetPageHtml(toolCall);
            }

            if (toolName === 'summarize_conversation') {
                return this.executeSummarizeConversation(toolCall);
            }

            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_assistant_execute_tool',
                        _wpnonce: aiAssistantConfig.nonce,
                        tool: toolName,
                        arguments: JSON.stringify(toolCall.arguments),
                        conversation_id: self.conversationId || 0
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

                        resolve({
                            id: toolCall.id,
                            name: toolName,
                            input: toolCall.arguments,
                            result: response.success ? response.data : { error: errorMessage },
                            success: response.success
                        });
                    },
                    error: function(xhr, status, errorThrown) {
                        var errorMessage = 'AJAX error: ';

                        if (xhr.responseJSON && xhr.responseJSON.data) {
                            errorMessage += xhr.responseJSON.data.message || JSON.stringify(xhr.responseJSON.data);
                        } else if (xhr.responseText) {
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

        executeSummarizeConversation: function(toolCall) {
            var self = this;
            var args = toolCall.arguments || {};
            var targetConversationId = args.conversation_id || this.conversationId;

            return new Promise(function(resolve) {
                if (!targetConversationId || targetConversationId <= 0) {
                    resolve({
                        id: toolCall.id,
                        name: 'summarize_conversation',
                        input: args,
                        result: { error: 'No conversation to summarize. Save the conversation first.' },
                        success: false
                    });
                    return;
                }

                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_assistant_get_conversation_for_summary',
                        _wpnonce: aiAssistantConfig.nonce,
                        conversation_id: targetConversationId
                    },
                    success: function(response) {
                        if (!response.success) {
                            resolve({
                                id: toolCall.id,
                                name: 'summarize_conversation',
                                input: args,
                                result: { error: response.data?.message || 'Failed to load conversation' },
                                success: false
                            });
                            return;
                        }

                        var convData = response.data;

                        if (convData.existing_summary) {
                            resolve({
                                id: toolCall.id,
                                name: 'summarize_conversation',
                                input: args,
                                result: {
                                    conversation_id: targetConversationId,
                                    title: convData.title,
                                    summary: convData.existing_summary,
                                    message: 'Existing summary retrieved'
                                },
                                success: true
                            });
                            return;
                        }

                        self.generateConversationSummary(convData).then(function(summary) {
                            $.ajax({
                                url: aiAssistantConfig.ajaxUrl,
                                type: 'POST',
                                data: {
                                    action: 'ai_assistant_save_summary',
                                    _wpnonce: aiAssistantConfig.nonce,
                                    conversation_id: targetConversationId,
                                    summary: summary
                                },
                                success: function() {
                                    resolve({
                                        id: toolCall.id,
                                        name: 'summarize_conversation',
                                        input: args,
                                        result: {
                                            conversation_id: targetConversationId,
                                            title: convData.title,
                                            summary: summary,
                                            message: 'Summary generated and saved'
                                        },
                                        success: true
                                    });
                                },
                                error: function() {
                                    resolve({
                                        id: toolCall.id,
                                        name: 'summarize_conversation',
                                        input: args,
                                        result: {
                                            conversation_id: targetConversationId,
                                            summary: summary,
                                            message: 'Summary generated but failed to save'
                                        },
                                        success: true
                                    });
                                }
                            });
                        }).catch(function(error) {
                            resolve({
                                id: toolCall.id,
                                name: 'summarize_conversation',
                                input: args,
                                result: { error: 'Failed to generate summary: ' + error.message },
                                success: false
                            });
                        });
                    },
                    error: function() {
                        resolve({
                            id: toolCall.id,
                            name: 'summarize_conversation',
                            input: args,
                            result: { error: 'Failed to load conversation data' },
                            success: false
                        });
                    }
                });
            });
        },

        handleToolResults: function(results, provider) {
            var self = this;

            var navigateResult = results.find(function(r) {
                return r.name === 'navigate' && r.success && r.result && r.result.url;
            });

            this.deduplicateFileReads(results);
            this.showToolResults(results);

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
                results.forEach(function(r) {
                    self.messages.push({
                        role: 'tool',
                        tool_call_id: r.id,
                        content: JSON.stringify(r.result)
                    });
                });
            }

            this.updateTokenCount();

            if (navigateResult) {
                var targetUrl = navigateResult.result.url;
                this.addMessage('system', 'Navigating to: ' + targetUrl);
                this.setLoading(false);
                this.saveConversationThenNavigate(targetUrl);
                return;
            }

            this.autoSaveConversation();
            this.callLLM();
        },

        confirmAction: function(actionId, confirmed) {
            var self = this;
            var action = this.pendingActions.find(function(a) {
                return a.id === actionId;
            });

            if (!action) return;

            this.pendingActions = this.pendingActions.filter(function(a) {
                return a.id !== actionId;
            });
            $('[data-action-id="' + actionId + '"]').remove();

            if (this.pendingActions.length === 0) {
                $('#ai-assistant-pending-actions').hide();
            }

            if (confirmed) {
                this.executeSingleTool(action).then(function(result) {
                    self.handleToolResults([result], action.provider);
                });
            } else {
                this.addMessage('system', 'Skipped: ' + action.description);
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
                case 'db_query':
                    var sql = (args.sql || '').substring(0, 40);
                    return 'Query: ' + sql + (args.sql && args.sql.length > 40 ? '...' : '');
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

            var html;
            if (isEdit) {
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

            var prefixCount = 0;
            while (prefixCount < searchLines.length &&
                   prefixCount < replaceLines.length &&
                   searchLines[prefixCount] === replaceLines[prefixCount]) {
                prefixCount++;
            }

            var suffixCount = 0;
            while (suffixCount < (searchLines.length - prefixCount) &&
                   suffixCount < (replaceLines.length - prefixCount) &&
                   searchLines[searchLines.length - 1 - suffixCount] === replaceLines[replaceLines.length - 1 - suffixCount]) {
                suffixCount++;
            }

            var contextBefore = Math.min(prefixCount, 2);
            var prefixStart = prefixCount - contextBefore;

            if (prefixStart > 0) {
                result.push('  ... (' + prefixStart + ' unchanged lines)');
            }

            for (var i = prefixStart; i < prefixCount; i++) {
                result.push('  ' + searchLines[i]);
            }

            var searchMiddleEnd = searchLines.length - suffixCount;
            for (var i = prefixCount; i < searchMiddleEnd; i++) {
                result.push('- ' + searchLines[i]);
            }

            var replaceMiddleEnd = replaceLines.length - suffixCount;
            for (var i = prefixCount; i < replaceMiddleEnd; i++) {
                result.push('+ ' + replaceLines[i]);
            }

            var contextAfter = Math.min(suffixCount, 2);
            var suffixStart = searchLines.length - suffixCount;

            for (var i = suffixStart; i < suffixStart + contextAfter; i++) {
                result.push('  ' + searchLines[i]);
            }

            if (suffixCount > contextAfter) {
                result.push('  ... (' + (suffixCount - contextAfter) + ' unchanged lines)');
            }

            return result;
        }
    });

})(jQuery);
