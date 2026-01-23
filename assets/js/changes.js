(function($) {
    'use strict';

    var AiChanges = {
        currentFilePaths: [],
        previewTimeout: null,
        commitOffset: 0,
        commitsLoaded: false,
        hasMoreCommits: false,

        init: function() {
            this.bindEvents();
            this.lintAllPhpFiles();
            this.autoExpandFromUrl();
        },

        autoExpandFromUrl: function() {
            var self = this;
            var params = new URLSearchParams(window.location.search);
            var pluginPath = params.get('plugin');

            if (pluginPath) {
                var $card = $('.ai-plugin-card[data-plugin="' + pluginPath + '"]');
                if ($card.length) {
                    var $content = $card.find('.ai-plugin-content');
                    var $toggle = $card.find('.ai-plugin-toggle');

                    $toggle.text('▼');
                    $content.show();
                    self.lintFilesInCard($card);

                    // Scroll to the card
                    $('html, body').animate({
                        scrollTop: $card.offset().top - 50
                    }, 300);
                }
            }
        },

        bindEvents: function() {
            var self = this;

            // Plugin card toggle
            $(document).on('click', '.ai-plugin-toggle, .ai-plugin-name', function(e) {
                e.preventDefault();
                var $card = $(this).closest('.ai-plugin-card');
                var $content = $card.find('.ai-plugin-content');
                var $toggle = $card.find('.ai-plugin-toggle');
                var willBeVisible = !$content.is(':visible');

                $toggle.text(willBeVisible ? '▼' : '▶');
                $content.slideToggle(200);

                if (willBeVisible) {
                    self.lintFilesInCard($card);
                }
            });

            // Per-file preview toggle
            $(document).on('click', '.ai-file-preview-toggle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleFilePreview($(this));
            });

            // Plugin checkbox - select/deselect all files in plugin
            $(document).on('change', '.ai-plugin-checkbox', function() {
                var checked = $(this).prop('checked');
                var plugin = $(this).data('plugin');
                $('.ai-file-checkbox[data-plugin="' + plugin + '"]').prop('checked', checked);
                self.scheduleAutoPreview();
            });

            // File checkbox - update plugin checkbox state and trigger preview
            $(document).on('change', '.ai-file-checkbox', function() {
                var plugin = $(this).data('plugin');
                var $pluginCheckbox = $('.ai-plugin-checkbox[data-plugin="' + plugin + '"]');
                var $fileCheckboxes = $('.ai-file-checkbox[data-plugin="' + plugin + '"]');
                var allChecked = $fileCheckboxes.length === $fileCheckboxes.filter(':checked').length;
                var anyChecked = $fileCheckboxes.filter(':checked').length > 0;

                $pluginCheckbox.prop('checked', allChecked);
                $pluginCheckbox.prop('indeterminate', !allChecked && anyChecked);
                self.scheduleAutoPreview();
            });

            // Select all
            $('#ai-select-all').on('click', function() {
                $('.ai-plugin-checkbox, .ai-file-checkbox').prop('checked', true);
                $('.ai-plugin-checkbox').prop('indeterminate', false);
                self.scheduleAutoPreview();
            });

            // Clear selection
            $('#ai-clear-selection').on('click', function() {
                $('.ai-plugin-checkbox, .ai-file-checkbox').prop('checked', false);
                $('.ai-plugin-checkbox').prop('indeterminate', false);
                self.scheduleAutoPreview();
            });

            // Download diff
            $('#ai-download-diff').on('click', function() {
                self.downloadDiff();
            });

            // Close preview
            $('#ai-close-preview').on('click', function() {
                self.hidePreviewPanel();
            });

            // Clear history
            $('#ai-clear-history').on('click', function() {
                self.clearHistory();
            });

            // Import patch - trigger file input
            $('#ai-import-patch').on('click', function() {
                $('#ai-patch-file').click();
            });

            // Handle patch file selection
            $('#ai-patch-file').on('change', function() {
                if (this.files && this.files[0]) {
                    self.importPatch(this.files[0]);
                    $(this).val('');
                }
            });

            // Revert file
            $(document).on('click', '.ai-revert-file', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var filePath = $(this).data('path');
                self.revertFile(filePath, $(this));
            });

            // Reapply file
            $(document).on('click', '.ai-reapply-file', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var filePath = $(this).data('path');
                self.reapplyFile(filePath, $(this));
            });

            // Revert plugin
            $(document).on('click', '.ai-revert-plugin', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var plugin = $(this).data('plugin');
                self.revertPlugin(plugin, $(this));
            });

            // Revert to commit
            $(document).on('click', '.ai-revert-to-commit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var sha = $(this).data('sha');
                self.revertToCommit(sha, $(this));
            });

            // Commit diff toggle
            $(document).on('click', '.ai-commit-diff-toggle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleCommitDiff($(this));
            });
        },

        toggleCommitDiff: function($toggle) {
            var self = this;
            var sha = $toggle.data('sha');
            var $preview = $('.ai-commit-diff-preview[data-sha="' + sha + '"]');
            var $code = $preview.find('code');
            var isVisible = $preview.is(':visible');

            if (isVisible) {
                $toggle.text('▶').removeClass('expanded');
                $preview.slideUp(200);
            } else {
                $toggle.text('▼').addClass('expanded');
                $preview.slideDown(200);

                if (!$code.html()) {
                    $code.html('<span class="loading">' + (aiChanges.strings.loading || 'Loading...') + '</span>');
                    $.post(aiChanges.ajaxUrl, {
                        action: 'ai_assistant_get_commit_diff',
                        nonce: aiChanges.nonce,
                        sha: sha
                    }, function(response) {
                        if (response.success) {
                            $code.html(self.highlightDiff(response.data.diff));
                        } else {
                            $code.html('<span class="error">Failed to load diff</span>');
                        }
                    }).fail(function() {
                        $code.html('<span class="error">Failed to load diff</span>');
                    });
                }
            }
        },

        revertToCommit: function(sha, $button) {
            if (!confirm(aiChanges.strings.confirmRevertToCommit || 'Are you sure you want to revert all files to this commit?')) {
                return;
            }

            var originalText = $button.text();
            $button.text(aiChanges.strings.revertingToCommit || 'Reverting...').prop('disabled', true);

            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_revert_to_commit',
                nonce: aiChanges.nonce,
                sha: sha
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || aiChanges.strings.revertToCommitError || 'Failed to revert to commit');
                    $button.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert(aiChanges.strings.revertToCommitError || 'Failed to revert to commit');
                $button.text(originalText).prop('disabled', false);
            });
        },

        showPreviewPanel: function() {
            $('#ai-diff-preview').css('display', 'flex');
        },

        hidePreviewPanel: function() {
            $('#ai-diff-preview').hide();
        },

        getSelectedFilePaths: function() {
            var paths = [];
            $('.ai-file-checkbox:checked').each(function() {
                paths.push($(this).data('path'));
            });
            return paths;
        },

        scheduleAutoPreview: function() {
            var self = this;
            if (this.previewTimeout) {
                clearTimeout(this.previewTimeout);
            }
            this.previewTimeout = setTimeout(function() {
                self.autoPreview();
            }, 300);
        },

        autoPreview: function() {
            var filePaths = this.getSelectedFilePaths();
            var $code = $('#ai-diff-preview').find('code');
            var $download = $('#ai-download-diff');

            if (filePaths.length === 0) {
                $code.html('<span class="ai-diff-preview-empty">Select files above to preview the diff</span>');
                $download.prop('disabled', true);
                this.currentFilePaths = [];
                return;
            }

            // Show panel when files are selected
            this.showPreviewPanel();

            // Check if selection changed
            if (JSON.stringify(filePaths) === JSON.stringify(this.currentFilePaths)) {
                return;
            }

            $code.html('<span class="ai-diff-preview-empty">Generating diff...</span>');
            $download.prop('disabled', true);

            var self = this;
            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_generate_diff',
                nonce: aiChanges.nonce,
                file_paths: filePaths
            }, function(response) {
                if (response.success) {
                    self.currentFilePaths = filePaths;
                    $code.html(self.highlightDiff(response.data.diff));
                    $download.prop('disabled', false);
                } else {
                    $code.html('<span class="ai-diff-preview-empty">Error: ' + (response.data.message || 'Failed to generate diff') + '</span>');
                }
            }).fail(function() {
                $code.html('<span class="ai-diff-preview-empty">Failed to generate diff</span>');
            });
        },

        highlightDiff: function(diff) {
            var mode = wp.CodeMirror.getMode({}, 'diff');
            var container = document.createElement('pre');
            container.className = 'cm-s-default';
            wp.CodeMirror.runMode(diff, mode, container);
            return container.innerHTML;
        },

        toggleFilePreview: function($toggle) {
            var filePath = $toggle.data('path');
            var $preview = $('.ai-file-inline-preview[data-path="' + filePath + '"]');
            var $code = $preview.find('code');
            var isVisible = $preview.is(':visible');

            if (isVisible) {
                $preview.slideUp(150);
                $toggle.text('▶').removeClass('expanded');
            } else {
                // Check if already loaded
                if ($code.html() === '') {
                    $code.html('<span class="loading">Loading...</span>');
                    var self = this;
                    $.post(aiChanges.ajaxUrl, {
                        action: 'ai_assistant_generate_diff',
                        nonce: aiChanges.nonce,
                        file_paths: [filePath]
                    }, function(response) {
                        if (response.success) {
                            $code.html(self.highlightDiff(response.data.diff));
                        } else {
                            $code.html('<span class="loading">Error loading diff</span>');
                        }
                    }).fail(function() {
                        $code.html('<span class="loading">Error loading diff</span>');
                    });
                }
                $preview.slideDown(150);
                $toggle.text('▼').addClass('expanded');
            }
        },

        downloadDiff: function() {
            if (this.currentFilePaths.length === 0) {
                alert(aiChanges.strings.noSelection);
                return;
            }

            var downloadUrl = aiChanges.downloadUrl +
                '&file_paths=' + encodeURIComponent(this.currentFilePaths.join(',')) +
                '&_wpnonce=' + aiChanges.downloadNonce;

            window.location.href = downloadUrl;
        },

        clearHistory: function() {
            if (!confirm(aiChanges.strings.confirmClear)) {
                return;
            }

            var $button = $('#ai-clear-history');
            var originalText = $button.text();
            $button.text(aiChanges.strings.clearing).prop('disabled', true);

            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_clear_changes',
                nonce: aiChanges.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Failed to clear history');
                    $button.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert('Failed to clear history');
                $button.text(originalText).prop('disabled', false);
            });
        },

        importPatch: function(file) {
            var $button = $('#ai-import-patch');
            var originalText = $button.text();
            $button.text(aiChanges.strings.importing).prop('disabled', true);

            var formData = new FormData();
            formData.append('action', 'ai_assistant_apply_patch');
            formData.append('nonce', aiChanges.nonce);
            formData.append('patch_file', file);

            $.ajax({
                url: aiChanges.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        alert(aiChanges.strings.importSuccess.replace('%d', response.data.modified));
                        location.reload();
                    } else {
                        alert(response.data.message || aiChanges.strings.importError);
                        $button.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert(aiChanges.strings.importError);
                    $button.text(originalText).prop('disabled', false);
                }
            });
        },

        revertFile: function(filePath, $button) {
            if (!confirm(aiChanges.strings.confirmRevert)) {
                return;
            }

            var originalText = $button.text();
            $button.text(aiChanges.strings.reverting).prop('disabled', true);

            var self = this;
            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_revert_file',
                nonce: aiChanges.nonce,
                file_path: filePath
            }, function(response) {
                if (response.success) {
                    self.updateFileState(filePath, $button, true);
                } else {
                    alert(response.data.message || aiChanges.strings.revertError);
                    $button.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert(aiChanges.strings.revertError);
                $button.text(originalText).prop('disabled', false);
            });
        },

        reapplyFile: function(filePath, $button) {
            if (!confirm(aiChanges.strings.confirmReapply)) {
                return;
            }

            var originalText = $button.text();
            $button.text(aiChanges.strings.reverting).prop('disabled', true);

            var self = this;
            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_reapply_file',
                nonce: aiChanges.nonce,
                file_path: filePath
            }, function(response) {
                if (response.success) {
                    self.updateFileState(filePath, $button, false);
                } else {
                    alert(response.data.message || aiChanges.strings.reapplyError);
                    $button.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert(aiChanges.strings.reapplyError);
                $button.text(originalText).prop('disabled', false);
            });
        },

        updateFileState: function(filePath, $button, isReverted) {
            var $row = $button.closest('.ai-changes-file-row');
            var $label = $row.find('label');
            var $changeType = $label.find('.ai-changes-type').not('.ai-changes-type-reverted').last();

            if (isReverted) {
                // Add reverted badge after the change type badge
                if ($label.find('.ai-changes-type-reverted').length === 0) {
                    $('<span class="ai-changes-type ai-changes-type-reverted">Reverted</span>').insertAfter($changeType);
                }
                // Replace button with Reapply
                $button
                    .removeClass('ai-revert-file')
                    .addClass('ai-reapply-file')
                    .text(aiChanges.strings.reapply || 'Reapply')
                    .prop('disabled', false)
                    .attr('title', aiChanges.strings.reapplyTitle || 'Reapply this change');
            } else {
                // Remove reverted badge
                $label.find('.ai-changes-type-reverted').remove();
                // Replace button with Revert
                $button
                    .removeClass('ai-reapply-file')
                    .addClass('ai-revert-file')
                    .text(aiChanges.strings.revert || 'Revert')
                    .prop('disabled', false)
                    .attr('title', aiChanges.strings.revertTitle || 'Revert this change');
            }

            // Clear the inline preview cache so it reloads fresh diff next time
            var $preview = $('.ai-file-inline-preview[data-path="' + filePath + '"]');
            $preview.find('code').html('');

            // Re-lint the file since contents changed
            this.lintFile(filePath);
        },

        revertPlugin: function(plugin, $button) {
            var $pluginCard = $('.ai-plugin-card[data-plugin="' + plugin + '"]');
            var $revertButtons = $pluginCard.find('.ai-revert-file');

            if ($revertButtons.length === 0) {
                alert(aiChanges.strings.nothingToRevert || 'No files to revert.');
                return;
            }

            var fileCount = $revertButtons.length;
            var confirmMsg = (aiChanges.strings.confirmRevertPlugin || 'Are you sure you want to revert %d file(s) in this plugin?').replace('%d', fileCount);

            if (!confirm(confirmMsg)) {
                return;
            }

            var filePaths = [];
            $revertButtons.each(function() {
                filePaths.push($(this).data('path'));
            });

            var originalText = $button.text();
            $button.text(aiChanges.strings.reverting).prop('disabled', true);

            var self = this;
            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_revert_files',
                nonce: aiChanges.nonce,
                file_paths: filePaths
            }, function(response) {
                if (response.success) {
                    var reverted = response.data.reverted || [];
                    reverted.forEach(function(filePath) {
                        var $fileButton = $pluginCard.find('.ai-revert-file[data-path="' + filePath + '"]');
                        if ($fileButton.length) {
                            self.updateFileState(filePath, $fileButton, true);
                        }
                    });

                    if (response.data.errors && response.data.errors.length > 0) {
                        alert(response.data.message + '\n\nErrors:\n' + response.data.errors.join('\n'));
                    }
                } else {
                    alert(response.data.message || aiChanges.strings.revertError);
                }
                $button.text(originalText).prop('disabled', false);
            }).fail(function() {
                alert(aiChanges.strings.revertError);
                $button.text(originalText).prop('disabled', false);
            });
        },

        lintFilesInCard: function($card) {
            var self = this;
            $card.find('.ai-lint-status').each(function() {
                var $status = $(this);
                if (!$status.data('linted')) {
                    self.lintFile($status.data('path'));
                }
            });
        },

        lintAllPhpFiles: function() {
            // This is now a no-op since we lint on-demand when cards are expanded
            // Keep the method for compatibility
        },

        lintFile: function(filePath) {
            var self = this;
            var $status = $('.ai-lint-status[data-path="' + filePath + '"]');
            var $pluginCard = $status.closest('.ai-plugin-card');

            $status.data('linted', true);

            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_lint_php',
                nonce: aiChanges.nonce,
                file_path: filePath
            }, function(response) {
                if (!response.success || !response.data.is_php) {
                    return;
                }

                if (response.data.valid) {
                    $status
                        .text(aiChanges.strings.syntaxOk || 'Syntax OK')
                        .removeClass('ai-lint-error')
                        .addClass('ai-lint-ok');
                } else {
                    var errorMsg = response.data.error || 'Syntax error';
                    if (response.data.line) {
                        errorMsg += ' (line ' + response.data.line + ')';
                    }
                    $status
                        .text(aiChanges.strings.syntaxError || 'Syntax Error')
                        .removeClass('ai-lint-ok')
                        .addClass('ai-lint-error')
                        .attr('title', errorMsg);
                }

                self.updatePluginLintStatus($pluginCard);
            });
        },

        updatePluginLintStatus: function($pluginCard) {
            var $header = $pluginCard.find('.ai-plugin-header');
            var $pluginStatus = $header.find('.ai-plugin-lint-status');

            if ($pluginStatus.length === 0) {
                $pluginStatus = $('<span class="ai-plugin-lint-status"></span>');
                $header.find('.ai-plugin-stats').after($pluginStatus);
            }

            var errorCount = $pluginCard.find('.ai-lint-error').length;

            if (errorCount > 0) {
                $pluginStatus
                    .text(errorCount + ' syntax ' + (errorCount === 1 ? 'error' : 'errors'))
                    .addClass('ai-lint-error')
                    .show();
            } else {
                $pluginStatus.removeClass('ai-lint-error').hide();
            }
        }
    };

    $(document).ready(function() {
        AiChanges.init();
    });

})(jQuery);
