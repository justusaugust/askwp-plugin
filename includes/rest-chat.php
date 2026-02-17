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
        'description' => 'Load the full content of a website page. Use a URL from search_website results or from context.',
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

function askwp_chat_build_system_prompt($context_text, $page_title, $faq_raw, $use_search_tool = false)
{
    $bot_name = (string) askwp_get_option('bot_name', 'Chat Assistant');
    $admin_system = trim((string) askwp_get_option('system_instructions', askwp_default_system_instructions()));
    $admin_system = str_replace('{bot_name}', $bot_name, $admin_system);
    $context_pack = trim((string) askwp_get_option('context_pack', ''));
    $compact_faq = askwp_chat_compact_faq_context($faq_raw, 6);

    $parts = array();

    $parts[] = $admin_system;

    if ($use_search_tool) {
        $parts[] = 'You have two tools: search_website (finds pages) and get_page (loads full page content). If the provided context does not answer the question, use search_website first, then get_page for the most relevant result. Only respond after loading the page content.';
    } else {
        $parts[] = 'Context priority: 1) CURRENT_PAGE (high), 2) WP_SEARCH (medium), 3) FAQ_MATCHES (low).';
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

    $payload_encoded = wp_json_encode($payload);
    if (is_string($payload_encoded) && strlen($payload_encoded) > 50000) {
        return new WP_Error('askwp_payload_too_large', 'Payload too large.', array('status' => 413));
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

            $results = askwp_rag_search_posts($query, $exclude_post_id);
            $query_terms = askwp_rag_query_terms($query);
            $max_results = (int) askwp_get_option('rag_max_results', 4);
            $results = askwp_rag_rank_search_results($results, $query_terms, $max_results + 1);

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

            $page = askwp_rag_resolve_page($url);
            if (!is_array($page)) {
                return 'Page not found: ' . $url;
            }

            $tool_sources[] = array('title' => $page['title'], 'url' => $page['url']);

            $post = get_post((int) $page['post_id']);
            $content = ($post instanceof WP_Post) ? askwp_rag_extract_post_text($post, 3000) : $page['content'];

            return $page['title'] . "\nURL: " . $page['url'] . "\n\n" . $content;
        }

        return 'Unknown tool.';
    };

    // Build system prompt.
    $provider = askwp_get_llm_provider();
    $use_tools = $provider->supports_tools();
    $system_prompt = askwp_chat_build_system_prompt(
        $slim_context['context_text'],
        $page_title,
        $faq_raw,
        $use_tools
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
        error_log('AskWP chat generation failed: ' . $result->get_error_code() . ' - ' . $result->get_error_message());
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
