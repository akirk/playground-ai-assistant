<?php
namespace AI_Assistant\Tests;

use PHPUnit\Framework\TestCase;
use AI_Assistant\Tools;

/**
 * Unit tests for the Tools class
 */
class ToolsTest extends TestCase {

    private Tools $tools;

    protected function setUp(): void {
        $this->tools = new Tools();
    }

    public function test_get_all_tools_returns_array(): void {
        $tools = $this->tools->get_all_tools();
        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);
    }

    public function test_all_tools_have_required_properties(): void {
        $tools = $this->tools->get_all_tools();

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool, 'Tool missing name');
            $this->assertArrayHasKey('description', $tool, "Tool {$tool['name']} missing description");
            $this->assertArrayHasKey('parameters', $tool, "Tool {$tool['name']} missing parameters");
            $this->assertNotEmpty($tool['name'], 'Tool name cannot be empty');
            $this->assertNotEmpty($tool['description'], "Tool {$tool['name']} description cannot be empty");
        }
    }

    public function test_tool_names_are_unique(): void {
        $tools = $this->tools->get_all_tools();
        $names = array_column($tools, 'name');
        $unique_names = array_unique($names);

        $this->assertCount(count($names), $unique_names, 'Duplicate tool names found');
    }

    public function test_write_file_tool_requires_path_and_content(): void {
        $tools = $this->tools->get_all_tools();
        $write_file = $this->findToolByName($tools, 'write_file');

        $this->assertNotNull($write_file);
        $this->assertContains('path', $write_file['parameters']['required']);
        $this->assertContains('content', $write_file['parameters']['required']);
    }

    public function test_edit_file_tool_requires_path_and_edits(): void {
        $tools = $this->tools->get_all_tools();
        $edit_file = $this->findToolByName($tools, 'edit_file');

        $this->assertNotNull($edit_file);
        $this->assertContains('path', $edit_file['parameters']['required']);
        $this->assertContains('edits', $edit_file['parameters']['required']);
    }

    public function test_search_files_tool_requires_pattern(): void {
        $tools = $this->tools->get_all_tools();
        $search_files = $this->findToolByName($tools, 'search_files');

        $this->assertNotNull($search_files);
        $this->assertContains('pattern', $search_files['parameters']['required']);
    }

    public function test_read_file_tool_requires_path(): void {
        $tools = $this->tools->get_all_tools();
        $read_file = $this->findToolByName($tools, 'read_file');

        $this->assertNotNull($read_file);
        $this->assertContains('path', $read_file['parameters']['required']);
    }

    public function test_search_content_tool_requires_needle(): void {
        $tools = $this->tools->get_all_tools();
        $search_content = $this->findToolByName($tools, 'search_content');

        $this->assertNotNull($search_content);
        $this->assertContains('needle', $search_content['parameters']['required']);
    }

    public function test_get_read_only_tools_returns_subset(): void {
        $all_tools = $this->tools->get_all_tools();
        $read_only_tools = $this->tools->get_read_only_tools();

        $this->assertLessThan(count($all_tools), count($read_only_tools));

        $read_only_names = array_column($read_only_tools, 'name');

        $this->assertContains('read_file', $read_only_names);
        $this->assertContains('search_files', $read_only_names);
        $this->assertContains('list_directory', $read_only_names);
        $this->assertContains('search_content', $read_only_names);

        $this->assertNotContains('write_file', $read_only_names);
        $this->assertNotContains('edit_file', $read_only_names);
        $this->assertNotContains('delete_file', $read_only_names);
    }

    public function test_tool_parameters_have_valid_types(): void {
        $tools = $this->tools->get_all_tools();
        $valid_types = ['string', 'number', 'boolean', 'array', 'object'];

        foreach ($tools as $tool) {
            $params = $tool['parameters'];
            if (isset($params['properties']) && is_array($params['properties'])) {
                foreach ($params['properties'] as $prop_name => $prop) {
                    if (isset($prop['type'])) {
                        $this->assertContains(
                            $prop['type'],
                            $valid_types,
                            "Tool {$tool['name']} property $prop_name has invalid type: {$prop['type']}"
                        );
                    }
                }
            }
        }
    }

    public function test_expected_tools_exist(): void {
        $tools = $this->tools->get_all_tools();
        $names = array_column($tools, 'name');

        $expected_tools = [
            'read_file',
            'write_file',
            'edit_file',
            'delete_file',
            'list_directory',
            'search_files',
            'search_content',
            'db_query',
            'get_plugins',
            'get_themes',
            'install_plugin',
            'run_php',
            'navigate',
            'list_abilities',
            'get_ability',
            'execute_ability',
            'list_skills',
            'get_skill',
        ];

        foreach ($expected_tools as $expected) {
            $this->assertContains($expected, $names, "Expected tool '$expected' not found");
        }
    }

    private function findToolByName(array $tools, string $name): ?array {
        foreach ($tools as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }
        return null;
    }
}
