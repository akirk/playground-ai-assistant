---
title: Conversation Context Retrieval
description: How to find and use context from past AI conversations stored in the database
category: context
---

# Retrieving Past Conversation Context

When a user references past work ("we modified plugin X", "continue from where we left off", "remember when we built Y"), you can retrieve context from previous conversations stored in the WordPress database.

## Storage Structure

Conversations are stored as the `ai_conversation` custom post type:

| Field | Content |
|-------|---------|
| `post_title` | Conversation name (often from first user message) |
| `post_content` | Base64-encoded JSON array of all messages |
| `post_excerpt` | Compacted summary of the conversation (if generated) |
| `post_author` | User ID who created it |
| `post_modified` | Last activity timestamp |

### Meta Fields

- `_ai_message_count` - Number of messages
- `_ai_provider` - LLM provider used (anthropic, openai, etc.)
- `_ai_model` - Model name

## Finding Relevant Conversations

Search by title or content keywords:

```php
$args = [
    'post_type' => 'ai_conversation',
    'post_status' => 'publish',
    's' => 'plugin name or keyword', // searches title and content
    'posts_per_page' => 5,
    'orderby' => 'modified',
    'order' => 'DESC',
];
$conversations = get_posts($args);

foreach ($conversations as $conv) {
    echo "ID: {$conv->ID}\n";
    echo "Title: {$conv->post_title}\n";
    echo "Modified: {$conv->post_modified}\n";
    echo "Summary: {$conv->post_excerpt}\n\n";
}
```

## Retrieving Conversation Context

### If Summary Exists (Preferred)

Use the compacted summary from `post_excerpt`:

```php
$conv = get_post(123);
if (!empty($conv->post_excerpt)) {
    echo $conv->post_excerpt;
}
```

### Full Message History

Decode the complete conversation:

```php
$conv = get_post(123);
$messages = json_decode(base64_decode($conv->post_content), true);

foreach ($messages as $msg) {
    $role = $msg['role'];
    $content = is_array($msg['content']) ? '[complex content]' : $msg['content'];
    echo "{$role}: {$content}\n\n";
}
```

### Recent Messages Only

For long conversations, get just the last N messages:

```php
$conv = get_post(123);
$messages = json_decode(base64_decode($conv->post_content), true);
$recent = array_slice($messages, -10); // last 10 messages
```

## Generating a Summary

You have access to the `summarize_conversation` tool that automatically generates and saves conversation summaries.

### Using the Tool

```
Call: summarize_conversation
Arguments:
  conversation_id: 123  (optional - defaults to current conversation)
```

The tool will:
1. Load the conversation messages
2. Generate a summary using the configured LLM
3. Save the summary to `post_excerpt`
4. Return the summary text

### When to Generate Summaries

- When the user asks to "summarize" or "compact" the conversation
- Before closing a long conversation that might be continued later
- When context is getting too long and you need to preserve key information

### Manual Approach (via PHP)

If you need to manually extract key information:

```php
$conv = get_post(123);
$messages = json_decode(base64_decode($conv->post_content), true);

// Extract user messages for context
$user_messages = array_filter($messages, fn($m) => $m['role'] === 'user');
$topics = array_map(function($m) {
    $content = is_array($m['content']) ? '' : $m['content'];
    return wp_trim_words($content, 20);
}, array_slice($user_messages, 0, 5));

print_r($topics);
```

## When to Use This

- User says "continue from...", "we were working on...", "remember when..."
- User references a specific plugin, feature, or task from a past session
- User asks to pick up where they left off

## Best Practices

1. **Search first** - Find candidate conversations before loading full content
2. **Prefer summaries** - Use `post_excerpt` when available to save context space
3. **Confirm with user** - If multiple matches, ask which conversation they mean
4. **Recent messages** - For long conversations without summaries, focus on the last 10-20 messages
