(function($) {
    'use strict';

    var AiChanges = {
        currentFileIds: [],
        previewTimeout: null,

        init: function() {
            this.bindEvents();
            this.lintAllPhpFiles();
        },

        bindEvents: function() {
            var self = this;

            // Directory toggle
            $(document).on('click', '.ai-changes-toggle, .ai-changes-dir-name', function(e) {
                e.preventDefault();
                var $dir = $(this).closest('.ai-changes-directory');
                var $files = $dir.find('.ai-changes-files');
                var $toggle = $dir.find('.ai-changes-toggle');
                var willBeVisible = !$files.is(':visible');

                $toggle.text(willBeVisible ? '▼' : '▶');
                $files.slideToggle(200);
            });

            // Per-file preview toggle
            $(document).on('click', '.ai-file-preview-toggle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleFilePreview($(this));
            });

            // Directory checkbox - select/deselect all files in directory
            $(document).on('change', '.ai-dir-checkbox', function() {
                var checked = $(this).prop('checked');
                var dir = $(this).data('dir');
                $('.ai-file-checkbox[data-dir="' + dir + '"]').prop('checked', checked);
                self.scheduleAutoPreview();
            });

            // File checkbox - update directory checkbox state and trigger preview
            $(document).on('change', '.ai-file-checkbox', function() {
                var dir = $(this).data('dir');
                var $dirCheckbox = $('.ai-dir-checkbox[data-dir="' + dir + '"]');
                var $fileCheckboxes = $('.ai-file-checkbox[data-dir="' + dir + '"]');
                var allChecked = $fileCheckboxes.length === $fileCheckboxes.filter(':checked').length;
                var anyChecked = $fileCheckboxes.filter(':checked').length > 0;

                $dirCheckbox.prop('checked', allChecked);
                $dirCheckbox.prop('indeterminate', !allChecked && anyChecked);
                self.scheduleAutoPreview();
            });

            // Select all
            $('#ai-select-all').on('click', function() {
                $('.ai-dir-checkbox, .ai-file-checkbox').prop('checked', true);
                $('.ai-dir-checkbox').prop('indeterminate', false);
                self.scheduleAutoPreview();
            });

            // Clear selection
            $('#ai-clear-selection').on('click', function() {
                $('.ai-dir-checkbox, .ai-file-checkbox').prop('checked', false);
                $('.ai-dir-checkbox').prop('indeterminate', false);
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
                var fileId = $(this).data('id');
                self.revertFile(fileId, $(this));
            });

            // Reapply file
            $(document).on('click', '.ai-reapply-file', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var fileId = $(this).data('id');
                self.reapplyFile(fileId, $(this));
            });

            // Revert directory
            $(document).on('click', '.ai-revert-dir', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var dir = $(this).data('dir');
                self.revertDirectory(dir, $(this));
            });
        },

        showPreviewPanel: function() {
            $('#ai-diff-preview').css('display', 'flex');
        },

        hidePreviewPanel: function() {
            $('#ai-diff-preview').hide();
        },

        getSelectedFileIds: function() {
            var ids = [];
            $('.ai-file-checkbox:checked').each(function() {
                ids.push($(this).data('id'));
            });
            return ids;
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
            var fileIds = this.getSelectedFileIds();
            var $code = $('#ai-diff-preview').find('code');
            var $download = $('#ai-download-diff');

            if (fileIds.length === 0) {
                $code.html('<span class="ai-diff-preview-empty">Select files above to preview the diff</span>');
                $download.prop('disabled', true);
                this.currentFileIds = [];
                return;
            }

            // Show panel when files are selected
            this.showPreviewPanel();

            // Check if selection changed
            if (JSON.stringify(fileIds) === JSON.stringify(this.currentFileIds)) {
                return;
            }

            $code.html('<span class="ai-diff-preview-empty">Generating diff...</span>');
            $download.prop('disabled', true);

            var self = this;
            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_generate_diff',
                nonce: aiChanges.nonce,
                file_ids: fileIds
            }, function(response) {
                if (response.success) {
                    self.currentFileIds = fileIds;
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
            var lines = diff.split('\n');
            var html = [];

            for (var i = 0; i < lines.length; i++) {
                var line = this.escapeHtml(lines[i]);
                var cssClass = '';

                if (line.match(/^diff --git/)) {
                    cssClass = 'diff-header';
                } else if (line.match(/^@@/)) {
                    cssClass = 'diff-hunk';
                } else if (line.match(/^---/) || line.match(/^\+\+\+/)) {
                    cssClass = 'diff-file';
                } else if (line.match(/^\+/)) {
                    cssClass = 'diff-add';
                } else if (line.match(/^-/)) {
                    cssClass = 'diff-del';
                }

                if (cssClass) {
                    html.push('<span class="' + cssClass + '">' + line + '</span>');
                } else {
                    html.push('<span>' + line + '</span>');
                }
            }

            return html.join('\n');
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        toggleFilePreview: function($toggle) {
            var fileId = $toggle.data('id');
            var $preview = $('.ai-file-inline-preview[data-id="' + fileId + '"]');
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
                        file_ids: [fileId]
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
            if (this.currentFileIds.length === 0) {
                alert(aiChanges.strings.noSelection);
                return;
            }

            var downloadUrl = aiChanges.downloadUrl +
                '&file_ids=' + this.currentFileIds.join(',') +
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

        revertFile: function(fileId, $button) {
            if (!confirm(aiChanges.strings.confirmRevert)) {
                return;
            }

            var originalText = $button.text();
            $button.text(aiChanges.strings.reverting).prop('disabled', true);

            var self = this;
            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_revert_file',
                nonce: aiChanges.nonce,
                file_id: fileId
            }, function(response) {
                if (response.success) {
                    self.updateFileState(fileId, $button, true);
                } else {
                    alert(response.data.message || aiChanges.strings.revertError);
                    $button.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert(aiChanges.strings.revertError);
                $button.text(originalText).prop('disabled', false);
            });
        },

        reapplyFile: function(fileId, $button) {
            if (!confirm(aiChanges.strings.confirmReapply)) {
                return;
            }

            var originalText = $button.text();
            $button.text(aiChanges.strings.reverting).prop('disabled', true);

            var self = this;
            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_reapply_file',
                nonce: aiChanges.nonce,
                file_id: fileId
            }, function(response) {
                if (response.success) {
                    self.updateFileState(fileId, $button, false);
                } else {
                    alert(response.data.message || aiChanges.strings.reapplyError);
                    $button.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert(aiChanges.strings.reapplyError);
                $button.text(originalText).prop('disabled', false);
            });
        },

        updateFileState: function(fileId, $button, isReverted) {
            var $row = $button.closest('.ai-changes-file-row');
            var $label = $row.find('label');
            var $dateSpan = $label.find('.ai-changes-date');

            if (isReverted) {
                // Add reverted badge before the date
                $('<span class="ai-changes-type ai-changes-type-reverted">Reverted</span>').insertBefore($dateSpan);
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
            var $preview = $('.ai-file-inline-preview[data-id="' + fileId + '"]');
            $preview.find('code').html('');

            // Re-lint the file since contents changed
            this.lintFile(fileId);
        },

        revertDirectory: function(dir, $button) {
            var $directory = $('.ai-changes-directory[data-dir="' + dir + '"]');
            var $revertButtons = $directory.find('.ai-revert-file');

            if ($revertButtons.length === 0) {
                alert(aiChanges.strings.nothingToRevert || 'No files to revert in this directory.');
                return;
            }

            var fileCount = $revertButtons.length;
            var confirmMsg = (aiChanges.strings.confirmRevertDir || 'Are you sure you want to revert %d file(s) in this directory?').replace('%d', fileCount);

            if (!confirm(confirmMsg)) {
                return;
            }

            var fileIds = [];
            $revertButtons.each(function() {
                fileIds.push($(this).data('id'));
            });

            var originalText = $button.text();
            $button.text(aiChanges.strings.reverting).prop('disabled', true);

            var self = this;
            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_revert_files',
                nonce: aiChanges.nonce,
                file_ids: fileIds
            }, function(response) {
                if (response.success) {
                    var reverted = response.data.reverted || [];
                    reverted.forEach(function(fileId) {
                        var $fileButton = $directory.find('.ai-revert-file[data-id="' + fileId + '"]');
                        if ($fileButton.length) {
                            self.updateFileState(fileId, $fileButton, true);
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

        lintAllPhpFiles: function() {
            var self = this;
            $('.ai-lint-status').each(function() {
                var fileId = $(this).data('id');
                self.lintFile(fileId);
            });
        },

        lintFile: function(fileId) {
            var self = this;
            var $status = $('.ai-lint-status[data-id="' + fileId + '"]');
            var $directory = $status.closest('.ai-changes-directory');

            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_lint_php',
                nonce: aiChanges.nonce,
                file_id: fileId
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

                self.updateDirectoryLintStatus($directory);
            });
        },

        updateDirectoryLintStatus: function($directory) {
            var $header = $directory.find('.ai-changes-directory-header');
            var $dirStatus = $header.find('.ai-dir-lint-status');

            if ($dirStatus.length === 0) {
                $dirStatus = $('<span class="ai-dir-lint-status"></span>');
                $header.find('.ai-changes-count').after($dirStatus);
            }

            var errorCount = $directory.find('.ai-lint-error').length;

            if (errorCount > 0) {
                $dirStatus
                    .text(errorCount + ' syntax ' + (errorCount === 1 ? 'error' : 'errors'))
                    .addClass('ai-lint-error')
                    .show();
            } else {
                $dirStatus.removeClass('ai-lint-error').hide();
            }
        }
    };

    $(document).ready(function() {
        AiChanges.init();
    });

})(jQuery);
