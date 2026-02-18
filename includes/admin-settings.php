<?php

if (!defined('ABSPATH')) {
    exit;
}

function askwp_register_settings_page()
{
    add_options_page(
        'AskWP',
        'AskWP',
        'manage_options',
        'askwp-settings',
        'askwp_render_settings_page'
    );
}
add_action('admin_menu', 'askwp_register_settings_page');

function askwp_admin_settings_by_tab()
{
    return array(
        'general' => array(
            'askwp_enable_widget'          => array('type' => 'boolean', 'sanitize' => 'askwp_sanitize_checkbox'),
            'askwp_bot_name'               => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_default_language'       => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_widget_position'        => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_enable_favicon'         => array('type' => 'boolean', 'sanitize' => 'askwp_sanitize_checkbox'),
            'askwp_enable_image_attachments' => array('type' => 'boolean', 'sanitize' => 'askwp_sanitize_checkbox'),
            'askwp_show_stream_steps'      => array('type' => 'boolean', 'sanitize' => 'askwp_sanitize_checkbox'),
            'askwp_suggested_questions'    => array('type' => 'string',  'sanitize' => 'askwp_sanitize_suggested_questions'),
        ),
        'llm' => array(
            'askwp_llm_provider'      => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_api_key'           => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_ollama_endpoint'   => array('type' => 'string',  'sanitize' => 'esc_url_raw'),
            'askwp_model'             => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_max_output_tokens' => array('type' => 'integer', 'sanitize' => 'absint'),
            'askwp_temperature'       => array('type' => 'string',  'sanitize' => 'askwp_sanitize_temperature'),
        ),
        'prompt' => array(
            'askwp_system_instructions' => array('type' => 'string', 'sanitize' => 'sanitize_textarea_field'),
            'askwp_context_pack'        => array('type' => 'string', 'sanitize' => 'sanitize_textarea_field'),
            'askwp_faq_raw'             => array('type' => 'string', 'sanitize' => 'sanitize_textarea_field'),
        ),
        'rag' => array(
            'askwp_rag_enabled'        => array('type' => 'boolean', 'sanitize' => 'askwp_sanitize_checkbox'),
            'askwp_rag_max_results'    => array('type' => 'integer', 'sanitize' => 'absint'),
            'askwp_rag_max_faq'        => array('type' => 'integer', 'sanitize' => 'absint'),
            'askwp_rag_post_types'     => array('type' => 'array',   'sanitize' => 'askwp_sanitize_post_types'),
            'askwp_rag_snippet_length' => array('type' => 'integer', 'sanitize' => 'absint'),
        ),
        'form' => array(
            'askwp_form_enabled'         => array('type' => 'boolean', 'sanitize' => 'askwp_sanitize_checkbox'),
            'askwp_form_title'           => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_form_trigger_label'   => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_form_email_to'        => array('type' => 'string',  'sanitize' => 'askwp_sanitize_email_field'),
            'askwp_form_email_subject'   => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_form_success_message' => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_form_fields'          => array('type' => 'string',  'sanitize' => 'askwp_sanitize_form_fields_json'),
            'askwp_form_steps'           => array('type' => 'integer', 'sanitize' => 'absint'),
        ),
        'appearance' => array(
            'askwp_color_primary'        => array('type' => 'string',  'sanitize' => 'sanitize_hex_color'),
            'askwp_color_secondary'      => array('type' => 'string',  'sanitize' => 'sanitize_hex_color'),
            'askwp_color_text'           => array('type' => 'string',  'sanitize' => 'sanitize_hex_color'),
            'askwp_theme_mode'           => array('type' => 'string',  'sanitize' => 'askwp_sanitize_theme_mode'),
            'askwp_chat_icon'            => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_chat_icon_custom_url' => array('type' => 'string',  'sanitize' => 'esc_url_raw'),
            'askwp_bot_avatar_url'       => array('type' => 'string',  'sanitize' => 'esc_url_raw'),
            'askwp_border_radius'        => array('type' => 'integer', 'sanitize' => 'absint'),
            'askwp_font_family'          => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'askwp_widget_width'         => array('type' => 'integer', 'sanitize' => 'absint'),
            'askwp_panel_size'           => array('type' => 'string',  'sanitize' => 'askwp_sanitize_panel_size'),
            'askwp_widget_zindex'        => array('type' => 'integer', 'sanitize' => 'absint'),
            'askwp_custom_css'           => array('type' => 'string',  'sanitize' => 'sanitize_textarea_field'),
            'askwp_custom_css_light'     => array('type' => 'string',  'sanitize' => 'sanitize_textarea_field'),
            'askwp_custom_css_dark'      => array('type' => 'string',  'sanitize' => 'sanitize_textarea_field'),
        ),
        'limits' => array(
            'askwp_chat_rate_limit_hourly' => array('type' => 'integer', 'sanitize' => 'askwp_sanitize_rate_limit'),
            'askwp_form_rate_limit_daily'  => array('type' => 'integer', 'sanitize' => 'askwp_sanitize_rate_limit'),
        ),
    );
}

function askwp_register_settings()
{
    $defaults = askwp_default_options();
    foreach (askwp_admin_settings_by_tab() as $tab => $fields) {
        $group = 'askwp_' . $tab . '_group';
        foreach ($fields as $key => $config) {
            register_setting($group, $key, array(
                'type'              => $config['type'],
                'sanitize_callback' => $config['sanitize'],
                'default'           => isset($defaults[$key]) ? $defaults[$key] : '',
            ));
        }
    }
}
add_action('admin_init', 'askwp_register_settings');

function askwp_sanitize_checkbox($value)
{
    return empty($value) ? 0 : 1;
}

function askwp_sanitize_temperature($value)
{
    $temp = (float) $value;
    return max(0.0, min(2.0, $temp));
}

function askwp_sanitize_rate_limit($value)
{
    $limit = absint($value);
    return ($limit > 0) ? $limit : 10;
}

function askwp_sanitize_theme_mode($value)
{
    $mode = sanitize_key((string) $value);
    if (!in_array($mode, array('auto', 'light', 'dark'), true)) {
        return 'auto';
    }
    return $mode;
}

function askwp_sanitize_panel_size($value)
{
    $size = sanitize_key((string) $value);
    if (!in_array($size, array('compact', 'normal', 'large'), true)) {
        return 'normal';
    }
    return $size;
}

function askwp_sanitize_email_field($value)
{
    $email = sanitize_email((string) $value);
    if ($email === '' || !is_email($email)) {
        return get_option('admin_email');
    }
    return $email;
}

function askwp_sanitize_post_types($value)
{
    if (!is_array($value)) {
        return array('page', 'post');
    }
    return array_map('sanitize_key', $value);
}

function askwp_sanitize_suggested_questions($value)
{
    $text = sanitize_textarea_field((string) $value);
    $lines = array_filter(array_map('trim', explode("\n", $text)));
    $questions = array();
    foreach (array_slice($lines, 0, 8) as $line) {
        $q = askwp_text_substr(sanitize_text_field($line), 150);
        if ($q !== '') {
            $questions[] = $q;
        }
    }
    return wp_json_encode(array_values($questions));
}

function askwp_suggested_question_presets()
{
    return array(
        'general'   => array(
            'label'     => 'General Business',
            'questions' => array(
                'What services do you offer?',
                'How can I contact your team?',
                'What are your business hours?',
                'Where are you located?',
            ),
        ),
        'saas'      => array(
            'label'     => 'SaaS',
            'questions' => array(
                'How does your product work?',
                'What pricing plans do you offer?',
                'Do you offer a free trial?',
                'How do I get started?',
            ),
        ),
        'ecommerce' => array(
            'label'     => 'E-commerce',
            'questions' => array(
                'What is your return policy?',
                'How long does shipping take?',
                'Do you ship internationally?',
                'How can I track my order?',
            ),
        ),
        'agency'    => array(
            'label'     => 'Agency',
            'questions' => array(
                'What kind of projects do you take on?',
                'Can I see examples of your work?',
                'What is your typical process?',
                'How do I request a quote?',
            ),
        ),
    );
}

function askwp_sanitize_form_fields_json($value)
{
    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) {
        return askwp_default_form_fields();
    }
    return wp_json_encode($decoded);
}

function askwp_maybe_handle_test_email()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!isset($_POST['askwp_send_test_email'])) {
        return;
    }
    check_admin_referer('askwp_send_test_email_action', 'askwp_test_email_nonce');

    $recipient = askwp_get_option('form_email_to', get_option('admin_email'));
    $subject = 'AskWP Test Email';
    $body = "This is a test email from the AskWP plugin.\n\nTime: " . current_time('mysql');
    $sent = wp_mail($recipient, $subject, $body, array('Content-Type: text/plain; charset=UTF-8'));

    if ($sent) {
        add_settings_error('askwp_messages', 'askwp_test_mail_ok', 'Test email sent successfully.', 'updated');
    } else {
        add_settings_error('askwp_messages', 'askwp_test_mail_failed', 'Test email could not be sent.', 'error');
    }
}

function askwp_enqueue_admin_assets($hook)
{
    if ($hook !== 'settings_page_askwp-settings') {
        return;
    }

    wp_enqueue_style(
        'askwp-admin',
        ASKWP_PLUGIN_URL . 'assets/admin.css',
        array(),
        ASKWP_PLUGIN_VERSION
    );

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation only, no data processing.
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

    if ($tab === 'appearance') {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();
    }

    if ($tab === 'form') {
        wp_enqueue_script(
            'askwp-form-builder',
            ASKWP_PLUGIN_URL . 'assets/admin-form-builder.js',
            array(),
            ASKWP_PLUGIN_VERSION,
            true
        );
    }

    if ($tab === 'usage') {
        wp_enqueue_script(
            'askwp-usage-dashboard',
            ASKWP_PLUGIN_URL . 'assets/admin-usage-dashboard.js',
            array(),
            ASKWP_PLUGIN_VERSION,
            true
        );

        $log = get_option('askwp_token_log', array());
        if (!is_array($log)) {
            $log = array();
        }

        wp_localize_script('askwp-usage-dashboard', 'ASKWP_USAGE_DATA', array(
            'log'     => $log,
            'pricing' => askwp_token_pricing_table(),
        ));
    }

    // Inline script for tab-specific behavior.
    wp_add_inline_script('jquery', '
        jQuery(function($) {
            // Color pickers
            $(".askwp-color-field").wpColorPicker && $(".askwp-color-field").wpColorPicker();

            // Media uploader
            $(".askwp-media-upload").on("click", function(e) {
                e.preventDefault();
                var btn = $(this);
                var target = btn.data("target");
                var preview = btn.data("preview");
                var frame = wp.media({ title: "Select Image", multiple: false, library: { type: "image" } });
                frame.on("select", function() {
                    var attachment = frame.state().get("selection").first().toJSON();
                    $("#" + target).val(attachment.url);
                    if (preview) { $("#" + preview).attr("src", attachment.url).show(); }
                });
                frame.open();
            });

            // Provider toggle
            $("input[name=askwp_llm_provider]").on("change", function() {
                var val = $(this).val();
                $(".askwp-provider-field").hide();
                $(".askwp-provider-" + val).show();
                if (val === "openai" || val === "anthropic" || val === "openrouter") { $(".askwp-provider-api-key").show(); }
            }).filter(":checked").trigger("change");
        });
    ');
}
add_action('admin_enqueue_scripts', 'askwp_enqueue_admin_assets');

function askwp_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    askwp_maybe_handle_test_email();

    $tabs = array(
        'general'    => 'General',
        'llm'        => 'LLM Provider',
        'prompt'     => 'System Prompt',
        'rag'        => 'RAG',
        'form'       => 'Form Builder',
        'appearance' => 'Appearance',
        'limits'     => 'Rate Limits',
        'usage'      => 'Usage',
    );

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation only, no data processing.
    $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
    if (!isset($tabs[$current_tab])) {
        $current_tab = 'general';
    }

    settings_errors('askwp_messages');
    ?>
    <div class="wrap">
        <h1>AskWP</h1>

        <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $slug => $label) : ?>
                <a href="<?php echo esc_url(add_query_arg('tab', $slug, admin_url('options-general.php?page=askwp-settings'))); ?>"
                   class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($current_tab === 'usage') : ?>
            <?php askwp_render_tab_usage(); ?>
        <?php else : ?>
        <form method="post" action="options.php">
            <?php settings_fields('askwp_' . $current_tab . '_group'); ?>
            <?php call_user_func('askwp_render_tab_' . $current_tab); ?>
            <?php submit_button('Save Settings'); ?>
        </form>
        <?php endif; ?>

        <?php if ($current_tab === 'limits') : ?>
            <hr />
            <form method="post">
                <?php wp_nonce_field('askwp_send_test_email_action', 'askwp_test_email_nonce'); ?>
                <input type="hidden" name="askwp_send_test_email" value="1" />
                <?php submit_button('Send Test Email', 'secondary', 'submit', false); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function askwp_render_tab_general()
{
    $enable_widget = (int) askwp_get_option('enable_widget', 1);
    $enable_favicon = (int) askwp_get_option('enable_favicon', 0);
    $enable_image_attachments = (int) askwp_get_option('enable_image_attachments', 0);
    $show_stream_steps = (int) askwp_get_option('show_stream_steps', 1);
    $bot_name = (string) askwp_get_option('bot_name', 'Chat Assistant');
    $language = (string) askwp_get_option('default_language', 'en');
    $position = (string) askwp_get_option('widget_position', 'bottom-right');
    ?>
    <table class="form-table">
        <tr>
            <th><label for="askwp_enable_widget">Enable Widget</label></th>
            <td>
                <input type="checkbox" id="askwp_enable_widget" name="askwp_enable_widget" value="1" <?php checked(1, $enable_widget); ?> />
                <p class="description">Show the floating chat widget on all frontend pages.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_enable_favicon">Override Favicon</label></th>
            <td>
                <input type="checkbox" id="askwp_enable_favicon" name="askwp_enable_favicon" value="1" <?php checked(1, $enable_favicon); ?> />
                <p class="description">Replace the site favicon with the AskWP icon.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_enable_image_attachments">Allow Image Attachments</label></th>
            <td>
                <input type="checkbox" id="askwp_enable_image_attachments" name="askwp_enable_image_attachments" value="1" <?php checked(1, $enable_image_attachments); ?> />
                <p class="description">Allow visitors to attach one image (max 2MB) with each chat message. Works with OpenAI, Anthropic, and OpenRouter vision-capable models.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_show_stream_steps">Show Retrieval Steps</label></th>
            <td>
                <input type="checkbox" id="askwp_show_stream_steps" name="askwp_show_stream_steps" value="1" <?php checked(1, $show_stream_steps); ?> />
                <p class="description">Show live retrieval status steps before the assistant answer starts streaming.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_bot_name">Bot Name</label></th>
            <td>
                <input type="text" id="askwp_bot_name" name="askwp_bot_name" value="<?php echo esc_attr($bot_name); ?>" class="regular-text" />
                <p class="description">Displayed in the widget header and used as <code>{bot_name}</code> in system instructions.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_default_language">Default Language</label></th>
            <td>
                <select id="askwp_default_language" name="askwp_default_language">
                    <?php foreach (array('en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano') as $code => $label) : ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($language, $code); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_widget_position">Widget Position</label></th>
            <td>
                <select id="askwp_widget_position" name="askwp_widget_position">
                    <option value="bottom-right" <?php selected($position, 'bottom-right'); ?>>Bottom Right</option>
                    <option value="bottom-left" <?php selected($position, 'bottom-left'); ?>>Bottom Left</option>
                </select>
            </td>
        </tr>
        <?php
        $sq_raw = askwp_get_option('suggested_questions', '[]');
        $sq_arr = is_string($sq_raw) ? json_decode($sq_raw, true) : $sq_raw;
        if (!is_array($sq_arr)) { $sq_arr = array(); }
        $sq_text = implode("\n", $sq_arr);
        $presets = askwp_suggested_question_presets();
        ?>
        <tr>
            <th><label for="askwp_suggested_questions">Suggested Questions</label></th>
            <td>
                <textarea id="askwp_suggested_questions" name="askwp_suggested_questions" rows="5" class="large-text" placeholder="What services do you offer?&#10;How can I contact your team?"><?php echo esc_textarea($sq_text); ?></textarea>
                <p class="description">One question per line (max 8, 150 chars each). Shown as clickable chips when the chat opens.</p>
                <p style="margin-top:8px;">
                    <label for="askwp_sq_preset"><strong>Load Preset:</strong></label>
                    <select id="askwp_sq_preset" style="margin-left:6px;">
                        <option value="">— Select —</option>
                        <?php foreach ($presets as $key => $preset) : ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($preset['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <script>
                (function() {
                    var presets = <?php echo wp_json_encode(array_map(function($p) { return $p['questions']; }, $presets)); ?>;
                    var sel = document.getElementById('askwp_sq_preset');
                    var ta = document.getElementById('askwp_suggested_questions');
                    if (sel && ta) {
                        sel.addEventListener('change', function() {
                            var key = sel.value;
                            if (key && presets[key]) {
                                ta.value = presets[key].join('\n');
                            }
                            sel.value = '';
                        });
                    }
                })();
                </script>
            </td>
        </tr>
    </table>
    <?php
}

function askwp_render_tab_llm()
{
    $provider = (string) askwp_get_option('llm_provider', 'openai');
    $api_key = (string) askwp_get_option('api_key', '');
    $ollama_endpoint = (string) askwp_get_option('ollama_endpoint', 'http://localhost:11434');
    $model = (string) askwp_get_option('model', 'gpt-4o');
    $max_tokens = (int) askwp_get_option('max_output_tokens', 500);
    $temperature = (float) askwp_get_option('temperature', 0.7);
    ?>
    <table class="form-table">
        <tr>
            <th>LLM Provider</th>
            <td>
                <fieldset>
                    <label><input type="radio" name="askwp_llm_provider" value="openai" <?php checked($provider, 'openai'); ?> /> OpenAI</label><br>
                    <label><input type="radio" name="askwp_llm_provider" value="anthropic" <?php checked($provider, 'anthropic'); ?> /> Anthropic</label><br>
                    <label><input type="radio" name="askwp_llm_provider" value="openrouter" <?php checked($provider, 'openrouter'); ?> /> OpenRouter</label><br>
                    <label><input type="radio" name="askwp_llm_provider" value="ollama" <?php checked($provider, 'ollama'); ?> /> Ollama (local)</label>
                </fieldset>
            </td>
        </tr>
        <tr class="askwp-provider-field askwp-provider-api-key askwp-provider-openai askwp-provider-anthropic askwp-provider-openrouter">
            <th><label for="askwp_api_key">API Key</label></th>
            <td>
                <input type="password" id="askwp_api_key" name="askwp_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" autocomplete="off" />
                <p class="description">Your OpenAI, Anthropic, or OpenRouter API key. Stored in WordPress options only.</p>
            </td>
        </tr>
        <tr class="askwp-provider-field askwp-provider-ollama">
            <th><label for="askwp_ollama_endpoint">Ollama Endpoint</label></th>
            <td>
                <input type="url" id="askwp_ollama_endpoint" name="askwp_ollama_endpoint" value="<?php echo esc_attr($ollama_endpoint); ?>" class="regular-text" />
                <p class="description">Default: <code>http://localhost:11434</code></p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_model">Model</label></th>
            <td>
                <input type="text" id="askwp_model" name="askwp_model" value="<?php echo esc_attr($model); ?>" class="regular-text" />
                <p class="description">e.g. <code>gpt-5</code>, <code>claude-sonnet-4-5-20250929</code>, <code>openai/gpt-5</code>, <code>llama3</code></p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_max_output_tokens">Max Output Tokens</label></th>
            <td>
                <input type="number" id="askwp_max_output_tokens" name="askwp_max_output_tokens" value="<?php echo esc_attr($max_tokens); ?>" min="120" max="4000" step="10" />
            </td>
        </tr>
        <tr>
            <th><label for="askwp_temperature">Temperature</label></th>
            <td>
                <input type="number" id="askwp_temperature" name="askwp_temperature" value="<?php echo esc_attr($temperature); ?>" min="0" max="2" step="0.1" />
                <p class="description">0 = deterministic, 1 = creative, 2 = very random</p>
            </td>
        </tr>
    </table>
    <?php
}

function askwp_render_tab_prompt()
{
    $system_instructions = (string) askwp_get_option('system_instructions', askwp_default_system_instructions());
    $context_pack = (string) askwp_get_option('context_pack', '');
    $faq_raw = (string) askwp_get_option('faq_raw', '');
    ?>
    <table class="form-table">
        <tr>
            <th><label for="askwp_system_instructions">System Instructions</label></th>
            <td>
                <textarea id="askwp_system_instructions" name="askwp_system_instructions" rows="12" class="large-text code"><?php echo esc_textarea($system_instructions); ?></textarea>
                <p class="description">Use <code>{bot_name}</code> as a placeholder for the bot name. These instructions are sent as the system prompt with every chat request.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_context_pack">Context Pack</label></th>
            <td>
                <textarea id="askwp_context_pack" name="askwp_context_pack" rows="8" class="large-text code"><?php echo esc_textarea($context_pack); ?></textarea>
                <p class="description">Always-active background knowledge. Company info, terminology, key facts.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_faq_raw">FAQ Pairs</label></th>
            <td>
                <textarea id="askwp_faq_raw" name="askwp_faq_raw" rows="10" class="large-text code"><?php echo esc_textarea($faq_raw); ?></textarea>
                <p class="description">Format: <code>Q: question\nA: answer</code> — separate pairs with a blank line.</p>
            </td>
        </tr>
    </table>
    <?php
}

function askwp_render_tab_rag()
{
    $rag_enabled = (int) askwp_get_option('rag_enabled', 1);
    $max_results = (int) askwp_get_option('rag_max_results', 4);
    $max_faq = (int) askwp_get_option('rag_max_faq', 2);
    $snippet_length = (int) askwp_get_option('rag_snippet_length', 300);
    $post_types_saved = askwp_get_option('rag_post_types', array('page', 'post'));
    if (!is_array($post_types_saved)) {
        $post_types_saved = array('page', 'post');
    }
    $available_types = get_post_types(array('public' => true), 'objects');
    ?>
    <table class="form-table">
        <tr>
            <th><label for="askwp_rag_enabled">Enable RAG</label></th>
            <td>
                <input type="checkbox" id="askwp_rag_enabled" name="askwp_rag_enabled" value="1" <?php checked(1, $rag_enabled); ?> />
                <p class="description">Search your site content and inject it as context for the LLM.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_rag_max_results">Max Search Results</label></th>
            <td>
                <input type="number" id="askwp_rag_max_results" name="askwp_rag_max_results" value="<?php echo esc_attr($max_results); ?>" min="1" max="10" />
            </td>
        </tr>
        <tr>
            <th><label for="askwp_rag_max_faq">Max FAQ Matches</label></th>
            <td>
                <input type="number" id="askwp_rag_max_faq" name="askwp_rag_max_faq" value="<?php echo esc_attr($max_faq); ?>" min="0" max="10" />
            </td>
        </tr>
        <tr>
            <th>Post Types to Search</th>
            <td>
                <?php foreach ($available_types as $pt) : ?>
                    <label>
                        <input type="checkbox" name="askwp_rag_post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $post_types_saved, true)); ?> />
                        <?php echo esc_html($pt->labels->singular_name); ?> (<code><?php echo esc_html($pt->name); ?></code>)
                    </label><br>
                <?php endforeach; ?>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_rag_snippet_length">Snippet Length</label></th>
            <td>
                <input type="number" id="askwp_rag_snippet_length" name="askwp_rag_snippet_length" value="<?php echo esc_attr($snippet_length); ?>" min="100" max="1000" step="50" />
                <p class="description">Characters per search result snippet.</p>
            </td>
        </tr>
    </table>
    <?php
}

function askwp_render_tab_form()
{
    $form_enabled = (int) askwp_get_option('form_enabled', 0);
    $form_title = (string) askwp_get_option('form_title', 'Contact Form');
    $form_trigger = (string) askwp_get_option('form_trigger_label', 'Open Form');
    $form_email = (string) askwp_get_option('form_email_to', get_option('admin_email'));
    $form_subject = (string) askwp_get_option('form_email_subject', 'New submission from {bot_name}');
    $form_success = (string) askwp_get_option('form_success_message', 'Thank you. Your submission has been sent.');
    $form_steps = (int) askwp_get_option('form_steps', 1);
    $form_fields_raw = askwp_get_option('form_fields', askwp_default_form_fields());
    $form_fields_json = is_string($form_fields_raw) ? $form_fields_raw : wp_json_encode($form_fields_raw);
    ?>
    <table class="form-table">
        <tr>
            <th><label for="askwp_form_enabled">Enable Form</label></th>
            <td>
                <input type="checkbox" id="askwp_form_enabled" name="askwp_form_enabled" value="1" <?php checked(1, $form_enabled); ?> />
                <p class="description">Show a form button in the chat widget.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_form_title">Form Title</label></th>
            <td><input type="text" id="askwp_form_title" name="askwp_form_title" value="<?php echo esc_attr($form_title); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="askwp_form_trigger_label">Button Label</label></th>
            <td><input type="text" id="askwp_form_trigger_label" name="askwp_form_trigger_label" value="<?php echo esc_attr($form_trigger); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="askwp_form_email_to">Recipient Email</label></th>
            <td><input type="email" id="askwp_form_email_to" name="askwp_form_email_to" value="<?php echo esc_attr($form_email); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="askwp_form_email_subject">Email Subject</label></th>
            <td>
                <input type="text" id="askwp_form_email_subject" name="askwp_form_email_subject" value="<?php echo esc_attr($form_subject); ?>" class="regular-text" />
                <p class="description">Use <code>{bot_name}</code> or <code>{field_name}</code> placeholders.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_form_success_message">Success Message</label></th>
            <td><input type="text" id="askwp_form_success_message" name="askwp_form_success_message" value="<?php echo esc_attr($form_success); ?>" class="large-text" /></td>
        </tr>
        <tr>
            <th><label for="askwp_form_steps">Form Steps</label></th>
            <td>
                <select id="askwp_form_steps" name="askwp_form_steps">
                    <option value="1" <?php selected($form_steps, 1); ?>>Single step</option>
                    <option value="2" <?php selected($form_steps, 2); ?>>Two steps</option>
                </select>
            </td>
        </tr>
        <tr>
            <th>Form Fields</th>
            <td>
                <div id="askwp-form-builder"></div>
                <p><button type="button" id="askwp-fb-add" class="button askwp-fb-add">+ Add Field</button></p>
                <input type="hidden" id="askwp_form_fields" name="askwp_form_fields" value="<?php echo esc_attr($form_fields_json); ?>" />
                <p class="description">Add, remove, and reorder fields. Supported types: text, email, phone, textarea, select, checkbox.</p>
            </td>
        </tr>
    </table>
    <?php
}

function askwp_render_tab_appearance()
{
    $color_primary = (string) askwp_get_option('color_primary', '#2563eb');
    $color_secondary = (string) askwp_get_option('color_secondary', '#1e293b');
    $color_text = (string) askwp_get_option('color_text', '#1f2937');
    $theme_mode = (string) askwp_get_option('theme_mode', 'auto');
    $chat_icon = (string) askwp_get_option('chat_icon', 'chat-bubble');
    $chat_icon_custom = (string) askwp_get_option('chat_icon_custom_url', '');
    $bot_avatar = (string) askwp_get_option('bot_avatar_url', '');
    $border_radius = (int) askwp_get_option('border_radius', 16);
    $font_family = (string) askwp_get_option('font_family', '');
    $widget_width = (int) askwp_get_option('widget_width', 380);
    $panel_size = (string) askwp_get_option('panel_size', 'normal');
    $widget_zindex = (int) askwp_get_option('widget_zindex', 999999);
    $custom_css = (string) askwp_get_option('custom_css', '');
    $custom_css_light = (string) askwp_get_option('custom_css_light', '');
    $custom_css_dark = (string) askwp_get_option('custom_css_dark', '');
    ?>
    <table class="form-table">
        <tr>
            <th><label for="askwp_color_primary">Primary Color</label></th>
            <td><input type="text" id="askwp_color_primary" name="askwp_color_primary" value="<?php echo esc_attr($color_primary); ?>" class="askwp-color-field" /></td>
        </tr>
        <tr>
            <th><label for="askwp_color_secondary">Secondary Color</label></th>
            <td><input type="text" id="askwp_color_secondary" name="askwp_color_secondary" value="<?php echo esc_attr($color_secondary); ?>" class="askwp-color-field" /></td>
        </tr>
        <tr>
            <th><label for="askwp_color_text">Text Color</label></th>
            <td><input type="text" id="askwp_color_text" name="askwp_color_text" value="<?php echo esc_attr($color_text); ?>" class="askwp-color-field" /></td>
        </tr>
        <tr>
            <th><label for="askwp_theme_mode">Theme Mode</label></th>
            <td>
                <select id="askwp_theme_mode" name="askwp_theme_mode">
                    <option value="auto" <?php selected($theme_mode, 'auto'); ?>>Auto (follow visitor device)</option>
                    <option value="light" <?php selected($theme_mode, 'light'); ?>>Always Light</option>
                    <option value="dark" <?php selected($theme_mode, 'dark'); ?>>Always Dark</option>
                </select>
                <p class="description">Auto uses <code>prefers-color-scheme</code> and updates live if the visitor switches OS theme.</p>
            </td>
        </tr>
        <tr>
            <th>Chat Icon</th>
            <td>
                <fieldset>
                    <label><input type="radio" name="askwp_chat_icon" value="chat-bubble" <?php checked($chat_icon, 'chat-bubble'); ?> /> Chat Bubble</label><br>
                    <label><input type="radio" name="askwp_chat_icon" value="headset" <?php checked($chat_icon, 'headset'); ?> /> Headset</label><br>
                    <label><input type="radio" name="askwp_chat_icon" value="robot" <?php checked($chat_icon, 'robot'); ?> /> Robot</label><br>
                    <label><input type="radio" name="askwp_chat_icon" value="custom" <?php checked($chat_icon, 'custom'); ?> /> Custom Image</label>
                </fieldset>
                <div style="margin-top: 8px;">
                    <input type="text" id="askwp_chat_icon_custom_url" name="askwp_chat_icon_custom_url" value="<?php echo esc_attr($chat_icon_custom); ?>" class="regular-text" placeholder="https://..." />
                    <button type="button" class="button askwp-media-upload" data-target="askwp_chat_icon_custom_url">Upload</button>
                </div>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_bot_avatar_url">Bot Avatar</label></th>
            <td>
                <input type="text" id="askwp_bot_avatar_url" name="askwp_bot_avatar_url" value="<?php echo esc_attr($bot_avatar); ?>" class="regular-text" placeholder="https://..." />
                <button type="button" class="button askwp-media-upload" data-target="askwp_bot_avatar_url" data-preview="askwp-avatar-preview">Upload</button>
                <?php if ($bot_avatar) : ?>
                    <br><img id="askwp-avatar-preview" src="<?php echo esc_url($bot_avatar); ?>" style="max-width:48px; margin-top:8px;" />
                <?php else : ?>
                    <br><img id="askwp-avatar-preview" src="" style="max-width:48px; margin-top:8px; display:none;" />
                <?php endif; ?>
                <p class="description">Small image shown next to assistant messages. Leave empty for no avatar.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_border_radius">Border Radius (px)</label></th>
            <td><input type="range" id="askwp_border_radius" name="askwp_border_radius" value="<?php echo esc_attr($border_radius); ?>" min="0" max="28" /> <span><?php echo esc_html($border_radius); ?>px</span></td>
        </tr>
        <tr>
            <th><label for="askwp_font_family">Font Family</label></th>
            <td>
                <input type="text" id="askwp_font_family" name="askwp_font_family" value="<?php echo esc_attr($font_family); ?>" class="regular-text" placeholder="inherit" />
                <p class="description">Leave empty to inherit from your theme.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_widget_width">Widget Width (px)</label></th>
            <td><input type="number" id="askwp_widget_width" name="askwp_widget_width" value="<?php echo esc_attr($widget_width); ?>" min="300" max="600" step="10" /></td>
        </tr>
        <tr>
            <th><label for="askwp_panel_size">Panel Size Preset</label></th>
            <td>
                <select id="askwp_panel_size" name="askwp_panel_size">
                    <option value="compact" <?php selected($panel_size, 'compact'); ?>>Compact</option>
                    <option value="normal" <?php selected($panel_size, 'normal'); ?>>Normal</option>
                    <option value="large" <?php selected($panel_size, 'large'); ?>>Large</option>
                </select>
                <p class="description">Adjusts chat panel width/height as a quick preset for different site layouts.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_widget_zindex">Z-Index</label></th>
            <td><input type="number" id="askwp_widget_zindex" name="askwp_widget_zindex" value="<?php echo esc_attr($widget_zindex); ?>" min="1" max="9999999" /></td>
        </tr>
        <tr>
            <th><label for="askwp_custom_css">Custom CSS</label></th>
            <td>
                <textarea id="askwp_custom_css" name="askwp_custom_css" rows="6" class="large-text code"><?php echo esc_textarea($custom_css); ?></textarea>
                <p class="description">Applied in both light and dark mode.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_custom_css_light">Custom CSS (Light Mode)</label></th>
            <td>
                <textarea id="askwp_custom_css_light" name="askwp_custom_css_light" rows="6" class="large-text code"><?php echo esc_textarea($custom_css_light); ?></textarea>
                <p class="description">Applied only while the widget is in light mode.</p>
            </td>
        </tr>
        <tr>
            <th><label for="askwp_custom_css_dark">Custom CSS (Dark Mode)</label></th>
            <td>
                <textarea id="askwp_custom_css_dark" name="askwp_custom_css_dark" rows="6" class="large-text code"><?php echo esc_textarea($custom_css_dark); ?></textarea>
                <p class="description">Applied only while the widget is in dark mode.</p>
            </td>
        </tr>
    </table>
    <?php
}

function askwp_render_tab_limits()
{
    $chat_limit = (int) askwp_get_option('chat_rate_limit_hourly', 60);
    $form_limit = (int) askwp_get_option('form_rate_limit_daily', 10);
    ?>
    <table class="form-table">
        <tr>
            <th><label for="askwp_chat_rate_limit_hourly">Chat Rate Limit (per hour / IP)</label></th>
            <td><input type="number" id="askwp_chat_rate_limit_hourly" name="askwp_chat_rate_limit_hourly" value="<?php echo esc_attr($chat_limit); ?>" min="1" /></td>
        </tr>
        <tr>
            <th><label for="askwp_form_rate_limit_daily">Form Rate Limit (per day / IP)</label></th>
            <td><input type="number" id="askwp_form_rate_limit_daily" name="askwp_form_rate_limit_daily" value="<?php echo esc_attr($form_limit); ?>" min="1" /></td>
        </tr>
    </table>
    <?php
}

function askwp_token_pricing_table()
{
    return array(
        'gpt-4o'                       => array('input' => 2.50,  'output' => 10.00),
        'gpt-4o-mini'                  => array('input' => 0.15,  'output' => 0.60),
        'gpt-4.1'                      => array('input' => 2.00,  'output' => 8.00),
        'gpt-4.1-mini'                 => array('input' => 0.40,  'output' => 1.60),
        'gpt-4.1-nano'                 => array('input' => 0.10,  'output' => 0.40),
        'gpt-5'                        => array('input' => 2.00, 'output' => 8.00),
        'claude-sonnet-4-5-20250929'   => array('input' => 3.00,  'output' => 15.00),
        'claude-haiku-3-5-20241022'    => array('input' => 0.80,  'output' => 4.00),
        'claude-3-haiku-20240307'      => array('input' => 0.25,  'output' => 1.25),
    );
}

function askwp_render_tab_usage()
{
    ?>
    <div id="askwp-usage-summary" class="askwp-usage-summary"></div>
    <h3>Daily Usage (Last 30 Days)</h3>
    <div id="askwp-usage-chart" class="askwp-chart"></div>
    <h3>Model Breakdown</h3>
    <div id="askwp-usage-model-table"></div>
    <p class="description" style="margin-top:16px;">Token log stores the last 500 requests. Cost estimates are approximate based on published API pricing.</p>
    <?php
}
