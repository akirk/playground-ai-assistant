<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Change_Tracker {

    const POST_TYPE = 'ai_file_change';
    const MAX_CONTENT_SIZE = 1048576; // 1MB

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
    }

    public function register_post_type(): void {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('File Changes', 'ai-assistant'),
                'singular_name' => __('File Change', 'ai-assistant'),
            ],
            'public' => false,
            'show_ui' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function track_change(string $path, string $change_type, ?string $original_content = null): int {
        $existing_changes = $this->get_changes_for_path($path);

        if (!empty($existing_changes)) {
            $first_change = end($existing_changes); // Oldest first (ordered DESC, so end is oldest)
            $first_type = get_post_meta($first_change->ID, '_change_type', true);
            $last_change = reset($existing_changes); // Most recent

            // If file was created in this session and now deleted, remove all records
            if ($first_type === 'created' && $change_type === 'deleted') {
                foreach ($existing_changes as $change) {
                    wp_delete_post($change->ID, true);
                }
                return 0;
            }

            // If file was created and now modified, update the first record's type
            // but still store this change as a new patch
            if ($first_type === 'created' && $change_type === 'modified') {
                // Don't create a new record, the "created" already captures the null->content change
                // Just return the existing ID since the current file content IS the change
                return $first_change->ID;
            }
        }

        $is_binary = $original_content !== null && $this->is_binary($original_content);
        $content_truncated = false;
        $stored_content = '';

        if ($original_content !== null) {
            if ($is_binary) {
                $stored_content = '[Binary file]';
            } elseif (strlen($original_content) > self::MAX_CONTENT_SIZE) {
                $stored_content = base64_encode(substr($original_content, 0, self::MAX_CONTENT_SIZE));
                $content_truncated = true;
            } else {
                $stored_content = base64_encode($original_content);
            }
        }

        $post_id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_title' => $path,
            'post_content' => $stored_content,
            'post_status' => 'publish',
        ]);

        if (is_wp_error($post_id)) {
            return 0;
        }

        update_post_meta($post_id, '_change_type', $change_type);
        update_post_meta($post_id, '_is_binary', $is_binary ? '1' : '0');
        update_post_meta($post_id, '_content_truncated', $content_truncated ? '1' : '0');
        update_post_meta($post_id, '_original_size', $original_content !== null ? strlen($original_content) : 0);

        return $post_id;
    }

    public function get_changes_by_directory(): array {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC', // Oldest first for proper patch ordering
        ]);

        // Group patches by file path
        $by_path = [];
        foreach ($posts as $post) {
            $path = $post->post_title;
            if (!isset($by_path[$path])) {
                $by_path[$path] = [];
            }
            $by_path[$path][] = $post;
        }

        $directories = [];

        foreach ($by_path as $path => $file_posts) {
            $parts = explode('/', $path);

            if (count($parts) >= 2) {
                $dir = $parts[0] . '/' . $parts[1];
            } else {
                $dir = $parts[0];
            }

            if (!isset($directories[$dir])) {
                $directories[$dir] = [
                    'path' => $dir,
                    'files' => [],
                    'count' => 0,
                ];
            }

            $first_post = $file_posts[0];
            $first_type = get_post_meta($first_post->ID, '_change_type', true);

            // Collect all patch IDs for this file
            $patch_ids = array_map(function($p) { return $p->ID; }, $file_posts);

            // Determine overall change type based on first and last changes
            $last_post = end($file_posts);
            $last_type = get_post_meta($last_post->ID, '_change_type', true);
            $overall_type = $last_type === 'deleted' ? 'deleted' : $first_type;

            $directories[$dir]['files'][] = [
                'id' => $first_post->ID,
                'ids' => $patch_ids,
                'patch_count' => count($file_posts),
                'path' => $path,
                'relative_path' => substr($path, strlen($dir) + 1),
                'change_type' => $overall_type,
                'is_binary' => get_post_meta($first_post->ID, '_is_binary', true) === '1',
                'is_reverted' => get_post_meta($first_post->ID, '_is_reverted', true) === '1',
                'date' => $first_post->post_date,
                'last_modified' => $last_post->post_date,
            ];
            $directories[$dir]['count']++;
        }

        return $directories;
    }

    public function get_change(int $id): ?array {
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return null;
        }

        $content = $post->post_content;
        $is_binary = get_post_meta($id, '_is_binary', true) === '1';

        if (!$is_binary && $content !== '' && $content !== '[Binary file]') {
            $content = base64_decode($content);
        }

        $reverted_content = get_post_meta($id, '_reverted_content', true);
        if ($reverted_content && !$is_binary) {
            $reverted_content = base64_decode($reverted_content);
        }

        return [
            'id' => $post->ID,
            'path' => $post->post_title,
            'original_content' => $content,
            'reverted_content' => $reverted_content ?: null,
            'change_type' => get_post_meta($id, '_change_type', true),
            'is_binary' => $is_binary,
            'is_reverted' => get_post_meta($id, '_is_reverted', true) === '1',
            'content_truncated' => get_post_meta($id, '_content_truncated', true) === '1',
            'original_size' => (int) get_post_meta($id, '_original_size', true),
            'date' => $post->post_date,
        ];
    }

    public function get_file_patches(string $path): array {
        $posts = $this->get_changes_for_path($path);
        $patches = [];

        // Reverse to get chronological order (oldest first)
        $posts = array_reverse($posts);

        foreach ($posts as $i => $post) {
            $change = $this->get_change($post->ID);
            if (!$change) {
                continue;
            }

            // Determine what content this patch changed TO
            // For intermediate patches, it's the next patch's "before" content
            // For the last patch, it's the current file content
            if (isset($posts[$i + 1])) {
                $next_change = $this->get_change($posts[$i + 1]->ID);
                $after_content = $next_change ? $next_change['original_content'] : '';
            } else {
                $full_path = WP_CONTENT_DIR . '/' . $path;
                $after_content = file_exists($full_path) ? file_get_contents($full_path) : '';
            }

            $patches[] = [
                'id' => $post->ID,
                'before' => $change['original_content'],
                'after' => $after_content,
                'change_type' => $change['change_type'],
                'is_binary' => $change['is_binary'],
                'content_truncated' => $change['content_truncated'],
                'date' => $change['date'],
            ];
        }

        return $patches;
    }

    public function generate_diff(array $file_ids, bool $show_individual_patches = false): string {
        $diff_output = [];

        // Group IDs by file path
        $by_path = [];
        foreach ($file_ids as $id) {
            $change = $this->get_change($id);
            if (!$change) {
                continue;
            }
            $path = $change['path'];
            if (!isset($by_path[$path])) {
                $by_path[$path] = [];
            }
            $by_path[$path][] = $change;
        }

        foreach ($by_path as $path => $changes) {
            $first_change = $changes[0];
            $is_binary = $first_change['is_binary'];

            if ($is_binary) {
                $diff_output[] = "diff --git a/$path b/$path";
                $diff_output[] = "Binary files differ";
                $diff_output[] = "";
                continue;
            }

            if ($show_individual_patches && count($changes) > 1) {
                // Show each patch individually
                $patches = $this->get_file_patches($path);
                foreach ($patches as $i => $patch) {
                    $patch_num = $i + 1;
                    $total = count($patches);
                    $diff_output[] = "# Patch $patch_num/$total for $path ({$patch['date']})";
                    $diff_output[] = $this->generate_unified_diff(
                        $path,
                        $patch['before'],
                        $patch['after'],
                        $patch['change_type']
                    );
                }
            } else {
                // Merged diff: first original → current file
                $original = $first_change['original_content'];
                $change_type = $first_change['change_type'];

                // Check if file was ultimately deleted
                $last_change = end($changes);
                if ($last_change['change_type'] === 'deleted') {
                    $change_type = 'deleted';
                }

                if ($first_change['content_truncated']) {
                    $diff_output[] = "diff --git a/$path b/$path";
                    $diff_output[] = "--- File too large for complete diff (original: {$first_change['original_size']} bytes) ---";
                    $diff_output[] = "";
                    continue;
                }

                $full_path = WP_CONTENT_DIR . '/' . $path;
                $current = file_exists($full_path) ? file_get_contents($full_path) : '';

                $diff_output[] = $this->generate_unified_diff($path, $original, $current, $change_type);
            }
        }

        return implode("\n", $diff_output);
    }

    private function generate_unified_diff(string $path, ?string $original, string $current, string $change_type): string {
        $lines = [];
        $lines[] = "diff --git a/$path b/$path";

        if ($change_type === 'created') {
            $lines[] = "new file mode 100644";
            $lines[] = "--- /dev/null";
            $lines[] = "+++ b/$path";

            $current_lines = explode("\n", $current);
            $lines[] = "@@ -0,0 +1," . count($current_lines) . " @@";
            foreach ($current_lines as $line) {
                $lines[] = '+' . $line;
            }
        } elseif ($change_type === 'deleted') {
            $lines[] = "deleted file mode 100644";
            $lines[] = "--- a/$path";
            $lines[] = "+++ /dev/null";

            $original_lines = $original !== null ? explode("\n", $original) : [];
            $lines[] = "@@ -1," . count($original_lines) . " +0,0 @@";
            foreach ($original_lines as $line) {
                $lines[] = '-' . $line;
            }
        } else {
            $lines[] = "--- a/$path";
            $lines[] = "+++ b/$path";
            $lines[] = $this->compute_diff_hunks($original ?? '', $current);
        }

        $lines[] = "";
        return implode("\n", $lines);
    }

    private function compute_diff_hunks(string $original, string $current): string {
        $original_lines = explode("\n", $original);
        $current_lines = explode("\n", $current);

        $diff = $this->diff_arrays($original_lines, $current_lines);

        $hunks = [];
        $hunk = [];
        $old_line = 0;
        $new_line = 0;
        $hunk_old_start = 0;
        $hunk_new_start = 0;
        $context_before = [];

        foreach ($diff as $item) {
            $type = $item[0];
            $line = $item[1];

            if ($type === '=') {
                $old_line++;
                $new_line++;

                if (!empty($hunk)) {
                    $hunk[] = ' ' . $line;
                    if (count($hunk) >= 3 && end($hunk)[0] === ' ' && prev($hunk)[0] === ' ' && prev($hunk)[0] === ' ') {
                        // End hunk after 3 context lines
                        $hunks[] = $this->format_hunk($hunk_old_start, $old_line - $hunk_old_start, $hunk_new_start, $new_line - $hunk_new_start, $hunk);
                        $hunk = [];
                    }
                } else {
                    $context_before[] = ' ' . $line;
                    if (count($context_before) > 3) {
                        array_shift($context_before);
                    }
                }
            } else {
                if (empty($hunk)) {
                    $hunk_old_start = $old_line - count($context_before) + 1;
                    $hunk_new_start = $new_line - count($context_before) + 1;
                    $hunk = $context_before;
                    $context_before = [];
                }

                if ($type === '-') {
                    $old_line++;
                    $hunk[] = '-' . $line;
                } else {
                    $new_line++;
                    $hunk[] = '+' . $line;
                }
            }
        }

        if (!empty($hunk)) {
            $hunks[] = $this->format_hunk($hunk_old_start, $old_line - $hunk_old_start + 1, $hunk_new_start, $new_line - $hunk_new_start + 1, $hunk);
        }

        return implode("\n", $hunks);
    }

    private function format_hunk(int $old_start, int $old_count, int $new_start, int $new_count, array $lines): string {
        $header = "@@ -{$old_start},{$old_count} +{$new_start},{$new_count} @@";
        return $header . "\n" . implode("\n", $lines);
    }

    private function diff_arrays(array $old, array $new): array {
        $matrix = [];
        $old_len = count($old);
        $new_len = count($new);

        for ($i = 0; $i <= $old_len; $i++) {
            $matrix[$i][0] = 0;
        }
        for ($j = 0; $j <= $new_len; $j++) {
            $matrix[0][$j] = 0;
        }

        for ($i = 1; $i <= $old_len; $i++) {
            for ($j = 1; $j <= $new_len; $j++) {
                if ($old[$i - 1] === $new[$j - 1]) {
                    $matrix[$i][$j] = $matrix[$i - 1][$j - 1] + 1;
                } else {
                    $matrix[$i][$j] = max($matrix[$i - 1][$j], $matrix[$i][$j - 1]);
                }
            }
        }

        $result = [];
        $i = $old_len;
        $j = $new_len;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                array_unshift($result, ['=', $old[$i - 1]]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $matrix[$i][$j - 1] >= $matrix[$i - 1][$j])) {
                array_unshift($result, ['+', $new[$j - 1]]);
                $j--;
            } else {
                array_unshift($result, ['-', $old[$i - 1]]);
                $i--;
            }
        }

        return $result;
    }

    public function clear_changes(array $file_ids = []): int {
        if (empty($file_ids)) {
            $posts = get_posts([
                'post_type' => self::POST_TYPE,
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]);
            $file_ids = $posts;
        }

        $deleted = 0;
        foreach ($file_ids as $id) {
            if (wp_delete_post($id, true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function has_changes(): bool {
        $count = wp_count_posts(self::POST_TYPE);
        return isset($count->publish) && $count->publish > 0;
    }

    public function mark_reverted(int $id, string $content_before_revert): void {
        $is_binary = $this->is_binary($content_before_revert);
        $stored_content = $is_binary ? '[Binary file]' : base64_encode($content_before_revert);

        update_post_meta($id, '_is_reverted', '1');
        update_post_meta($id, '_reverted_content', $stored_content);
    }

    public function mark_reapplied(int $id): void {
        update_post_meta($id, '_is_reverted', '0');
        delete_post_meta($id, '_reverted_content');
    }

    private function is_binary(string $content): bool {
        return strpos($content, "\0") !== false;
    }

    private function get_changes_for_path(string $path): array {
        return get_posts([
            'post_type' => self::POST_TYPE,
            'title' => $path,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }
}
