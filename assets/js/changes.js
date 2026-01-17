(function($) {
    'use strict';

    var AiChanges = {
        currentFilePaths: [],
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

            // Revert directory
            $(document).on('click', '.ai-revert-dir', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var dir = $(this).data('dir');
                self.revertDirectory(dir, $(this));
            });

            // Commit log toggle
            $(document).on('click', '.ai-commit-log-header', function(e) {
                e.preventDefault();
                var $list = $(this).siblings('.ai-commit-log-list');
                var $toggle = $(this).find('.ai-commit-log-toggle');
                var willBeVisible = !$list.is(':visible');

                $toggle.text(willBeVisible ? '▼' : '▶');
                $list.slideToggle(200);
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

                // Load diff if not already loaded
                if (!$code.html()) {
                    $code.html('<span class="loading">Loading...</span>');
                    $.get(aiChanges.ajaxUrl, {
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
            var $lintStatus = $label.find('.ai-lint-status');

            if (isReverted) {
                // Add reverted badge before the lint status
                if ($label.find('.ai-changes-type-reverted').length === 0) {
                    $('<span class="ai-changes-type ai-changes-type-reverted">Reverted</span>').insertBefore($lintStatus);
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
                        var $fileButton = $directory.find('.ai-revert-file[data-path="' + filePath + '"]');
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

        revertToCommit: function(sha, $button) {
            if (!confirm(aiChanges.strings.confirmRevertToCommit)) {
                return;
            }

            var self = this;
            var originalText = $button.text();
            $button.text(aiChanges.strings.revertingToCommit).prop('disabled', true);

            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_revert_to_commit',
                nonce: aiChanges.nonce,
                sha: sha
            }, function(response) {
                if (response.success) {
                    // Reload the page to show updated state
                    location.reload();
                } else {
                    alert(response.data.message || aiChanges.strings.revertToCommitError);
                    $button.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert(aiChanges.strings.revertToCommitError);
                $button.text(originalText).prop('disabled', false);
            });
        },

        lintAllPhpFiles: function() {
            var self = this;
            $('.ai-lint-status').each(function() {
                var filePath = $(this).data('path');
                self.lintFile(filePath);
            });
        },

        lintFile: function(filePath) {
            var self = this;
            var $status = $('.ai-lint-status[data-path="' + filePath + '"]');
            var $directory = $status.closest('.ai-changes-directory');

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
