# AGENTS.md — askwp-plugin

This file guides coding agents working in this repository.

## Repository Identity

This is the open-source **AskWP WordPress plugin** codebase.

- Contains plugin code only
- Does **not** contain marketing site theme/content/config
- Plugin changes are visible on the local marketing site via symlink (`http://localhost:8883/`)

## Scope Guardrails

Work in this repo for:
- WordPress plugin PHP logic
- Plugin frontend widget/admin JS and CSS
- REST endpoints and provider integrations

Do **not** implement marketing site changes here. If the request is about site pages/SEO/theme/content/design, use `askwp-site/` instead.

## High-Level Structure

- `askwp.php`: plugin bootstrap, defaults, enqueue logic, `ASKWP_CONFIG`
- `includes/admin-settings.php`: settings UI (includes Usage dashboard tab)
- `includes/security.php`: origin checks, rate limiting, text utilities
- `includes/rag.php`: page resolution, search, FAQ matching, context assembly
- `includes/rest-chat.php`: `POST /askwp/v1/chat`
- `includes/rest-form.php`: `POST /askwp/v1/submit_form`
- `includes/stream-chat.php`: SSE streaming chat endpoint via admin-ajax
- `includes/llm-provider.php`: abstract provider base
- `includes/llm-openai.php`: OpenAI Responses provider
- `includes/llm-anthropic.php`: Anthropic Messages provider
- `includes/llm-openrouter.php`: OpenRouter Chat Completions provider
- `includes/llm-ollama.php`: Ollama provider
- `includes/llm-factory.php`: provider instantiation/factory
- `assets/widget.js`: frontend chat widget (vanilla ES5 IIFE)
- `assets/widget.css`: widget styles
- `assets/admin-form-builder.js`: admin form field builder
- `assets/admin-usage-dashboard.js`: admin token/cost usage dashboard
- `assets/admin.css`: admin styling
- `readme.txt`: WordPress.org metadata

## Request Lifecycle (Chat)

1. `assets/widget.js` posts `{messages, page_url, page_title}` to `/askwp/v1/chat`
2. `includes/rest-chat.php` validates origin, rate limits, payload, message lengths
3. `includes/rest-chat.php` applies deterministic guards (injection/status handling)
4. `includes/rag.php` resolves page + search + FAQ matching + ranked context blocks
5. `includes/llm-factory.php` selects provider; tools are provider-dependent
6. `includes/rest-chat.php` builds final system prompt (admin prompt + context + page info)
7. API returns `{reply, sources, action, usage}`

Important retrieval rule:
- `askwp_rag_build_context()` uses the **latest user message only** for retrieval terms.

## Coding Conventions

### PHP
- Follow WordPress coding standards
- Prefix plugin functions with `askwp_`
- Store options as `askwp_*` in `wp_options`

### JavaScript
- Vanilla ES5 IIFE style (no modules/imports/build tooling)
- Prefix DOM classes with `askwp-`
- Keep frontend state in a single `state` object when extending widget logic
- Use existing `askwp_*_v1` localStorage patterns

### CSS
- Prefix custom properties with `--askwp-`
- No preprocessor; edit CSS directly
- Preserve host-site font inheritance unless the feature requires explicit override

### Architecture
- Preserve `ASKWP_LLM_Provider` abstraction and factory pattern
- Keep providers isolated in their own implementation files

## Configuration Model

Configuration is runtime/site-specific in WordPress DB options (`askwp_*` in `wp_options`).
Do not introduce repo-based config for settings already managed in admin.

## Operational Notes

- No build step: edit `.php`, `.js`, `.css` directly
- Keep changes small, focused, and backwards-compatible
- Maintain REST endpoint compatibility unless explicitly requested
- License remains GPLv2-or-later compatible

## Current Repo Notes

- Streaming chat is implemented end-to-end (`stream_url` in config + `includes/stream-chat.php` + streaming logic in `assets/widget.js`).
- Multi-provider support currently includes OpenAI, Anthropic, OpenRouter, and Ollama.
- Documentation drift exists: some docs still say "7-tab" settings, while code includes a Usage tab.
- Origin validation logic is duplicated in `includes/security.php` and `includes/stream-chat.php`; keep behavior aligned when changing either path.
- No automated test suite is currently present in this repository.

## Agent Working Checklist

Before editing:
- Confirm request belongs to plugin scope
- Identify affected layer (widget, REST, RAG, provider, admin)

When editing:
- Reuse existing naming and prefix conventions
- Avoid broad refactors unless required by task
- Preserve retrieval behavior unless asked to change it

Before finishing:
- Validate changed files for syntax/regression risk
- Summarize what changed and why
- Call out any follow-up testing needed
