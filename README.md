# AskWP

AI-powered chat widget for WordPress. RAG-powered search pulls answers directly from your pages, posts, and FAQs — no training, no vector databases, no external dependencies.

## Features

- **RAG-powered context** — automatically searches your WordPress content on every message
- **Multi-provider LLM** — OpenAI, Anthropic, OpenRouter, or self-hosted Ollama
- **Visual form builder** — drag-and-drop contact forms inside the chat widget
- **Full customization** — colors, icons, fonts, position, custom CSS via a 7-tab admin panel
- **Security built in** — prompt injection detection, origin validation, configurable rate limits
- **Zero dependencies** — pure PHP + vanilla JS/CSS, no npm, no build step, no frameworks

## Installation

### From GitHub

1. Download `askwp.zip` from the [latest release](https://github.com/justusaugust/askwp-plugin/releases/latest)
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP and activate

### Manual

1. Clone or download this repo
2. Copy the contents to `wp-content/plugins/askwp/`
3. Activate in WordPress admin

## Setup

1. Go to **Settings > AskWP**
2. Choose your LLM provider and enter your API key
3. Write a system prompt (or use the default)
4. Customize appearance, RAG settings, forms, and rate limits
5. The chat widget appears on your site automatically

## File Structure

```
askwp/
├── askwp.php                   # Bootstrap, defaults, enqueue
├── includes/
│   ├── admin-settings.php      # 7-tab settings page
│   ├── security.php            # Origin check, rate limiting
│   ├── rag.php                 # Page resolution, search, FAQ, context assembly
│   ├── rest-chat.php           # POST /askwp/v1/chat
│   ├── rest-form.php           # POST /askwp/v1/submit_form
│   ├── llm-provider.php        # Abstract base class
│   ├── llm-openai.php          # OpenAI provider
│   ├── llm-anthropic.php       # Anthropic provider
│   ├── llm-ollama.php          # Ollama provider
│   └── llm-factory.php         # Provider instantiation
├── assets/
│   ├── widget.js               # Frontend chat widget (vanilla ES5 IIFE)
│   ├── widget.css              # Widget styles (CSS custom properties)
│   ├── admin-form-builder.js   # Visual field editor
│   └── admin.css               # Admin styles
└── readme.txt                  # WordPress.org plugin directory metadata
```

## How RAG Works

1. Visitor sends a message
2. AskWP extracts keywords from the latest message
3. Runs a WordPress search query across configured post types
4. Ranks results by relevance, creates text snippets
5. Injects snippets + FAQ matches + current page context into the system prompt
6. LLM responds using your actual content as ground truth

No embeddings, no vector DB, no training. Just WordPress search, intelligently applied.

## Configuration

All settings are stored in `wp_options` as `askwp_*` keys. Every site gets its own independent configuration — the plugin code is shared, settings are per-site.

Key settings: LLM provider, API key, model, system prompt, temperature, max tokens, RAG post types, snippet length, FAQ entries, form fields, colors, widget position, rate limits.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Related Repositories

| Repo | Description |
|------|-------------|
| [askwp-plugin](https://github.com/justusaugust/askwp-plugin) | This repo — the open-source plugin |
| [askwp-site](https://github.com/justusaugust/askwp-site) | Marketing site at askwp.dev (private) |

## Local Development

This repo is designed to be symlinked into a WordPress installation:

```bash
ln -s /path/to/askwp-plugin /path/to/wordpress/wp-content/plugins/askwp
```

PHP changes apply immediately. JS/CSS changes require a hard refresh.

## License

GPLv2 or later. See [LICENSE](LICENSE) for details.
