# CLAUDE.md — askwp-plugin

This is the **open-source AskWP plugin** repo. It contains only the WordPress plugin code — no site theme, no content, no configuration.

## Scope

This repo = plugin functionality. If a request is about the marketing site (theme, SEO, page content, site design), work in `askwp-site/` instead (see parent CLAUDE.md).

Changes here are immediately visible on the marketing site at `http://localhost:8883/` via symlink.

## File Structure

```
askwp-plugin/
├── askwp.php                   # Bootstrap, defaults, enqueue, ASKWP_CONFIG
├── includes/
│   ├── admin-settings.php      # 7-tab settings page
│   ├── security.php            # Origin check, rate limiting, text utilities
│   ├── rag.php                 # Page resolution, search, FAQ, context assembly
│   ├── rest-chat.php           # Chat endpoint (POST /askwp/v1/chat)
│   ├── rest-form.php           # Form endpoint (POST /askwp/v1/submit_form)
│   ├── llm-provider.php        # Abstract LLM provider base class
│   ├── llm-openai.php          # OpenAI Responses API provider
│   ├── llm-anthropic.php       # Anthropic Messages API provider
│   ├── llm-ollama.php          # Ollama (local) provider
│   └── llm-factory.php         # Provider instantiation
├── assets/
│   ├── widget.js               # Frontend widget (vanilla ES5 IIFE)
│   ├── widget.css              # Widget styles (CSS custom properties)
│   ├── admin-form-builder.js   # Visual field editor for admin
│   └── admin.css               # Admin page styles
├── readme.txt                  # WordPress.org plugin directory metadata
├── LICENSE                     # GPLv2
└── README.md
```

## Architecture

### Chat request lifecycle

1. **Frontend** (`widget.js`): User sends message → builds `{messages, page_url, page_title}` → POST to `/askwp/v1/chat`
2. **Validation** (`rest-chat.php`): Origin check → rate limit → payload size → message sanitization (last 12, user max 1500 chars, assistant max 2000 chars)
3. **Deterministic guards** (`rest-chat.php`): Injection detection + status ping handling
4. **RAG context assembly** (`rag.php`): Resolve current page → WordPress search (configurable post types) → FAQ keyword matching → rank by term hits → assemble context blocks
5. **LLM provider** (`llm-factory.php`): Instantiate configured provider. Tool support for search_website + get_page when provider supports tools.
6. **System prompt** (`rest-chat.php`): Admin instructions (with `{bot_name}` resolved) + tool hints + context pack + FAQ highlights + page title + RAG context
7. **Response**: `{reply, sources, action, usage}`

### Key design constraint

`askwp_rag_build_context()` extracts search terms from the *latest user message only*. Full chat history goes to the LLM for conversational context, but RAG retrieval is based solely on the newest message.

### Token logging

`askwp_log_token_usage()` appends to `askwp_token_log` WP option (capped at 500 entries). Each entry: `{ts, model, input, output, total}`.

## Code Conventions

- **PHP**: WordPress coding standards. All functions prefixed `askwp_`. Options stored as `askwp_*` in `wp_options`.
- **JS**: Vanilla ES5 IIFE, no modules/imports. All DOM classes prefixed `askwp-`. State in a single `state` object. localStorage keys: `askwp_*_v1`.
- **CSS**: Custom properties prefixed `--askwp-`. No preprocessor. Inherits site font by default.
- **No build step**: Edit `.js`/`.css`/`.php` directly. No transpilation, bundling, or minification.
- **LLM providers**: Abstract base `ASKWP_LLM_Provider`, concrete implementations per provider. Factory pattern in `llm-factory.php`.
- **License**: GPLv2 or later (required for WordPress.org).

## Configuration is NOT in this repo

All `askwp_*` options (API key, model, colors, system prompt, form fields, rate limits) live in each site's `wp_options` database. This repo has no config files — it ships sensible defaults in `askwp.php` that are overridden per-site via the admin panel.

## Git

```bash
git add -A && git commit -m "..." && git push
```

GitHub releases with ZIP:
```bash
rsync -a --delete --exclude-from=.distignore ./ ../askwp-dist/
(cd ../askwp-dist && zip -r ../askwp.zip .)
rm -rf ../askwp-dist
gh release create vX.Y.Z ../askwp.zip --repo justusaugust/askwp-plugin --title "AskWP vX.Y.Z"
```
