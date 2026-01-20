<?php
namespace AI_Assistant\Tests;

use PHPUnit\Framework\TestCase;
use AI_Assistant\Tools;
use AI_Assistant\Executor;

/**
 * Unit tests for the Executor class
 */
class ExecutorTest extends TestCase {

    private Tools $tools;
    private Executor $executor;
    private string $test_dir;

    protected function setUp(): void {
        $this->tools = new Tools();
        $this->executor = new Executor($this->tools);
        $this->test_dir = WP_CONTENT_DIR;

        // Ensure clean test environment
        $this->cleanTestDirectory();
        $this->createTestStructure();
    }

    protected function tearDown(): void {
        $this->cleanTestDirectory();
    }

    private function cleanTestDirectory(): void {
        $plugins_dir = $this->test_dir . '/plugins';
        if (is_dir($plugins_dir)) {
            $this->recursiveDelete($plugins_dir);
        }
        mkdir($plugins_dir, 0755, true);
    }

    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createTestStructure(): void {
        // Create test plugin directories and files
        $plugins = [
            'test-plugin' => [
                'test-plugin.php' => '<?php /* Plugin Name: Test Plugin */',
                'includes/helper.php' => '<?php function test_helper() {}',
            ],
            'another-plugin' => [
                'another-plugin.php' => '<?php /* Plugin Name: Another Plugin */',
                'lib/utils.php' => '<?php class Utils {}',
            ],
        ];

        foreach ($plugins as $plugin_name => $files) {
            $plugin_dir = $this->test_dir . '/plugins/' . $plugin_name;
            mkdir($plugin_dir, 0755, true);
            foreach ($files as $file => $content) {
                $file_path = $plugin_dir . '/' . $file;
                $file_dir = dirname($file_path);
                if (!is_dir($file_dir)) {
                    mkdir($file_dir, 0755, true);
                }
                file_put_contents($file_path, $content);
            }
        }
    }

    // ===== SEARCH FILES TESTS =====

    public function test_search_files_with_valid_pattern(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/*/*.php',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('pattern', $result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertEquals('plugins/*/*.php', $result['pattern']);
        $this->assertGreaterThanOrEqual(2, $result['count']);
    }

    public function test_search_files_with_no_matches(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/nonexistent-plugin/*.php',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['count']);
        $this->assertEmpty($result['matches']);
    }

    public function test_search_files_with_nested_pattern(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/test-plugin/**/*.php',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
    }

    public function test_search_files_with_specific_plugin_pattern(): void {
        // This is the pattern that was causing issues: plugins/hello-dolly/*.php
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/test-plugin/*.php',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertEquals(1, $result['count']);
        $this->assertEquals('plugins/test-plugin/test-plugin.php', $result['matches'][0]['path']);
    }

    public function test_search_files_returns_file_metadata(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/test-plugin/*.php',
        ]);

        $this->assertNotEmpty($result['matches']);
        $match = $result['matches'][0];
        $this->assertArrayHasKey('path', $match);
        $this->assertArrayHasKey('type', $match);
        $this->assertArrayHasKey('size', $match);
        $this->assertEquals('file', $match['type']);
    }

    public function test_search_files_missing_pattern_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("search_files requires 'pattern' argument");

        $this->executor->execute_tool('search_files', []);
    }

    // ===== WRITE FILE TESTS =====

    public function test_write_file_creates_new_file(): void {
        $result = $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/new-file.php',
            'content' => '<?php echo "Hello";',
            'reason' => 'Test creating new file',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('created', $result['action']);
        $this->assertEquals('plugins/test-plugin/new-file.php', $result['path']);
        $this->assertNull($result['previous_size']);

        // Verify file exists
        $this->assertFileExists($this->test_dir . '/plugins/test-plugin/new-file.php');
    }

    public function test_write_file_updates_existing_file(): void {
        $result = $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'content' => '<?php /* Updated Plugin */',
            'reason' => 'Test updating file',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('updated', $result['action']);
        $this->assertNotNull($result['previous_size']);
    }

    public function test_write_file_missing_content_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("write_file requires 'content' argument");

        $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/file.php',
        ]);
    }

    public function test_write_file_missing_path_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("write_file requires 'path' argument");

        $this->executor->execute_tool('write_file', [
            'content' => '<?php echo "test";',
        ]);
    }

    public function test_write_file_creates_directories(): void {
        $result = $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/deep/nested/dir/file.php',
            'content' => '<?php',
            'reason' => 'Test creating nested directories',
        ]);

        $this->assertEquals('created', $result['action']);
        $this->assertFileExists($this->test_dir . '/plugins/test-plugin/deep/nested/dir/file.php');
    }

    public function test_write_file_with_empty_content(): void {
        $result = $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/empty.php',
            'content' => '',
            'reason' => 'Test empty file',
        ]);

        $this->assertEquals('created', $result['action']);
        $this->assertEquals(0, $result['size']);
    }

    public function test_write_file_with_array_content_converts_to_json(): void {
        $result = $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/data.json',
            'content' => ['key' => 'value', 'nested' => ['a' => 1]],
            'reason' => 'Test JSON conversion',
        ]);

        $this->assertEquals('created', $result['action']);
        $content = file_get_contents($this->test_dir . '/plugins/test-plugin/data.json');
        $decoded = json_decode($content, true);
        $this->assertEquals('value', $decoded['key']);
    }

    // ===== READ FILE TESTS =====

    public function test_read_file_returns_content(): void {
        $result = $this->executor->execute_tool('read_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('modified', $result);
        $this->assertStringContainsString('Plugin Name: Test Plugin', $result['content']);
    }

    public function test_read_file_not_found_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->executor->execute_tool('read_file', [
            'path' => 'plugins/nonexistent/file.php',
        ]);
    }

    public function test_read_file_missing_path_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("read_file requires 'path' argument");

        $this->executor->execute_tool('read_file', []);
    }

    // ===== EDIT FILE TESTS =====

    public function test_edit_file_applies_single_edit(): void {
        $result = $this->executor->execute_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => [
                ['search' => 'Test Plugin', 'replace' => 'Modified Plugin'],
            ],
            'reason' => 'Test editing file',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['edits_applied']);
        $this->assertEquals(0, $result['edits_failed']);

        // Verify the edit was applied
        $content = file_get_contents($this->test_dir . '/plugins/test-plugin/test-plugin.php');
        $this->assertStringContainsString('Modified Plugin', $content);
    }

    public function test_edit_file_fails_when_search_not_found(): void {
        $result = $this->executor->execute_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => [
                ['search' => 'nonexistent string', 'replace' => 'replacement'],
            ],
            'reason' => 'Test failed edit',
        ]);

        $this->assertEquals(0, $result['edits_applied']);
        $this->assertEquals(1, $result['edits_failed']);
        $this->assertEquals('Search string not found', $result['failed'][0]['reason']);
    }

    public function test_edit_file_missing_edits_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("edit_file requires 'edits' argument");

        $this->executor->execute_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);
    }

    public function test_edit_file_edits_must_be_array(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("edit_file 'edits' must be an array");

        $this->executor->execute_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => 'not an array',
        ]);
    }

    public function test_edit_file_not_found_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->executor->execute_tool('edit_file', [
            'path' => 'plugins/nonexistent/file.php',
            'edits' => [['search' => 'a', 'replace' => 'b']],
            'reason' => 'Test file not found',
        ]);
    }

    // ===== DELETE FILE TESTS =====

    public function test_delete_file_removes_file(): void {
        // Create a file to delete
        $file_path = $this->test_dir . '/plugins/test-plugin/to-delete.php';
        file_put_contents($file_path, '<?php');

        $result = $this->executor->execute_tool('delete_file', [
            'path' => 'plugins/test-plugin/to-delete.php',
            'reason' => 'Test deleting file',
        ]);

        $this->assertEquals('deleted', $result['action']);
        $this->assertFileDoesNotExist($file_path);
    }

    public function test_delete_file_not_found_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->executor->execute_tool('delete_file', [
            'path' => 'plugins/nonexistent/file.php',
            'reason' => 'Test file not found',
        ]);
    }

    // ===== LIST DIRECTORY TESTS =====

    public function test_list_directory_returns_items(): void {
        $result = $this->executor->execute_tool('list_directory', [
            'path' => 'plugins/test-plugin',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertGreaterThan(0, $result['count']);
    }

    public function test_list_directory_not_found_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Directory not found');

        $this->executor->execute_tool('list_directory', [
            'path' => 'plugins/nonexistent',
        ]);
    }

    public function test_list_directory_on_file_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Not a directory');

        $this->executor->execute_tool('list_directory', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);
    }

    // ===== SEARCH CONTENT TESTS =====

    public function test_search_content_finds_matches(): void {
        $result = $this->executor->execute_tool('search_content', [
            'needle' => 'Plugin Name',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertGreaterThan(0, $result['count']);
    }

    public function test_search_content_with_directory_filter(): void {
        $result = $this->executor->execute_tool('search_content', [
            'needle' => 'Plugin Name',
            'directory' => 'plugins/test-plugin',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['count']);
    }

    public function test_search_content_missing_needle_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("search_content requires 'needle' argument");

        $this->executor->execute_tool('search_content', []);
    }

    // ===== PERMISSION TESTS =====

    public function test_read_only_permission_allows_read_file(): void {
        $result = $this->executor->execute_tool('read_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ], 'read_only');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
    }

    public function test_read_only_permission_allows_search_files(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/*/*.php',
        ], 'read_only');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
    }

    public function test_read_only_permission_blocks_write_file(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'write_file' requires full access permission");

        $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/file.php',
            'content' => '<?php',
        ], 'read_only');
    }

    public function test_read_only_permission_blocks_edit_file(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'edit_file' requires full access permission");

        $this->executor->execute_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => [['search' => 'a', 'replace' => 'b']],
        ], 'read_only');
    }

    public function test_read_only_permission_blocks_delete_file(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'delete_file' requires full access permission");

        $this->executor->execute_tool('delete_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ], 'read_only');
    }

    public function test_chat_only_permission_blocks_all_tools(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tool execution not allowed with chat-only permission');

        $this->executor->execute_tool('read_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ], 'chat_only');
    }

    // ===== PATH SECURITY TESTS =====

    public function test_path_traversal_blocked(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Access denied');

        $this->executor->execute_tool('read_file', [
            'path' => '../../../etc/passwd',
        ]);
    }

    public function test_empty_path_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Path cannot be empty');

        $this->executor->execute_tool('read_file', [
            'path' => '',
        ]);
    }

    // ===== UNKNOWN TOOL TEST =====

    public function test_unknown_tool_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown tool: fake_tool');

        $this->executor->execute_tool('fake_tool', []);
    }
}
