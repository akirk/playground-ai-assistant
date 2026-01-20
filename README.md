# Playground AI Assistant

An AI-powered chat interface for WordPress Playground. Bring your own API key or connect to a local LLM.

![Screenshot](screenshot.png)

## Features

- **Multiple LLM Providers**: Anthropic (Claude), OpenAI, and local models via Ollama/LM Studio
- **Tool System**: Execute PHP, read/write/edit files, query the database, manage plugins and themes
- **Git-Compatible Change Tracking**: All AI modifications are tracked using a git-compatible structure in `wp-content`
- **Revert & Reapply**: Undo any AI change and optionally reapply it later
- **Patch Export/Import**: Download changes as `.patch` files or apply patches from elsewhere
- **Portable History**: Download your Playground zip with full git history intact for local git operations
- **Conversation History**: Persistent storage with automatic summarization
- **Streaming Responses**: Real-time output as the AI generates responses
- **Settings Persistence**: Configuration stored in localStorage, surviving Playground restarts

## Requirements

- WordPress 6.0+
- PHP 7.4+
- WordPress Playground environment

## Installation

1. Upload the plugin to `/wp-content/plugins/playground-ai-assistant`
2. Activate through the Plugins menu
3. Configure your API keys in Settings > AI Assistant

## Configuration

Go to **Settings > AI Assistant** to configure:

- **Provider**: Choose between Anthropic, OpenAI, or Local (Ollama/LM Studio)
- **Model**: Select which model to use
- **API Keys**: Enter your provider API keys
- **Local Endpoint**: Configure Ollama/LM Studio endpoint (default: `http://localhost:11434`)

## Usage

The AI Assistant panel appears in the WordPress admin screen meta area (alongside Help and Screen Options). Click to expand and start chatting.

### Available Tools

| Tool | Description |
|------|-------------|
| `read_file` | Read file contents |
| `write_file` | Create new files |
| `edit_file` | Edit existing files via search/replace |
| `delete_file` | Delete files |
| `list_directory` | List directory contents |
| `search_files` | Search for files by glob pattern |
| `search_content` | Search for text within files |
| `db_query` | Execute SELECT queries on the database |
| `get_plugins` | List installed plugins |
| `get_themes` | List installed themes |
| `install_plugin` | Install a plugin from WordPress.org |
| `run_php` | Execute PHP code |
| `list_abilities` | Discover available WordPress abilities |
| `get_ability` | Get details of a specific ability |
| `execute_ability` | Execute a WordPress ability |
| `navigate` | Navigate to a URL within the site |
| `get_page_html` | Get HTML of the current page |
| `summarize_conversation` | Generate a conversation summary |

### YOLO Mode

Enable the YOLO checkbox to skip confirmation dialogs for tool execution. Use with caution.

## AI Changes

Find this under **Tools > AI Changes**. Every file the AI creates or modifies is tracked using a git-compatible structure stored in `wp-content/.git`.

- **View diffs**: Click any file to see exactly what changed
- **Commit history**: Browse individual commits with expandable diffs
- **Revert**: Restore any file to its original state, or revert to a specific commit
- **Reapply**: Re-apply previously reverted changes
- **Export**: Select files and download a unified `.patch` file
- **Import**: Apply patch files to your installation
- **PHP Linting**: Automatic syntax checking for PHP files
- **Git-compatible**: Download your Playground zip and use standard git commands locally
- **Plugin ZIP downloads**: Modified plugins get a "Download ZIP" link on the Plugins page

### Using Git Locally

When you download your Playground as a ZIP, the `wp-content` folder contains a full git repository with two branches:

- **main**: The original state before AI modifications
- **ai-changes**: Each AI modification as a separate commit with a descriptive message

Each file change creates its own commit with a message describing why the change was made (e.g., "Add form validation", "Fix login redirect bug"). To explore:

```bash
cd wp-content
git log --oneline ai-changes   # See all AI commits
git diff main..ai-changes      # See all changes at once
git show <commit-sha>          # Inspect a specific change
```

This makes it safe to experiment—you can always undo what the AI did.

### Recovery Mode Integration

If the AI writes code that breaks WordPress (e.g., a PHP syntax error), the assistant detects consecutive failed requests and warns you that something is wrong—while the current page still works.

To recover:

1. Click the grid icon in the Playground top bar
2. Select **Recovery Mode** to boot into troubleshooting mode
3. Activate the AI Assistant plugin and go to **Tools → AI Changes**
4. Revert the problematic changes

The recovery screen highlights recently modified plugins to help identify the culprit.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.
