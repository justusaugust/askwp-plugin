=== AskWP ===
Contributors: askwp
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.2.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

White-label floating chat widget with RAG-powered AI responses, configurable form system, and multi-provider LLM support.

== Description ==
AskWP provides:

* Floating chat widget on all frontend pages (configurable position, colors, icon)
* Multi-provider LLM support: OpenAI, Anthropic, OpenRouter, Ollama (local)
* REST API endpoints:
  * POST /wp-json/askwp/v1/chat
  * POST /wp-json/askwp/v1/submit_form
* RAG without vector DB:
  * Current page context (url_to_postid / page mapping)
  * WordPress search snippets (configurable post types)
  * FAQ keyword matching (admin-defined Q/A pairs)
  * Optional context pack (always injected into system prompt)
* Configurable form system with visual field editor
* Zero server-side chat storage
* 7-tab admin settings page for full customization

= Third-Party Services =

This plugin sends chat messages to the LLM provider you configure in Settings > AskWP. No data is sent until you enter an API key and a visitor uses the chat widget. Supported providers:

* [OpenAI](https://openai.com/) — [Terms of Use](https://openai.com/policies/terms-of-use) | [Privacy Policy](https://openai.com/policies/privacy-policy)
* [Anthropic](https://www.anthropic.com/) — [Terms of Service](https://www.anthropic.com/policies/terms) | [Privacy Policy](https://www.anthropic.com/policies/privacy)
* [OpenRouter](https://openrouter.ai/) — [Terms of Service](https://openrouter.ai/terms) | [Privacy Policy](https://openrouter.ai/privacy)
* [Ollama](https://ollama.com/) — Self-hosted, no data leaves your server

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/ or install the ZIP via WordPress Admin.
2. Activate "AskWP".
3. Open Settings > AskWP.
4. Configure your LLM provider and API key.
5. Customize system instructions, appearance, and form fields.
6. Ensure widget is enabled.

== Frequently Asked Questions ==
= Does this plugin store chat logs? =
No. Chat messages are only kept in browser localStorage. The server does not persist conversations.

= Which LLM providers are supported? =
OpenAI (default), Anthropic (Claude), OpenRouter, and Ollama (local/self-hosted).

= Is form data sent to the LLM? =
No. Form submissions are only sent to /submit_form and emailed via wp_mail.

== Changelog ==
= 1.0.0 =
* Initial release.
