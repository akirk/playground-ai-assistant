(function($) {
    'use strict';

    var AiChanges = {
        currentFileIds: [],
        previewTimeout: null,

        init: function() {
            this.bindEvents();
            // Show preview panel and auto-preview if there are selections
            this.showPreviewPanel();
            this.autoPreview();
        },

        bindEvents: function() {
            var self = this;

            // Directory toggle
            $(document).on('click', '.ai-changes-toggle, .ai-changes-dir-name', function(e) {
                e.preventDefault();
                var $dir = $(this).closest('.ai-changes-directory');
                var $files = $dir.find('.ai-changes-files');
                var $toggle = $dir.find('.ai-changes-toggle');

                $files.slideToggle(200);
                $toggle.text($files.is(':visible') ? '▼' : '▶');
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
        },

        showPreviewPanel: function() {
            $('#ai-diff-preview').show();
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
        }
    };

    $(document).ready(function() {
        AiChanges.init();
    });

})(jQuery);
