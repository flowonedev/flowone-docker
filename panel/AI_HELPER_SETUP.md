# AI Helper Setup Guide

## Configuration

### 1. Add OpenAI API Key

Edit `api/config.local.php` (create it from `api/config.local.example.php` if it doesn't exist):

```php
return [
    // ... other config ...
    
    'ai_helper' => [
        'openai_api_key' => 'sk-your-api-key-here',  // Get from https://platform.openai.com/api-keys
        'openai_model' => 'gpt-5.2',  // Default model
        'max_tokens' => 2000,
        'temperature' => 0.3,
    ],
];
```

### 2. Available Models

You can use any of these GPT-5 models:

- **gpt-5.2** (default) - Complex reasoning, multi-step tasks, code-heavy operations
- **gpt-5.2-pro** - Tough problems requiring deeper analysis
- **gpt-5.1-codex-max** - Code analysis, config parsing, interactive coding
- **gpt-5-mini** - Cost-optimized for simple queries
- **gpt-5-nano** - High-throughput simple tasks

### 3. Database Migration

The database tables are automatically created on first use. No manual migration needed!

The system will automatically create these tables when you first access the AI Helper:
- `ai_conversations` - Stores conversation history
- `ai_messages` - Stores individual messages
- `ai_cached_issues` - Caches identified issues

### 4. Testing

1. Make sure `api/config.local.php` has your OpenAI API key
2. Access the AI Helper from the dashboard sidebar
3. Create a new conversation
4. Start asking questions about server issues

### Troubleshooting

**500 Error on AI Helper endpoints:**
- Check that `api/config.local.php` exists and has the `ai_helper` section
- Check PHP error logs for detailed error messages
- Ensure database connection is working
- Verify OpenAI API key is valid

**"OpenAI API key not configured" error:**
- Make sure you've added `openai_api_key` to `api/config.local.php`
- Restart PHP-FPM or web server after updating config

**Database errors:**
- The auto-migration runs on first use
- If tables still don't exist, check database permissions
- Manually run `database/migrate_ai_helper.sql` if needed

