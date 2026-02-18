<?php
/**
 * Plugin Name: AskWP
 * Plugin URI: https://askwp.dev
 * Description: White-label floating chat widget with RAG, multi-provider LLM support, and configurable contact form.
 * Version: 2.3.3
 * Author: Justus August
 * Author URI: https://askwp.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: askwp
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ASKWP_PLUGIN_VERSION', '2.3.3');
define('ASKWP_PLUGIN_FILE', __FILE__);
define('ASKWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASKWP_PLUGIN_URL', plugin_dir_url(__FILE__));

function askwp_default_system_instructions()
{
    return "You are {bot_name}, the friendly and knowledgeable support assistant for our company. You are part of the team — always speak in the first person plural (\"we\", \"our\", \"us\") when referring to the company.\n\n"
        . "Guidelines:\n"
        . "- You proudly represent our company. Be warm, confident, and helpful.\n"
        . "- Answer questions using the provided context from our website content.\n"
        . "- Speak as a team member who genuinely cares about helping the visitor.\n"
        . "- If you don't have enough information, say so honestly and offer to connect them with our team.\n"
        . "- Be concise (2-6 sentences) unless the visitor asks for more detail.\n"
        . "- Use Markdown structure when it improves clarity: headings, lists (including nested), blockquotes, tables, horizontal rules, links, code blocks, and images with absolute URLs.\n"
        . "- If a visitor attaches an image, analyze it directly and combine that with website context in your answer.\n"
        . "- Never make up facts. Only use information from the provided context.\n"
        . "- Answer in the language the visitor writes in.";
}

function askwp_default_form_fields()
{
    return wp_json_encode(array(
        array('type' => 'text', 'name' => 'full_name', 'label' => 'Full Name', 'required' => true, 'maxlength' => 120, 'step' => 1),
        array('type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true, 'maxlength' => 120, 'step' => 1),
        array('type' => 'textarea', 'name' => 'message', 'label' => 'Message', 'required' => true, 'maxlength' => 2000, 'step' => 1),
    ));
}

function askwp_default_options()
{
    return array(
        // General
        'askwp_enable_widget'          => 1,
        'askwp_bot_name'               => 'Chat Assistant',
        'askwp_default_language'       => 'en',
        'askwp_widget_position'        => 'bottom-right',
        'askwp_enable_favicon'         => 0,
        'askwp_enable_image_attachments' => 0,
        'askwp_show_stream_steps'      => 1,
        // LLM
        'askwp_llm_provider'           => 'openai',
        'askwp_api_key'                => '',
        'askwp_ollama_endpoint'        => 'http://localhost:11434',
        'askwp_model'                  => 'gpt-4o',
        'askwp_max_output_tokens'      => 500,
        'askwp_temperature'            => 0.7,
        // Prompt
        'askwp_system_instructions'    => askwp_default_system_instructions(),
        'askwp_context_pack'           => '',
        'askwp_faq_raw'                => '',
        // RAG
        'askwp_rag_enabled'            => 1,
        'askwp_rag_max_results'        => 4,
        'askwp_rag_max_faq'            => 2,
        'askwp_rag_post_types'         => array('page', 'post'),
        'askwp_rag_snippet_length'     => 300,
        // Form
        'askwp_form_enabled'           => 0,
        'askwp_form_title'             => 'Contact Form',
        'askwp_form_trigger_label'     => 'Open Form',
        'askwp_form_email_to'          => '',
        'askwp_form_email_subject'     => 'New submission from {bot_name}',
        'askwp_form_success_message'   => 'Thank you. Your submission has been sent.',
        'askwp_form_fields'            => askwp_default_form_fields(),
        'askwp_form_steps'             => 1,
        // Appearance
        'askwp_color_primary'          => '#2563eb',
        'askwp_color_secondary'        => '#1e293b',
        'askwp_color_text'             => '#1f2937',
        'askwp_chat_icon'              => 'chat-bubble',
        'askwp_chat_icon_custom_url'   => '',
        'askwp_bot_avatar_url'         => '',
        'askwp_border_radius'          => 16,
        'askwp_theme_mode'            => 'auto',
        'askwp_font_family'            => '',
        'askwp_widget_width'           => 380,
        'askwp_panel_size'             => 'normal',
        'askwp_widget_zindex'          => 999999,
        'askwp_custom_css'             => '',
        'askwp_custom_css_light'       => '',
        'askwp_custom_css_dark'        => '',
        // Suggested questions
        'askwp_suggested_questions'    => '[]',
        // Rate limits
        'askwp_chat_rate_limit_hourly' => 60,
        'askwp_form_rate_limit_daily'  => 10,
    );
}

function askwp_get_option($name, $fallback = null)
{
    $key = (strpos($name, 'askwp_') === 0) ? $name : 'askwp_' . $name;
    $defaults = askwp_default_options();

    if ($fallback === null && isset($defaults[$key])) {
        $fallback = $defaults[$key];
    }

    return get_option($key, $fallback);
}

function askwp_activate_plugin()
{
    $defaults = askwp_default_options();
    if ($defaults['askwp_form_email_to'] === '') {
        $defaults['askwp_form_email_to'] = get_option('admin_email');
    }
    foreach ($defaults as $key => $value) {
        if (get_option($key, null) === null) {
            add_option($key, $value);
        }
    }

    delete_option('askwp_rag_site_index_v1');
    delete_option('askwp_rag_site_index_v2');

    if (!wp_next_scheduled('askwp_rag_refresh_site_index_event')) {
        wp_schedule_event(time() + 300, 'hourly', 'askwp_rag_refresh_site_index_event');
    }
}

function askwp_deactivate_plugin()
{
    wp_clear_scheduled_hook('askwp_rag_refresh_site_index_event');
}

register_activation_hook(__FILE__, 'askwp_activate_plugin');
register_deactivation_hook(__FILE__, 'askwp_deactivate_plugin');

require_once ASKWP_PLUGIN_DIR . 'includes/security.php';
require_once ASKWP_PLUGIN_DIR . 'includes/rag.php';
require_once ASKWP_PLUGIN_DIR . 'includes/llm-provider.php';
require_once ASKWP_PLUGIN_DIR . 'includes/llm-openai.php';
require_once ASKWP_PLUGIN_DIR . 'includes/llm-anthropic.php';
require_once ASKWP_PLUGIN_DIR . 'includes/llm-ollama.php';
require_once ASKWP_PLUGIN_DIR . 'includes/llm-openrouter.php';
require_once ASKWP_PLUGIN_DIR . 'includes/llm-factory.php';
require_once ASKWP_PLUGIN_DIR . 'includes/rest-chat.php';
require_once ASKWP_PLUGIN_DIR . 'includes/rest-form.php';
require_once ASKWP_PLUGIN_DIR . 'includes/stream-chat.php';
require_once ASKWP_PLUGIN_DIR . 'includes/admin-settings.php';

function askwp_add_favicon()
{
    if (!((bool) askwp_get_option('enable_favicon', 0))) {
        return;
    }
    echo '<link rel="icon" type="image/svg+xml" href="' . esc_url(ASKWP_PLUGIN_URL . 'assets/favicon.svg') . '">' . "\n";
}
add_action('wp_head', 'askwp_add_favicon');
add_action('admin_head', 'askwp_add_favicon');

function askwp_enqueue_widget_assets()
{
    if (is_admin()) {
        return;
    }

    if (!((bool) askwp_get_option('enable_widget', 1))) {
        return;
    }

    wp_enqueue_style(
        'askwp-widget',
        ASKWP_PLUGIN_URL . 'assets/widget.css',
        array(),
        ASKWP_PLUGIN_VERSION
    );

    $bot_name = (string) askwp_get_option('bot_name', 'Chat Assistant');
    $form_enabled = (bool) askwp_get_option('form_enabled', 0);
    $form_fields_raw = askwp_get_option('form_fields', askwp_default_form_fields());
    $form_fields = is_string($form_fields_raw) ? json_decode($form_fields_raw, true) : $form_fields_raw;
    if (!is_array($form_fields)) {
        $form_fields = json_decode(askwp_default_form_fields(), true);
    }

    $color_primary   = sanitize_hex_color(askwp_get_option('color_primary', '#2563eb')) ?: '#2563eb';
    $color_secondary = sanitize_hex_color(askwp_get_option('color_secondary', '#1e293b')) ?: '#1e293b';
    $color_text      = sanitize_hex_color(askwp_get_option('color_text', '#1f2937')) ?: '#1f2937';
    $border_radius   = absint(askwp_get_option('border_radius', 16));
    $widget_width    = absint(askwp_get_option('widget_width', 380));
    $panel_size      = sanitize_key((string) askwp_get_option('panel_size', 'normal'));
    if (!in_array($panel_size, array('compact', 'normal', 'large'), true)) {
        $panel_size = 'normal';
    }
    $widget_zindex   = absint(askwp_get_option('widget_zindex', 999999));
    $font_family     = sanitize_text_field(askwp_get_option('font_family', ''));
    $theme_mode      = sanitize_key((string) askwp_get_option('theme_mode', 'auto'));
    if (!in_array($theme_mode, array('auto', 'light', 'dark'), true)) {
        $theme_mode = 'auto';
    }

    $css_vars = ":root {\n"
        . "  --askwp-color-primary: {$color_primary};\n"
        . "  --askwp-color-secondary: {$color_secondary};\n"
        . "  --askwp-color-text: {$color_text};\n"
        . "  --askwp-border-radius: {$border_radius}px;\n"
        . "  --askwp-widget-width: {$widget_width}px;\n"
        . "  --askwp-widget-zindex: {$widget_zindex};\n";
    if ($font_family !== '') {
        $safe_font = str_replace(array(';', '}', '{', '<', '>'), '', $font_family);
        $css_vars .= "  --askwp-font-family: {$safe_font};\n";
    }
    $css_vars .= '}';

    $custom_css = sanitize_textarea_field(askwp_get_option('custom_css', ''));
    $custom_css_light = sanitize_textarea_field(askwp_get_option('custom_css_light', ''));
    $custom_css_dark = sanitize_textarea_field(askwp_get_option('custom_css_dark', ''));
    if ($custom_css !== '') {
        $css_vars .= "\n" . $custom_css;
    }

    wp_add_inline_style('askwp-widget', $css_vars);

    wp_enqueue_script(
        'askwp-widget',
        ASKWP_PLUGIN_URL . 'assets/widget.js',
        array(),
        ASKWP_PLUGIN_VERSION,
        true
    );

    $suggested_raw = askwp_get_option('suggested_questions', '[]');
    $suggested = is_string($suggested_raw) ? json_decode($suggested_raw, true) : $suggested_raw;
    if (!is_array($suggested)) {
        $suggested = array();
    }

    wp_localize_script('askwp-widget', 'ASKWP_CONFIG', array(
        'enabled'      => true,
        'chat_url'     => esc_url_raw(rest_url('askwp/v1/chat')),
        'stream_url'   => admin_url('admin-ajax.php?action=askwp_stream_chat'),
        'stream_progress_url' => admin_url('admin-ajax.php?action=askwp_stream_progress'),
        'show_stream_steps' => (bool) askwp_get_option('show_stream_steps', 1),
        'form_url'     => esc_url_raw(rest_url('askwp/v1/submit_form')),
        'max_messages' => 12,
        'panel_size'   => $panel_size,
        'image_attachments_enabled' => (bool) askwp_get_option('enable_image_attachments', 0),
        'max_image_bytes' => 2 * 1024 * 1024,
        'bot_name'     => $bot_name,
        'theme_mode'   => $theme_mode,
        'custom_css_light' => $custom_css_light,
        'custom_css_dark'  => $custom_css_dark,
        'position'     => askwp_get_option('widget_position', 'bottom-right'),
        'chat_icon'    => askwp_get_option('chat_icon', 'chat-bubble'),
        'chat_icon_custom_url' => esc_url_raw(askwp_get_option('chat_icon_custom_url', '')),
        'bot_avatar_url' => esc_url_raw(askwp_get_option('bot_avatar_url', '')),
        'suggested_questions' => array_values(array_slice($suggested, 0, 8)),
        'form_enabled' => $form_enabled,
        'form_schema'  => $form_enabled ? array(
            'title'           => askwp_get_option('form_title', 'Contact Form'),
            'trigger_label'   => askwp_get_option('form_trigger_label', 'Open Form'),
            'success_message' => askwp_get_option('form_success_message', 'Thank you. Your submission has been sent.'),
            'fields'          => $form_fields,
            'steps'           => (int) askwp_get_option('form_steps', 1),
        ) : null,
        'strings' => array(
            'toggle_label' => $bot_name,
            'title'        => $bot_name,
            'placeholder'  => 'Type your message...',
            'send'         => 'Send',
            'open_form'    => askwp_get_option('form_trigger_label', 'Open Form'),
            'loading'      => 'Loading response...',
            'error'        => 'The chat is currently unavailable. Please try again later.',
            'error_offline' => 'You appear to be offline. Please reconnect and retry.',
            'error_network' => 'We could not reach the server. Please try again.',
            'error_server' => 'The assistant is temporarily unavailable. Please retry.',
            'retry'        => 'Retry',
            'retry_unavailable' => 'Retry is no longer available for that message.',
            'connection_online' => 'Online',
            'connection_connecting' => 'Connecting',
            'connection_offline' => 'Offline',
            'connection_issue' => 'Issue detected',
            'assistant_message' => 'Assistant message',
            'user_message' => 'Your message',
            'image_attached' => 'Image attached',
            'attach_image' => 'Attach image',
            'remove_image' => 'Remove image',
            'image_too_large' => 'Image is too large. Max size is 2MB.',
            'image_invalid' => 'Only PNG, JPEG, WEBP, and GIF images are supported.',
            'copy_message' => 'Copy message',
            'copied'       => 'Copied',
            'reset'        => 'Reset',
            'close'        => 'Close',
        ),
    ));
}
add_action('wp_enqueue_scripts', 'askwp_enqueue_widget_assets');
