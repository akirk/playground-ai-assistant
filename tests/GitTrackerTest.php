<?php
namespace AI_Assistant\Tests;

use PHPUnit\Framework\TestCase;
use AI_Assistant\Git_Tracker;

/**
 * Unit tests for the Git_Tracker class
 */
class GitTrackerTest extends TestCase {

    private Git_Tracker $tracker;
    private string $test_dir;
    private string $git_dir;

    protected function setUp(): void {
        $this->test_dir = WP_CONTENT_DIR;
        $this->git_dir = $this->test_dir . '/.git';
        $this->tracker = new Git_Tracker();

        // Clean up any existing .git from previous tests
        $this->recursiveDelete($this->git_dir);
    }

    protected function tearDown(): void {
        // Clean up after each test
        $this->recursiveDelete($this->git_dir);

        // Clean up test plugin directories
        $this->recursiveDelete(WP_PLUGIN_DIR . '/test-plugin');
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
        $test_file = $this->test_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', 'original content', 'Test change');

        $this->assertTrue($this->tracker->is_active());

        unlink($test_file);
    }

    public function test_track_change_creates_git_structure(): void {
        $test_file = $this->test_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', 'original content', 'Test change');

        $this->assertDirectoryExists($this->git_dir);
        $this->assertDirectoryExists($this->git_dir . '/objects');
        $this->assertDirectoryExists($this->git_dir . '/refs/heads');
        $this->assertFileExists($this->git_dir . '/HEAD');
        $this->assertFileExists($this->git_dir . '/config');
        $this->assertFileExists($this->git_dir . '/index');

        unlink($test_file);
    }

    public function test_track_change_for_created_file(): void {
        $test_file = $this->test_dir . '/new-file.txt';
        file_put_contents($test_file, 'new content');

        $result = $this->tracker->track_change($test_file, 'created', null, 'Created new file');

        $this->assertTrue($result);
        $this->assertTrue($this->tracker->is_tracked($test_file));

        unlink($test_file);
    }

    public function test_track_change_for_modified_file(): void {
        $test_file = $this->test_dir . '/modified.txt';
        file_put_contents($test_file, 'modified content');

        $result = $this->tracker->track_change($test_file, 'modified', 'original content', 'Modified file');

        $this->assertTrue($result);
        $this->assertTrue($this->tracker->is_tracked($test_file));

        unlink($test_file);
    }

    public function test_get_original_content(): void {
        $test_file = $this->test_dir . '/test.txt';
        $original = 'original content here';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', $original, 'Test change');

        $this->assertEquals($original, $this->tracker->get_original_content($test_file));

        unlink($test_file);
    }

    // -------------------------------------------------------------------------
    // HEAD and branch tests
    // -------------------------------------------------------------------------

    public function test_head_points_to_ai_changes_after_tracking(): void {
        $test_file = $this->test_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', 'original content', 'Test change');

        $head = file_get_contents($this->git_dir . '/HEAD');
        $this->assertEquals("ref: refs/heads/ai-changes\n", $head);

        unlink($test_file);
    }

    public function test_both_branches_exist_after_tracking(): void {
        $test_file = $this->test_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', 'original content', 'Test change');

        $this->assertFileExists($this->git_dir . '/refs/heads/main');
        $this->assertFileExists($this->git_dir . '/refs/heads/ai-changes');

        unlink($test_file);
    }

    // -------------------------------------------------------------------------
    // Diff generation tests
    // -------------------------------------------------------------------------

    public function test_generate_diff_for_modified_file(): void {
        $test_file = $this->test_dir . '/test.txt';
        file_put_contents($test_file, "line1\nline2\nmodified");

        $this->tracker->track_change($test_file, 'modified', "line1\nline2\noriginal", 'Test change');

        $diff = $this->tracker->generate_diff();

        $this->assertStringContainsString('diff --git', $diff);
        $this->assertStringContainsString('-original', $diff);
        $this->assertStringContainsString('+modified', $diff);

        unlink($test_file);
    }

    public function test_generate_diff_for_created_file(): void {
        $test_file = $this->test_dir . '/new.txt';
        file_put_contents($test_file, "new content");

        $this->tracker->track_change($test_file, 'created', null, 'Created file');

        $diff = $this->tracker->generate_diff();

        $this->assertStringContainsString('new file mode', $diff);
        $this->assertStringContainsString('+new content', $diff);

        unlink($test_file);
    }

    // -------------------------------------------------------------------------
    // Revert and reapply tests
    // -------------------------------------------------------------------------

    public function test_revert_modified_file(): void {
        $test_file = $this->test_dir . '/test.txt';
        $original = 'original content';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', $original, 'Test change');
        $result = $this->tracker->revert_file($test_file);

        $this->assertTrue($result);
        $this->assertEquals($original, file_get_contents($test_file));

        unlink($test_file);
    }

    public function test_revert_created_file(): void {
        $test_file = $this->test_dir . '/new.txt';
        file_put_contents($test_file, 'new content');

        $this->tracker->track_change($test_file, 'created', null, 'Created file');
        $result = $this->tracker->revert_file($test_file);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($test_file);
    }

    public function test_is_reverted_for_modified_file(): void {
        $test_file = $this->test_dir . '/test.txt';
        $original = 'original content';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', $original, 'Test change');

        $this->assertFalse($this->tracker->is_reverted($test_file));

        $this->tracker->revert_file($test_file);

        $this->assertTrue($this->tracker->is_reverted($test_file));

        unlink($test_file);
    }

    public function test_reapply_file(): void {
        $test_file = $this->test_dir . '/test.txt';
        $original = 'original content';
        $modified = 'modified content';
        file_put_contents($test_file, $modified);

        $this->tracker->track_change($test_file, 'modified', $original, 'Test change');
        $this->tracker->revert_file($test_file);

        $this->assertEquals($original, file_get_contents($test_file));

        $result = $this->tracker->reapply_file($test_file);

        $this->assertTrue($result);
        $this->assertEquals($modified, file_get_contents($test_file));

        unlink($test_file);
    }

    // -------------------------------------------------------------------------
    // Changes by directory tests
    // -------------------------------------------------------------------------

    public function test_get_changes_by_directory(): void {
        $plugin_dir = WP_PLUGIN_DIR . '/test-plugin';
        mkdir($plugin_dir, 0755, true);

        $test_file = $plugin_dir . '/test.php';
        file_put_contents($test_file, 'modified');

        $this->tracker->track_change($test_file, 'modified', 'original', 'Test');

        $changes = $this->tracker->get_changes_by_directory();

        $this->assertArrayHasKey('plugins/test-plugin', $changes);
        $this->assertEquals(1, $changes['plugins/test-plugin']['count']);
    }

    // -------------------------------------------------------------------------
    // build_standalone_git tests
    // -------------------------------------------------------------------------

    public function test_build_standalone_git_creates_structure(): void {
        $plugin_dir = WP_PLUGIN_DIR . '/test-plugin';
        mkdir($plugin_dir, 0755, true);

        $test_file = $plugin_dir . '/plugin.php';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', 'original content', 'Test change');

        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $result = $this->tracker->build_standalone_git('plugins/test-plugin', $target_dir);

        $this->assertTrue($result);
        $this->assertDirectoryExists($target_dir . '/.git');
        $this->assertDirectoryExists($target_dir . '/.git/objects');
        $this->assertFileExists($target_dir . '/.git/HEAD');
        $this->assertFileExists($target_dir . '/.git/refs/heads/main');
        $this->assertFileExists($target_dir . '/.git/refs/heads/ai-changes');

        $this->recursiveDelete($target_dir);
    }

    public function test_build_standalone_git_head_points_to_ai_changes(): void {
        $plugin_dir = WP_PLUGIN_DIR . '/test-plugin';
        mkdir($plugin_dir, 0755, true);

        $test_file = $plugin_dir . '/plugin.php';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', 'original content', 'Test change');

        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $this->tracker->build_standalone_git('plugins/test-plugin', $target_dir);

        $head = file_get_contents($target_dir . '/.git/HEAD');
        $this->assertEquals("ref: refs/heads/ai-changes\n", $head);

        $this->recursiveDelete($target_dir);
    }

    public function test_build_standalone_git_filemode_is_false(): void {
        $plugin_dir = WP_PLUGIN_DIR . '/test-plugin';
        mkdir($plugin_dir, 0755, true);

        $test_file = $plugin_dir . '/plugin.php';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', 'original content', 'Test change');

        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $this->tracker->build_standalone_git('plugins/test-plugin', $target_dir);

        $config = file_get_contents($target_dir . '/.git/config');
        $this->assertStringContainsString('filemode = false', $config);

        $this->recursiveDelete($target_dir);
    }

    public function test_build_standalone_git_returns_false_for_no_changes(): void {
        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $result = $this->tracker->build_standalone_git('plugins/nonexistent', $target_dir);

        $this->assertFalse($result);

        $this->recursiveDelete($target_dir);
    }

    public function test_build_standalone_git_for_created_file(): void {
        $plugin_dir = WP_PLUGIN_DIR . '/test-plugin';
        mkdir($plugin_dir, 0755, true);

        $test_file = $plugin_dir . '/new-plugin.php';
        file_put_contents($test_file, '<?php // New plugin');

        $this->tracker->track_change($test_file, 'created', null, 'Created plugin');

        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $result = $this->tracker->build_standalone_git('plugins/test-plugin', $target_dir);

        $this->assertTrue($result);

        // main branch should exist (even if empty tree for created-only files)
        $this->assertFileExists($target_dir . '/.git/refs/heads/main');
        $this->assertFileExists($target_dir . '/.git/refs/heads/ai-changes');

        $this->recursiveDelete($target_dir);
    }

    public function test_build_standalone_git_recreates_commit_history(): void {
        $plugin_dir = WP_PLUGIN_DIR . '/test-plugin';
        mkdir($plugin_dir, 0755, true);

        $test_file = $plugin_dir . '/plugin.php';

        // First change
        file_put_contents($test_file, 'version 1');
        $this->tracker->track_change($test_file, 'modified', 'original', 'plugins/test-plugin/plugin.php: First change');

        // Second change
        file_put_contents($test_file, 'version 2');
        $this->tracker->track_change($test_file, 'modified', 'original', 'plugins/test-plugin/plugin.php: Second change');

        $target_dir = sys_get_temp_dir() . '/standalone-git-test-' . uniqid();
        mkdir($target_dir, 0755, true);

        $this->tracker->build_standalone_git('plugins/test-plugin', $target_dir);

        // Check that ai-changes branch has commits
        $ai_changes_ref = trim(file_get_contents($target_dir . '/.git/refs/heads/ai-changes'));
        $this->assertNotEmpty($ai_changes_ref);
        $this->assertEquals(40, strlen($ai_changes_ref)); // SHA-1 length

        $this->recursiveDelete($target_dir);
    }

    // -------------------------------------------------------------------------
    // Clear tests
    // -------------------------------------------------------------------------

    public function test_clear_all(): void {
        $test_file = $this->test_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', 'original content', 'Test change');

        $this->assertTrue($this->tracker->is_active());

        $count = $this->tracker->clear_all();

        $this->assertGreaterThan(0, $count);
        $this->assertFalse($this->tracker->is_active());

        unlink($test_file);
    }

    public function test_has_changes(): void {
        $this->assertFalse($this->tracker->has_changes());

        $test_file = $this->test_dir . '/test.txt';
        file_put_contents($test_file, 'modified content');

        $this->tracker->track_change($test_file, 'modified', 'original content', 'Test change');

        $this->assertTrue($this->tracker->has_changes());

        unlink($test_file);
    }
}
