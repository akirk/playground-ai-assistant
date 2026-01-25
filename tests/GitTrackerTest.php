<?php
namespace AI_Assistant\Tests;

use PHPUnit\Framework\TestCase;
use AI_Assistant\Git_Tracker;

/**
 * Unit tests for the Git_Tracker class
 */
class GitTrackerTest extends TestCase {

    private Git_Tracker $tracker;
    private string $plugin_dir;
    private string $git_dir;

    protected function setUp(): void {
        $this->plugin_dir = WP_PLUGIN_DIR . '/test-plugin';
        $this->git_dir = $this->plugin_dir . '/.git';

        // Create test plugin directory
        if (!is_dir($this->plugin_dir)) {
            mkdir($this->plugin_dir, 0755, true);
        }

        $this->tracker = new Git_Tracker($this->plugin_dir);

        // Clean up any existing .git from previous tests
        $this->recursiveDelete($this->git_dir);
    }

    protected function tearDown(): void {
        // Clean up after each test
        $this->recursiveDelete($this->plugin_dir);
    }

    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Basic tracking tests
    // -------------------------------------------------------------------------

    public function test_is_active_returns_false_initially(): void {
        $this->assertFalse($this->tracker->is_active());
    }

    public function test_is_active_returns_true_after_tracking(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', 'original content', 'Test change');

        $this->assertTrue($this->tracker->is_active());
    }

    public function test_track_change_creates_git_structure(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', 'original content', 'Test change');

        $this->assertDirectoryExists($this->git_dir);
        $this->assertDirectoryExists($this->git_dir . '/objects');
        $this->assertDirectoryExists($this->git_dir . '/refs/heads');
        $this->assertFileExists($this->git_dir . '/HEAD');
        $this->assertFileExists($this->git_dir . '/config');
        $this->assertFileExists($this->git_dir . '/index');
    }

    public function test_track_change_for_created_file(): void {
        $test_file = $this->plugin_dir . '/new-file.txt';
        file_put_contents($test_file, 'new content');

        $result = $this->tracker->track_change('new-file.txt', 'created', null, 'Created new file');

        $this->assertTrue($result);
        $this->assertTrue($this->tracker->is_tracked('new-file.txt'));
    }

    public function test_track_change_for_modified_file(): void {
        $test_file = $this->plugin_dir . '/modified.txt';
        file_put_contents($test_file, 'modified content');

        $result = $this->tracker->track_change('modified.txt', 'modified', 'original content', 'Modified file');

        $this->assertTrue($result);
        $this->assertTrue($this->tracker->is_tracked('modified.txt'));
    }

    public function test_get_original_content(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        $original = 'original content here';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', $original, 'Test change');

        $this->assertEquals($original, $this->tracker->get_original_content('test.txt'));
    }

    // -------------------------------------------------------------------------
    // HEAD and branch tests
    // -------------------------------------------------------------------------

    public function test_head_points_to_ai_changes_after_tracking(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', 'original content', 'Test change');

        $head = file_get_contents($this->git_dir . '/HEAD');
        $this->assertEquals("ref: refs/heads/ai-changes\n", $head);
    }

    public function test_both_branches_exist_after_tracking(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', 'original content', 'Test change');

        $this->assertFileExists($this->git_dir . '/refs/heads/main');
        $this->assertFileExists($this->git_dir . '/refs/heads/ai-changes');
    }

    // -------------------------------------------------------------------------
    // Diff generation tests
    // -------------------------------------------------------------------------

    public function test_generate_diff_for_modified_file(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, "line1\nline2\nmodified");

        $this->tracker->track_change('test.txt', 'modified', "line1\nline2\noriginal", 'Test change');

        $diff = $this->tracker->generate_diff();

        $this->assertStringContainsString('diff --git', $diff);
        $this->assertStringContainsString('-original', $diff);
        $this->assertStringContainsString('+modified', $diff);
    }

    public function test_generate_diff_for_created_file(): void {
        $test_file = $this->plugin_dir . '/new.txt';
        file_put_contents($test_file, "new content");

        $this->tracker->track_change('new.txt', 'created', null, 'Created file');

        $diff = $this->tracker->generate_diff();

        $this->assertStringContainsString('new file mode', $diff);
        $this->assertStringContainsString('+new content', $diff);
    }

    // -------------------------------------------------------------------------
    // Revert and reapply tests
    // -------------------------------------------------------------------------

    public function test_revert_modified_file(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        $original = 'original content';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', $original, 'Test change');
        $result = $this->tracker->revert_file('test.txt');

        $this->assertTrue($result);
        $this->assertEquals($original, file_get_contents($test_file));
    }

    public function test_revert_created_file(): void {
        $test_file = $this->plugin_dir . '/new.txt';
        file_put_contents($test_file, 'new content');

        $this->tracker->track_change('new.txt', 'created', null, 'Created file');
        $result = $this->tracker->revert_file('new.txt');

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($test_file);
    }

    public function test_is_reverted_for_modified_file(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        $original = 'original content';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', $original, 'Test change');

        $this->assertFalse($this->tracker->is_reverted('test.txt'));

        $this->tracker->revert_file('test.txt');

        $this->assertTrue($this->tracker->is_reverted('test.txt'));
    }

    public function test_reapply_file(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        $original = 'original content';
        $modified = 'modified content';
        file_put_contents($test_file, $modified);

        $this->tracker->track_change('test.txt', 'modified', $original, 'Test change');
        $this->tracker->revert_file('test.txt');

        $this->assertEquals($original, file_get_contents($test_file));

        $result = $this->tracker->reapply_file('test.txt');

        $this->assertTrue($result);
        $this->assertEquals($modified, file_get_contents($test_file));
    }

    // -------------------------------------------------------------------------
    // Changes info tests
    // -------------------------------------------------------------------------

    public function test_get_changes_info(): void {
        $test_file = $this->plugin_dir . '/test.php';
        file_put_contents($test_file, 'modified');

        $this->tracker->track_change('test.php', 'modified', 'original', 'Test');

        $info = $this->tracker->get_changes_info();

        $this->assertArrayHasKey('files', $info);
        $this->assertArrayHasKey('file_count', $info);
        $this->assertArrayHasKey('commits', $info);
        $this->assertArrayHasKey('name', $info);
        $this->assertEquals(1, $info['file_count']);
    }

    public function test_get_changes_by_directory(): void {
        mkdir($this->plugin_dir . '/includes', 0755, true);
        $test_file = $this->plugin_dir . '/includes/test.php';
        file_put_contents($test_file, 'modified');

        $this->tracker->track_change('includes/test.php', 'modified', 'original', 'Test');

        $changes = $this->tracker->get_changes_by_directory();

        $this->assertArrayHasKey('includes', $changes);
        $this->assertEquals(1, $changes['includes']['count']);
    }

    // -------------------------------------------------------------------------
    // build_standalone_git tests
    // -------------------------------------------------------------------------

    public function test_build_standalone_git_creates_structure(): void {
        $test_file = $this->plugin_dir . '/plugin.php';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('plugin.php', 'modified', 'original content', 'Test change');

        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $result = $this->tracker->build_standalone_git($target_dir);

        $this->assertTrue($result);
        $this->assertDirectoryExists($target_dir . '/.git');
        $this->assertDirectoryExists($target_dir . '/.git/objects');
        $this->assertFileExists($target_dir . '/.git/HEAD');
        $this->assertFileExists($target_dir . '/.git/refs/heads/main');
        $this->assertFileExists($target_dir . '/.git/refs/heads/ai-changes');

        $this->recursiveDelete($target_dir);
    }

    public function test_build_standalone_git_head_points_to_ai_changes(): void {
        $test_file = $this->plugin_dir . '/plugin.php';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('plugin.php', 'modified', 'original content', 'Test change');

        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $this->tracker->build_standalone_git($target_dir);

        $head = file_get_contents($target_dir . '/.git/HEAD');
        $this->assertEquals("ref: refs/heads/ai-changes\n", $head);

        $this->recursiveDelete($target_dir);
    }

    public function test_build_standalone_git_filemode_is_false(): void {
        $test_file = $this->plugin_dir . '/plugin.php';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('plugin.php', 'modified', 'original content', 'Test change');

        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $this->tracker->build_standalone_git($target_dir);

        $config = file_get_contents($target_dir . '/.git/config');
        $this->assertStringContainsString('filemode = false', $config);

        $this->recursiveDelete($target_dir);
    }

    public function test_build_standalone_git_returns_false_for_no_changes(): void {
        // No changes tracked
        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $result = $this->tracker->build_standalone_git($target_dir);

        $this->assertFalse($result);

        $this->recursiveDelete($target_dir);
    }

    public function test_build_standalone_git_for_created_file(): void {
        $test_file = $this->plugin_dir . '/new-plugin.php';
        file_put_contents($test_file, '<?php // New plugin');

        $this->tracker->track_change('new-plugin.php', 'created', null, 'Created plugin');

        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $result = $this->tracker->build_standalone_git($target_dir);

        $this->assertTrue($result);
        $this->assertFileExists($target_dir . '/.git/refs/heads/main');
        $this->assertFileExists($target_dir . '/.git/refs/heads/ai-changes');

        $this->recursiveDelete($target_dir);
    }

    // -------------------------------------------------------------------------
    // Clear tests
    // -------------------------------------------------------------------------

    public function test_clear_all(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', 'original content', 'Test change');

        $this->assertTrue($this->tracker->is_active());

        $count = $this->tracker->clear_all();

        $this->assertGreaterThan(0, $count);
        $this->assertFalse($this->tracker->is_active());
    }

    public function test_has_changes(): void {
        $this->assertFalse($this->tracker->has_changes());

        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', 'original content', 'Test change');

        $this->assertTrue($this->tracker->has_changes());
    }

    // -------------------------------------------------------------------------
    // get_recent_commits tests
    // -------------------------------------------------------------------------

    public function test_get_recent_commits_returns_empty_when_inactive(): void {
        $result = $this->tracker->get_recent_commits();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_recent_commits_returns_commits(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change('test.txt', 'modified', 'original content', 'Test commit message');

        $result = $this->tracker->get_recent_commits();

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('sha', $result[0]);
        $this->assertArrayHasKey('short_sha', $result[0]);
        $this->assertArrayHasKey('message', $result[0]);
        $this->assertArrayHasKey('timestamp', $result[0]);
        $this->assertEquals('Test commit message', $result[0]['message']);
    }

    public function test_get_recent_commits_returns_multiple_commits(): void {
        $test_file = $this->plugin_dir . '/test.txt';

        file_put_contents($test_file, 'version 1');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'First commit');

        file_put_contents($test_file, 'version 2');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'Second commit');

        file_put_contents($test_file, 'version 3');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'Third commit');

        $result = $this->tracker->get_recent_commits();

        $this->assertGreaterThanOrEqual(3, count($result));
        $this->assertEquals('Third commit', $result[0]['message']);
        $this->assertEquals('Second commit', $result[1]['message']);
        $this->assertEquals('First commit', $result[2]['message']);
    }

    public function test_get_recent_commits_respects_limit(): void {
        $test_file = $this->plugin_dir . '/test.txt';

        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($test_file, "version $i");
            $this->tracker->track_change('test.txt', 'modified', 'original', "Commit $i");
        }

        $result = $this->tracker->get_recent_commits(2);

        $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // get_commit_diff tests
    // -------------------------------------------------------------------------

    public function test_get_commit_diff_returns_empty_when_inactive(): void {
        $diff = $this->tracker->get_commit_diff('invalid-sha');

        $this->assertEquals('', $diff);
    }

    public function test_get_commit_diff_returns_diff_for_valid_commit(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, "line1\nline2\nmodified");

        $this->tracker->track_change('test.txt', 'modified', "line1\nline2\noriginal", 'Test change');

        $commits = $this->tracker->get_recent_commits();
        $sha = $commits[0]['sha'];

        $diff = $this->tracker->get_commit_diff($sha);

        $this->assertStringContainsString('diff --git', $diff);
        $this->assertStringContainsString('-original', $diff);
        $this->assertStringContainsString('+modified', $diff);
    }

    public function test_get_commit_diff_shows_created_file(): void {
        $test_file = $this->plugin_dir . '/new.txt';
        file_put_contents($test_file, 'new content');

        $this->tracker->track_change('new.txt', 'created', null, 'Created file');

        $commits = $this->tracker->get_recent_commits();
        $sha = $commits[0]['sha'];

        $diff = $this->tracker->get_commit_diff($sha);

        $this->assertStringContainsString('new file mode', $diff);
        $this->assertStringContainsString('+new content', $diff);
    }

    // -------------------------------------------------------------------------
    // revert_to_commit tests
    // -------------------------------------------------------------------------

    public function test_revert_to_commit_returns_error_when_inactive(): void {
        $result = $this->tracker->revert_to_commit('invalid-sha');

        $this->assertFalse($result['success']);
        $this->assertContains('Tracking not active', $result['errors']);
    }

    public function test_revert_to_commit_returns_error_for_invalid_sha(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'content');

        $this->tracker->track_change('test.txt', 'modified', 'original', 'Test');

        $result = $this->tracker->revert_to_commit('0000000000000000000000000000000000000000');

        $this->assertFalse($result['success']);
        $this->assertContains('Invalid commit SHA', $result['errors']);
    }

    public function test_revert_to_commit_restores_file_content(): void {
        $test_file = $this->plugin_dir . '/test.txt';

        file_put_contents($test_file, 'version 1');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'First');

        $commits_after_first = $this->tracker->get_recent_commits();
        $first_commit_sha = $commits_after_first[0]['sha'];

        file_put_contents($test_file, 'version 2');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'Second');

        file_put_contents($test_file, 'version 3');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'Third');

        $this->assertEquals('version 3', file_get_contents($test_file));

        $result = $this->tracker->revert_to_commit($first_commit_sha);

        $this->assertTrue($result['success']);
        $this->assertEquals('version 1', file_get_contents($test_file));
    }

    public function test_revert_to_commit_reports_reverted_files(): void {
        $test_file = $this->plugin_dir . '/test.txt';

        file_put_contents($test_file, 'version 1');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'First');

        $commits = $this->tracker->get_recent_commits();
        $first_sha = $commits[0]['sha'];

        file_put_contents($test_file, 'version 2');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'Second');

        $result = $this->tracker->revert_to_commit($first_sha);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['reverted']);
        $this->assertContains('test.txt', $result['reverted']);
    }

    // -------------------------------------------------------------------------
    // Plugin name tests
    // -------------------------------------------------------------------------

    public function test_get_name_returns_plugin_name_from_header(): void {
        $main_file = $this->plugin_dir . '/test-plugin.php';
        file_put_contents($main_file, "<?php\n/**\n * Plugin Name: My Test Plugin\n */\n");

        $this->assertEquals('My Test Plugin', $this->tracker->get_name());
    }

    public function test_get_name_returns_fallback_for_no_header(): void {
        // No plugin file, should return formatted directory name
        $this->assertEquals('Test Plugin', $this->tracker->get_name());
    }

    // -------------------------------------------------------------------------
    // Existing git detection tests
    // -------------------------------------------------------------------------

    public function test_has_existing_git_returns_false_when_no_git(): void {
        $this->assertFalse($this->tracker->has_existing_git());
    }

    public function test_has_existing_git_returns_true_when_git_exists(): void {
        mkdir($this->git_dir, 0755, true);

        $tracker = new Git_Tracker($this->plugin_dir);
        $this->assertTrue($tracker->has_existing_git());
    }

    public function test_get_work_tree_returns_correct_path(): void {
        $this->assertEquals($this->plugin_dir, $this->tracker->get_work_tree());
    }

    // -------------------------------------------------------------------------
    // Pre-existing .git directory tests
    // -------------------------------------------------------------------------

    public function test_has_ai_changes_returns_false_when_git_exists_without_ai_changes_branch(): void {
        // Simulate a plugin fetched from Playground - has .git but no ai-changes branch
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        file_put_contents($this->git_dir . '/refs/heads/main', "abc123\n");

        $tracker = new Git_Tracker($this->plugin_dir);

        $this->assertTrue($tracker->has_existing_git());
        $this->assertFalse($tracker->has_ai_changes());
    }

    public function test_has_ai_changes_returns_true_when_ai_changes_branch_exists(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        file_put_contents($this->git_dir . '/refs/heads/ai-changes', "abc123\n");

        $tracker = new Git_Tracker($this->plugin_dir);

        $this->assertTrue($tracker->has_existing_git());
        $this->assertTrue($tracker->has_ai_changes());
    }

    public function test_track_change_works_with_preexisting_git(): void {
        // Pre-create a .git directory (like from Playground)
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        file_put_contents($this->git_dir . '/HEAD', "ref: refs/heads/main\n");

        $tracker = new Git_Tracker($this->plugin_dir);
        $this->assertTrue($tracker->has_existing_git());

        // Track a change
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $result = $tracker->track_change('test.txt', 'modified', 'original content', 'Test change');

        $this->assertTrue($result);
        $this->assertTrue($tracker->has_ai_changes());
        $this->assertFileExists($this->git_dir . '/refs/heads/ai-changes');
    }

    public function test_track_change_creates_index_in_preexisting_git(): void {
        // Pre-create minimal .git directory
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);

        $tracker = new Git_Tracker($this->plugin_dir);

        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');
        $tracker->track_change('test.txt', 'modified', 'original content', 'Test');

        // Our custom index should exist (ai-created only exists for created files)
        $this->assertFileExists($this->git_dir . '/index');
    }

    public function test_track_created_file_creates_ai_created_in_preexisting_git(): void {
        // Pre-create minimal .git directory
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);

        $tracker = new Git_Tracker($this->plugin_dir);

        $test_file = $this->plugin_dir . '/new-file.txt';
        file_put_contents($test_file, 'new content');
        $tracker->track_change('new-file.txt', 'created', null, 'Created');

        // ai-created file should exist for created files
        $this->assertFileExists($this->git_dir . '/ai-created');
    }

    public function test_new_commit_uses_existing_ai_changes_as_parent(): void {
        // Set up with existing ai-changes branch
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'version 1');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'First commit');

        $commits_after_first = $this->tracker->get_recent_commits();
        $first_sha = $commits_after_first[0]['sha'];

        // Make another change
        file_put_contents($test_file, 'version 2');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'Second commit');

        $commits_after_second = $this->tracker->get_recent_commits();

        // Second commit should be first in list, first commit second
        $this->assertEquals('Second commit', $commits_after_second[0]['message']);
        $this->assertEquals('First commit', $commits_after_second[1]['message']);
        $this->assertEquals($first_sha, $commits_after_second[1]['sha']);
    }

    public function test_preexisting_ai_changes_branch_is_continued(): void {
        // Create git structure and initial change
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'initial');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'Initial');

        $initial_commits = $this->tracker->get_recent_commits();
        $initial_sha = $initial_commits[0]['sha'];
        $initial_count = count($initial_commits);

        // Create new tracker instance (simulating page reload / new session)
        $tracker2 = new Git_Tracker($this->plugin_dir);

        // Make another change with new tracker
        file_put_contents($test_file, 'continued');
        $tracker2->track_change('test.txt', 'modified', 'original', 'Continued');

        $final_commits = $tracker2->get_recent_commits();

        // Should have one more commit than before
        $this->assertCount($initial_count + 1, $final_commits);
        $this->assertEquals('Continued', $final_commits[0]['message']);
        // The initial commit should still be in the history
        $found_initial = false;
        foreach ($final_commits as $commit) {
            if ($commit['sha'] === $initial_sha) {
                $found_initial = true;
                break;
            }
        }
        $this->assertTrue($found_initial, 'Initial commit should still be in history');
    }

    public function test_get_original_content_with_preexisting_git(): void {
        // Pre-create .git
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);

        $tracker = new Git_Tracker($this->plugin_dir);

        $test_file = $this->plugin_dir . '/test.txt';
        $original = 'original content';
        file_put_contents($test_file, 'modified content');

        $tracker->track_change('test.txt', 'modified', $original, 'Test');

        // Should be able to retrieve original content
        $this->assertEquals($original, $tracker->get_original_content('test.txt'));
    }

    public function test_revert_works_with_preexisting_git(): void {
        // Pre-create .git
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);

        $tracker = new Git_Tracker($this->plugin_dir);

        $test_file = $this->plugin_dir . '/test.txt';
        $original = 'original content';
        file_put_contents($test_file, 'modified content');

        $tracker->track_change('test.txt', 'modified', $original, 'Test');
        $tracker->revert_file('test.txt');

        $this->assertEquals($original, file_get_contents($test_file));
    }

    public function test_created_file_tracking_with_preexisting_git(): void {
        // Pre-create .git
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);

        $tracker = new Git_Tracker($this->plugin_dir);

        $test_file = $this->plugin_dir . '/new-file.txt';
        file_put_contents($test_file, 'new content');

        $result = $tracker->track_change('new-file.txt', 'created', null, 'Created');

        $this->assertTrue($result);
        $this->assertTrue($tracker->is_tracked('new-file.txt'));
        $this->assertTrue($tracker->has_ai_changes());
    }

    public function test_diff_generation_with_preexisting_git(): void {
        // Pre-create .git
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);

        $tracker = new Git_Tracker($this->plugin_dir);

        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, "line1\nmodified");

        $tracker->track_change('test.txt', 'modified', "line1\noriginal", 'Test');

        $diff = $tracker->generate_diff();

        $this->assertStringContainsString('diff --git', $diff);
        $this->assertStringContainsString('-original', $diff);
        $this->assertStringContainsString('+modified', $diff);
    }

    // -------------------------------------------------------------------------
    // Broken .git directory tests
    // -------------------------------------------------------------------------

    public function test_broken_git_corrupt_index_file(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        // Write garbage to index file
        file_put_contents($this->git_dir . '/index', 'not a valid index');

        $tracker = new Git_Tracker($this->plugin_dir);

        // Should not crash, should treat as empty
        $this->assertFalse($tracker->has_changes());
    }

    public function test_broken_git_truncated_index_file(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        // Write truncated data (less than header size)
        file_put_contents($this->git_dir . '/index', 'DIRC');

        $tracker = new Git_Tracker($this->plugin_dir);

        $this->assertFalse($tracker->has_changes());
    }

    public function test_broken_git_wrong_index_signature(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        // Write index with wrong signature
        file_put_contents($this->git_dir . '/index', 'XXXX' . pack('NN', 2, 0));

        $tracker = new Git_Tracker($this->plugin_dir);

        $this->assertFalse($tracker->has_changes());
    }

    public function test_track_change_creates_valid_structure_despite_corrupt_index(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        file_put_contents($this->git_dir . '/index', 'corrupt data');

        $tracker = new Git_Tracker($this->plugin_dir);

        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'content');

        // Track change should overwrite corrupt index with valid one
        $result = $tracker->track_change('test.txt', 'modified', 'original', 'Test');

        $this->assertTrue($result);
        $this->assertTrue($tracker->is_tracked('test.txt'));
    }

    public function test_broken_git_missing_objects_directory(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        // Don't create objects directory

        $tracker = new Git_Tracker($this->plugin_dir);

        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'content');

        // Should create objects directory when needed
        $result = $tracker->track_change('test.txt', 'modified', 'original', 'Test');

        $this->assertTrue($result);
        $this->assertDirectoryExists($this->git_dir . '/objects');
    }

    public function test_broken_git_missing_refs_heads_directory(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        // Don't create refs/heads - simulating broken .git

        $tracker = new Git_Tracker($this->plugin_dir);

        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'content');

        // Should create refs/heads when needed (ensure_git_structure fixes broken dirs)
        $result = $tracker->track_change('test.txt', 'modified', 'original', 'Test');

        $this->assertTrue($result);
        $this->assertDirectoryExists($this->git_dir . '/refs/heads');
        $this->assertFileExists($this->git_dir . '/refs/heads/ai-changes');
    }

    public function test_broken_git_ai_changes_points_to_nonexistent_commit(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        // ai-changes ref points to commit that doesn't exist
        file_put_contents($this->git_dir . '/refs/heads/ai-changes', "0000000000000000000000000000000000000000\n");

        $tracker = new Git_Tracker($this->plugin_dir);

        // has_ai_changes checks file existence, not validity
        $this->assertTrue($tracker->has_ai_changes());

        // get_recent_commits should handle gracefully
        $commits = $tracker->get_recent_commits();
        $this->assertIsArray($commits);
    }

    public function test_get_original_content_returns_null_for_untracked_file(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'content');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'Test');

        // Ask for content of a different, untracked file
        $content = $this->tracker->get_original_content('other.txt');

        $this->assertNull($content);
    }

    public function test_revert_returns_false_for_untracked_file(): void {
        $test_file = $this->plugin_dir . '/test.txt';
        file_put_contents($test_file, 'content');
        $this->tracker->track_change('test.txt', 'modified', 'original', 'Test');

        $result = $this->tracker->revert_file('untracked.txt');

        $this->assertFalse($result);
    }

    public function test_empty_ai_created_file(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        file_put_contents($this->git_dir . '/ai-created', '');
        file_put_contents($this->git_dir . '/refs/heads/ai-changes', "abc\n");

        $tracker = new Git_Tracker($this->plugin_dir);

        // Should not crash, should handle empty file gracefully
        $changes = $tracker->get_changes_by_directory();
        $this->assertIsArray($changes);
    }

    public function test_malformed_ai_created_file(): void {
        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);
        // Multiple empty lines
        file_put_contents($this->git_dir . '/ai-created', "\n\n\nfile.txt\n\n");
        file_put_contents($this->git_dir . '/refs/heads/ai-changes', "abc\n");

        $tracker = new Git_Tracker($this->plugin_dir);

        // Should filter out empty entries
        $this->assertTrue($tracker->is_tracked('file.txt'));
    }
}
