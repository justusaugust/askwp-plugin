<?php

if (!defined('ABSPATH')) {
    exit;
}

function askwp_register_chat_routes()
{
    register_rest_route('askwp/v1', '/chat', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'askwp_rest_chat_handler',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'askwp_register_chat_routes');

function askwp_chat_is_injection_attempt($message)
{
    $message = askwp_text_lower((string) $message);
    if ($message === '') {
        return false;
    }

    $needles = array(
        'ignore all previous',
        'ignore all prior',
        'ignore your instructions',
        'disregard your instructions',
        'system prompt',
        'system-prompt',
        'internal rules',
        'developer mode',
        'dev mode',
        'jailbreak',
        'prompt injection',
        'bypass',
        'api-key',
        'api key',
        'reveal your',
        'show me your prompt',
        'what are your instructions',
        'ignoriere alle',
        'interne regeln',
        'offenlege',
        'verrate',
    );

    foreach ($needles as $needle) {
        if (strpos($message, $needle) !== false) {
            return true;
        }
    }

    if (preg_match('/\b(system|developer)\b.{0,40}\b(prompt|instructions?|rules?)\b/iu', $message)) {
        return true;
    }

    if (strpos($message, 'base64') !== false && preg_match('/\b(prompt|system|rules?|instructions?)\b/iu', $message)) {
        return true;
    }

    return false;
}

function askwp_chat_is_status_ping($message)
{
    $message = trim((string) $message);
    return in_array($message, array('[FORM_OPENED]', '[FORM_SUBMITTED]'), true);
}

function askwp_chat_status_reply($message)
{
    $bot_name = (string) askwp_get_option('bot_name', 'Chat Assistant');

    if (trim((string) $message) === '[FORM_SUBMITTED]') {
        $success = (string) askwp_get_option('form_success_message', 'Thank you. Your submission has been sent.');
        return $success;
    }

    return 'Please fill out the form. It will be sent directly to us.';
}

function askwp_chat_image_attachments_enabled()
{
    return (bool) askwp_get_option('enable_image_attachments', 0);
}

function askwp_chat_max_payload_bytes()
{
    return askwp_chat_image_attachments_enabled() ? (6 * 1024 * 1024) : 50000;
}

function askwp_chat_provider_supports_images($provider_name)
{
    return in_array((string) $provider_name, array('openai', 'anthropic', 'openrouter'), true);
}

function askwp_chat_parse_image_attachment($raw_attachment)
{
    if ($raw_attachment === null || $raw_attachment === '' || $raw_attachment === false) {
        return null;
    }

    if (!askwp_chat_image_attachments_enabled()) {
        return new WP_Error('askwp_image_disabled', 'Image attachments are disabled on this site.', array('status' => 400));
    }

    if (!is_array($raw_attachment)) {
        return new WP_Error('askwp_image_invalid', 'Invalid image attachment payload.', array('status' => 400));
    }

    $data_url = isset($raw_attachment['data_url']) ? trim((string) $raw_attachment['data_url']) : '';
    if ($data_url === '') {
        return new WP_Error('askwp_image_missing', 'Image attachment is missing data.', array('status' => 400));
    }

    $match = array();
    if (!preg_match('/^data:(image\/(?:png|jpeg|jpg|webp|gif));base64,([A-Za-z0-9+\/=\r\n]+)$/i', $data_url, $match)) {
        return new WP_Error('askwp_image_format', 'Unsupported image format. Use PNG, JPEG, WEBP, or GIF.', array('status' => 400));
    }

    $mime_type = strtolower((string) $match[1]);
    if ($mime_type === 'image/jpg') {
        $mime_type = 'image/jpeg';
    }

    $base64 = preg_replace('/\s+/', '', (string) $match[2]);
    if (!is_string($base64) || $base64 === '') {
        return new WP_Error('askwp_image_base64_empty', 'Image attachment is empty.', array('status' => 400));
    }

    $binary = base64_decode($base64, true);
    if (!is_string($binary) || $binary === '') {
        return new WP_Error('askwp_image_base64_invalid', 'Image attachment could not be decoded.', array('status' => 400));
    }

    $max_bytes = 2 * 1024 * 1024;
    $size_bytes = strlen($binary);
    if ($size_bytes > $max_bytes) {
        return new WP_Error('askwp_image_too_large', 'Image is too large. Maximum size is 2MB.', array('status' => 413));
    }

    $name = isset($raw_attachment['name']) ? askwp_sanitize_line($raw_attachment['name'], 80) : 'image';
    if ($name === '') {
        $name = 'image';
    }

    return array(
        'mime_type'  => $mime_type,
        'base64'     => $base64,
        'data_url'   => 'data:' . $mime_type . ';base64,' . $base64,
        'name'       => $name,
        'size_bytes' => $size_bytes,
    );
}

function askwp_chat_attach_image_to_last_user_message($messages, $attachment)
{
    if (!is_array($messages) || empty($attachment) || !is_array($attachment)) {
        return $messages;
    }

    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (!isset($messages[$i]) || !is_array($messages[$i])) {
            continue;
        }
        if (!isset($messages[$i]['role']) || $messages[$i]['role'] !== 'user') {
            continue;
        }

        $text = isset($messages[$i]['content']) ? trim((string) $messages[$i]['content']) : '';
        if ($text === '') {
            $text = 'Please analyze this image.';
        }

        $messages[$i]['content'] = array(
            array('type' => 'text', 'text' => $text),
            array(
                'type'      => 'image',
                'mime_type' => isset($attachment['mime_type']) ? (string) $attachment['mime_type'] : 'image/png',
                'data_url'  => isset($attachment['data_url']) ? (string) $attachment['data_url'] : '',
                'base64'    => isset($attachment['base64']) ? (string) $attachment['base64'] : '',
            ),
        );

        break;
    }

    return $messages;
}

function askwp_chat_search_tool_schema()
{
    return array(
        'type'       => 'function',
        'name'       => 'search_website',
        'description' => 'Search the website for relevant pages. Returns a list of pages with title, URL, and preview snippet.',
        'parameters' => array(
            'type'       => 'object',
            'properties' => array(
                'query' => array(
                    'type'        => 'string',
                    'description' => 'Search keywords',
                ),
            ),
            'required'             => array('query'),
            'additionalProperties' => false,
        ),
        'strict' => true,
    );
}

function askwp_chat_get_page_tool_schema()
{
    return array(
        'type'       => 'function',
        'name'       => 'get_page',
        'description' => 'Load a page by URL. If the target body is thin, the tool may include supporting excerpts from related same-site pages.',
        'parameters' => array(
            'type'       => 'object',
            'properties' => array(
                'url' => array(
                    'type'        => 'string',
                    'description' => 'URL of the page to load',
                ),
            ),
            'required'             => array('url'),
            'additionalProperties' => false,
        ),
        'strict' => true,
    );
}

function askwp_chat_compact_faq_context($faq_raw, $max_items)
{
    $pairs = askwp_rag_parse_faq($faq_raw);
    if (empty($pairs)) {
        return '';
    }

    $items = array();
    foreach (array_slice($pairs, 0, $max_items) as $pair) {
        $question = askwp_text_substr((string) $pair['question'], 120);
        $answer = askwp_text_substr((string) $pair['answer'], 180);
        $items[] = '- ' . $question . ': ' . $answer;
    }

    return implode("\n", $items);
}

function askwp_chat_build_system_prompt($context_text, $page_title, $faq_raw, $use_search_tool = false, $has_image_attachment = false)
{
    $bot_name = (string) askwp_get_option('bot_name', 'Chat Assistant');
    $admin_system = trim((string) askwp_get_option('system_instructions', askwp_default_system_instructions()));
    $admin_system = str_replace('{bot_name}', $bot_name, $admin_system);
    $context_pack = trim((string) askwp_get_option('context_pack', ''));
    $compact_faq = askwp_chat_compact_faq_context($faq_raw, 6);

    $parts = array();

    $parts[] = $admin_system;

    if ($use_search_tool) {
        $parts[] = 'You have two tools: search_website (finds pages) and get_page (loads page content). If the provided context is not enough, use search_website first, then get_page for the best matches. get_page may return Content status: support_enriched when the target page is thin but related evidence exists; in that case answer directly from the supporting evidence, without leading with limitations and without labeling the answer as an inference. Never ask the visitor to paste URLs, text, screenshots, or other site content that can be retrieved via tools. Only if no substantive evidence exists after tool use, provide one concise best-effort inference (label it as an inference) and stop. For inferred statements, do not present text as a direct quote from the target page.';
    } else {
        $parts[] = 'Context priority: 1) CURRENT_PAGE (high), 2) WP_SEARCH (medium), 3) FAQ_MATCHES (low).';
    }

    $parts[] = 'Formatting support in the chat UI includes headings, nested lists, blockquotes, tables, horizontal rules, fenced code blocks, links, and Markdown images with absolute URLs. Use these only when they improve readability. Do not paste bare URLs in the answer body unless the visitor explicitly asks for direct links; source pills are shown separately.';
    if ($has_image_attachment) {
        $parts[] = 'The latest visitor message includes an attached image. Analyze that image directly and combine its details with website context in your answer.';
    }

    if ($context_pack !== '') {
        $parts[] = "Background context:\n" . askwp_sanitize_paragraph($context_pack, 1800);
    }

    if ($compact_faq !== '') {
        $parts[] = "FAQ highlights:\n" . $compact_faq;
    }

    if ($page_title !== '') {
        $parts[] = 'Current page: ' . $page_title;
    }

    if ($context_text !== '') {
        $parts[] = "Context:\n" . $context_text;
    } else {
        $parts[] = 'No context is available for this query. Be transparent about this and suggest contacting directly.';
    }

    return implode("\n\n", $parts);
}

function askwp_log_token_usage($usage, $model = '')
{
    if (!is_array($usage) || empty($usage['total_tokens'])) {
        return;
    }

    $entry = array(
        'ts'     => time(),
        'model'  => $model !== '' ? $model : (string) askwp_get_option('model', 'gpt-4o'),
        'input'  => (int) $usage['input_tokens'],
        'output' => (int) $usage['output_tokens'],
        'total'  => (int) $usage['total_tokens'],
    );

    $log = get_option('askwp_token_log', array());
    if (!is_array($log)) {
        $log = array();
    }

    $log[] = $entry;

    if (count($log) > 500) {
        $log = array_slice($log, -500);
    }

    update_option('askwp_token_log', $log, false);
}

function askwp_rest_chat_handler($request)
{
    $origin_check = askwp_validate_origin($request);
    if (is_wp_error($origin_check)) {
        return $origin_check;
    }

    $rate_limit = (int) askwp_get_option('chat_rate_limit_hourly', 60);
    $rate_check = askwp_enforce_rate_limit('chat', $rate_limit, HOUR_IN_SECONDS);
    if (is_wp_error($rate_check)) {
        return $rate_check;
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_Error('askwp_invalid_payload', 'Invalid JSON payload.', array('status' => 400));
    }

    $max_payload_bytes = askwp_chat_max_payload_bytes();
    $payload_encoded = wp_json_encode($payload);
    if (is_string($payload_encoded) && strlen($payload_encoded) > $max_payload_bytes) {
        return new WP_Error('askwp_payload_too_large', 'Payload too large.', array('status' => 413));
    }

    $image_attachment = askwp_chat_parse_image_attachment(isset($payload['attachment']) ? $payload['attachment'] : null);
    if (is_wp_error($image_attachment)) {
        return $image_attachment;
    }

    $provider = askwp_get_llm_provider();
    $provider_name = $provider->get_name();
    if (is_array($image_attachment) && !askwp_chat_provider_supports_images($provider_name)) {
        return new WP_Error('askwp_image_provider_unsupported', 'Image attachments are not supported by the selected LLM provider.', array('status' => 400));
    }

    $messages = isset($payload['messages']) && is_array($payload['messages']) ? $payload['messages'] : array();
    if (empty($messages)) {
        return new WP_Error('askwp_missing_messages', 'Messages array is required.', array('status' => 400));
    }

    $messages = array_slice($messages, -12);

    $clean_messages = array();
    $latest_user_message = '';

    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $role = isset($message['role']) ? strtolower((string) $message['role']) : 'user';
        if (!in_array($role, array('user', 'assistant'), true)) {
            continue;
        }

        $max_len = ($role === 'user') ? 1500 : 2000;
        $content = askwp_sanitize_message_text(isset($message['content']) ? $message['content'] : '', $max_len);

        if ($content === '') {
            continue;
        }

        $clean_messages[] = array(
            'role'    => $role,
            'content' => $content,
        );

        if ($role === 'user') {
            $latest_user_message = $content;
        }
    }

    if ($latest_user_message === '' && is_array($image_attachment)) {
        $latest_user_message = '[IMAGE_ATTACHED]';
        $clean_messages[] = array(
            'role'    => 'user',
            'content' => $latest_user_message,
        );
    }

    if ($latest_user_message === '') {
        return new WP_Error('askwp_missing_user_message', 'No valid user message found.', array('status' => 400));
    }

    $page_url = isset($payload['page_url']) ? esc_url_raw((string) $payload['page_url']) : '';
    $page_title = isset($payload['page_title']) ? askwp_sanitize_line($payload['page_title'], 180) : '';

    // Status pings for form open/submit.
    if (askwp_chat_is_status_ping($latest_user_message)) {
        return new WP_REST_Response(array(
            'reply'   => askwp_chat_status_reply($latest_user_message),
            'sources' => array(),
            'action'  => null,
        ), 200);
    }

    // Injection detection.
    if (askwp_chat_is_injection_attempt($latest_user_message)) {
        return new WP_REST_Response(array(
            'reply'   => 'I cannot help with that. Internal instructions, system prompts, and API keys are confidential. If you have a question about our services, I am happy to help.',
            'sources' => array(),
            'action'  => null,
        ), 200);
    }

    // Build RAG context.
    $faq_raw = (string) askwp_get_option('faq_raw', '');
    $rag_enabled = (bool) askwp_get_option('rag_enabled', 1);

    $slim_context = array(
        'current_page' => null,
        'sources'      => array(),
        'context_text' => '',
    );

    if ($rag_enabled) {
        $full_context = askwp_rag_build_context($page_url, $latest_user_message, $faq_raw);
        $slim_context['current_page'] = isset($full_context['current_page']) ? $full_context['current_page'] : null;
        $slim_context['sources'] = isset($full_context['sources']) ? $full_context['sources'] : array();
        $slim_context['context_text'] = askwp_rag_context_to_text($full_context);
    }

    $exclude_post_id = is_array($slim_context['current_page']) ? (int) $slim_context['current_page']['post_id'] : 0;

    // Tool handler for search_website and get_page.
    $tool_sources = array();
    $tool_handler = function ($name, $arguments) use ($exclude_post_id, &$tool_sources) {
        if ($name === 'search_website') {
            $query = isset($arguments['query']) ? trim((string) $arguments['query']) : '';
            if ($query === '') {
                return 'No search terms provided.';
            }

            $max_results = (int) askwp_get_option('rag_max_results', 4);
            $results = askwp_rag_tool_search($query, $exclude_post_id, $max_results + 2);

            if (empty($results)) {
                return 'No results for "' . $query . '".';
            }

            foreach ($results as $item) {
                if (!empty($item['url']) && !empty($item['title'])) {
                    $tool_sources[] = array('title' => $item['title'], 'url' => $item['url']);
                }
            }

            $lines = array();
            foreach (array_slice($results, 0, 6) as $item) {
                $snippet = askwp_text_substr(isset($item['snippet']) ? (string) $item['snippet'] : '', 200);
                $lines[] = '- ' . $item['title'] . ' | ' . $item['url'] . "\n  " . $snippet;
            }
            return implode("\n", $lines);
        }

        if ($name === 'get_page') {
            $url = isset($arguments['url']) ? trim((string) $arguments['url']) : '';
            if ($url === '') {
                return 'No URL provided.';
            }

            $page_payload = askwp_rag_get_page_tool_payload($url, 3000);
            if (!is_array($page_payload) || empty($page_payload['page']) || empty($page_payload['text'])) {
                return 'Page not found: ' . $url;
            }

            $page = $page_payload['page'];
            $tool_sources[] = array('title' => $page['title'], 'url' => $page['url']);

            return (string) $page_payload['text'];
        }

        return 'Unknown tool.';
    };

    $clean_messages = askwp_chat_attach_image_to_last_user_message($clean_messages, $image_attachment);

    // Build system prompt.
    $use_tools = $provider->supports_tools();
    $system_prompt = askwp_chat_build_system_prompt(
        $slim_context['context_text'],
        $page_title,
        $faq_raw,
        $use_tools,
        is_array($image_attachment)
    );

    // Build tool schemas.
    $tools = $use_tools ? array(askwp_chat_search_tool_schema(), askwp_chat_get_page_tool_schema()) : array();

    // Call LLM.
    $result = $provider->send($clean_messages, $system_prompt, array(
        'max_output_tokens' => (int) askwp_get_option('max_output_tokens', 500),
        'temperature'       => (float) askwp_get_option('temperature', 0.7),
        'tools'             => $tools,
        'tool_handler'      => $tool_handler,
    ));

    $usage = null;

    if (is_wp_error($result)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('AskWP chat generation failed: ' . $result->get_error_code() . ' - ' . $result->get_error_message());
        }
        $reply = 'I cannot generate a reliable response right now. Please try again later or contact us directly.';
    } else {
        $reply = trim((string) $result['text']);
        $usage = $result['usage'];
        askwp_log_token_usage($usage);
    }

    // Merge tool-found sources.
    if (!empty($tool_sources)) {
        $existing_urls = array();
        foreach ($slim_context['sources'] as $s) {
            if (!empty($s['url'])) {
                $existing_urls[$s['url']] = true;
            }
        }
        foreach ($tool_sources as $s) {
            if (!empty($s['url']) && !isset($existing_urls[$s['url']])) {
                $slim_context['sources'][] = array(
                    'title' => (string) $s['title'],
                    'url'   => esc_url_raw((string) $s['url']),
                );
                $existing_urls[$s['url']] = true;
            }
        }
    }

    return new WP_REST_Response(array(
        'reply'   => (string) $reply,
        'sources' => $slim_context['sources'],
        'action'  => null,
        'usage'   => $usage,
    ), 200);
}
