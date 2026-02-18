<?php

if (!defined('ABSPATH')) {
    exit;
}

function askwp_stream_force_flush($padding_bytes = 0)
{
    $pad = (int) $padding_bytes;
    if ($pad > 0) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE comment padding; no user data.
        echo ':' . str_repeat(' ', $pad) . "\n\n";
    }
    while (ob_get_level()) {
        ob_end_flush();
    }
    flush();
}

function askwp_stream_progress_sanitize_id($stream_id)
{
    $id = (string) $stream_id;
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
    if (!is_string($id) || $id === '') {
        return '';
    }
    if (strlen($id) > 96) {
        $id = substr($id, 0, 96);
    }
    return $id;
}

function askwp_stream_progress_key($stream_id)
{
    $id = askwp_stream_progress_sanitize_id($stream_id);
    if ($id === '') {
        return '';
    }
    return 'askwp_stream_progress_' . md5($id);
}

function askwp_stream_progress_get($stream_id)
{
    $key = askwp_stream_progress_key($stream_id);
    if ($key === '') {
        return null;
    }
    $state = get_transient($key);
    if (!is_array($state)) {
        return null;
    }

    $steps = isset($state['steps']) && is_array($state['steps']) ? $state['steps'] : array();
    $clean_steps = array();
    foreach ($steps as $step) {
        $line = askwp_sanitize_line((string) $step, 180);
        if ($line !== '') {
            $clean_steps[] = $line;
        }
    }

    return array(
        'steps'      => $clean_steps,
        'done'       => !empty($state['done']),
        'error'      => isset($state['error']) ? askwp_sanitize_line((string) $state['error'], 220) : '',
        'updated_at' => isset($state['updated_at']) ? (int) $state['updated_at'] : 0,
    );
}

function askwp_stream_progress_set($stream_id, $state, $ttl = 300)
{
    $key = askwp_stream_progress_key($stream_id);
    if ($key === '') {
        return;
    }
    set_transient($key, $state, max(60, (int) $ttl));
}

function askwp_stream_progress_begin($stream_id)
{
    $id = askwp_stream_progress_sanitize_id($stream_id);
    if ($id === '') {
        return '';
    }

    askwp_stream_progress_set($id, array(
        'steps'      => array(),
        'done'       => false,
        'error'      => '',
        'updated_at' => time(),
    ), 300);

    return $id;
}

function askwp_stream_progress_add_step($stream_id, $step_text)
{
    $id = askwp_stream_progress_sanitize_id($stream_id);
    if ($id === '') {
        return;
    }

    $step = askwp_sanitize_line((string) $step_text, 180);
    if ($step === '') {
        return;
    }

    $state = askwp_stream_progress_get($id);
    if (!is_array($state)) {
        $state = array(
            'steps'      => array(),
            'done'       => false,
            'error'      => '',
            'updated_at' => time(),
        );
    }

    $steps = isset($state['steps']) && is_array($state['steps']) ? $state['steps'] : array();
    $last = !empty($steps) ? end($steps) : '';
    if ((string) $last === $step) {
        return;
    }

    $steps[] = $step;
    if (count($steps) > 30) {
        $steps = array_slice($steps, -30);
    }

    $state['steps'] = $steps;
    $state['done'] = false;
    $state['updated_at'] = time();
    askwp_stream_progress_set($id, $state, 300);
}

function askwp_stream_progress_mark_done($stream_id, $error_message = '')
{
    $id = askwp_stream_progress_sanitize_id($stream_id);
    if ($id === '') {
        return;
    }

    $state = askwp_stream_progress_get($id);
    if (!is_array($state)) {
        $state = array(
            'steps'      => array(),
            'done'       => false,
            'error'      => '',
            'updated_at' => time(),
        );
    }

    $state['done'] = true;
    $state['updated_at'] = time();
    if ($error_message !== '') {
        $state['error'] = askwp_sanitize_line((string) $error_message, 220);
    }

    askwp_stream_progress_set($id, $state, 300);
}

function askwp_stream_emit($data)
{
    echo 'data: ' . wp_json_encode($data) . "\n\n";
    askwp_stream_force_flush();
}

function askwp_stream_emit_done($sources, $usage)
{
    if (!empty($GLOBALS['askwp_stream_progress_id'])) {
        askwp_stream_progress_mark_done((string) $GLOBALS['askwp_stream_progress_id']);
    }

    askwp_stream_emit(array(
        'done'    => true,
        'sources' => $sources,
        'usage'   => $usage,
    ));
    echo "data: [DONE]\n\n";
    askwp_stream_force_flush();
}

function askwp_stream_emit_status($text)
{
    $clean = askwp_sanitize_line((string) $text, 180);
    if ($clean === '') {
        return;
    }

    if (!empty($GLOBALS['askwp_stream_progress_id'])) {
        askwp_stream_progress_add_step((string) $GLOBALS['askwp_stream_progress_id'], $clean);
    }

    echo 'data: ' . wp_json_encode(array('status' => $clean)) . "\n\n";
    // Add a small SSE comment payload so reverse proxies flush status lines early.
    askwp_stream_force_flush(1024);
}

function askwp_stream_url_label($url)
{
    $raw = esc_url_raw((string) $url);
    if ($raw === '') {
        return '';
    }

    $parts = wp_parse_url($raw);
    if (!is_array($parts)) {
        return askwp_sanitize_line($raw, 120);
    }

    $host = isset($parts['host']) ? (string) $parts['host'] : '';
    $path = isset($parts['path']) ? (string) $parts['path'] : '/';
    if ($path === '') {
        $path = '/';
    }

    if (strlen($path) > 72) {
        $path = askwp_text_substr($path, 72);
    }

    $label = trim($host . $path);
    return askwp_sanitize_line($label, 120);
}

function askwp_stream_validate_origin()
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
    if (!is_string($origin) || trim($origin) === '') {
        return true;
    }

    $origin_host = wp_parse_url($origin, PHP_URL_HOST);
    $site_host = wp_parse_url(site_url(), PHP_URL_HOST);

    if (!$origin_host || !$site_host) {
        return new WP_Error('askwp_origin_invalid', 'Invalid origin header.', array('status' => 403));
    }

    if (strtolower($origin_host) !== strtolower($site_host)) {
        return new WP_Error('askwp_origin_blocked', 'Origin not allowed.', array('status' => 403));
    }

    return true;
}

function askwp_stream_chat_handler()
{
    // Validate origin.
    $origin_check = askwp_stream_validate_origin();
    if (is_wp_error($origin_check)) {
        status_header(403);
        wp_die('Origin not allowed.');
    }

    // Rate limit.
    $rate_limit = (int) askwp_get_option('chat_rate_limit_hourly', 60);
    $rate_check = askwp_enforce_rate_limit('chat', $rate_limit, HOUR_IN_SECONDS);
    if (is_wp_error($rate_check)) {
        @ini_set('zlib.output_compression', 0); // phpcs:ignore WordPress.PHP.IniSet.Risky
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level()) { ob_end_clean(); }
        ob_implicit_flush(true);
        askwp_stream_emit(array('error' => 'Too many requests. Please try again later.'));
        exit;
    }

    // Parse payload.
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        status_header(400);
        wp_die('Invalid JSON payload.');
    }

    $max_payload_bytes = askwp_chat_max_payload_bytes();
    if (is_string($raw) && strlen($raw) > $max_payload_bytes) {
        status_header(413);
        wp_die('Payload too large.');
    }

    $image_attachment = askwp_chat_parse_image_attachment(isset($payload['attachment']) ? $payload['attachment'] : null);
    if (is_wp_error($image_attachment)) {
        $error_data = $image_attachment->get_error_data();
        $status = (is_array($error_data) && isset($error_data['status'])) ? (int) $error_data['status'] : 400;
        status_header($status);
        wp_die(esc_html($image_attachment->get_error_message()));
    }

    $provider = askwp_get_llm_provider();
    $provider_name = $provider->get_name();
    if (is_array($image_attachment) && !askwp_chat_provider_supports_images($provider_name)) {
        status_header(400);
        wp_die('Image attachments are not supported by the selected LLM provider.');
    }

    $messages = isset($payload['messages']) && is_array($payload['messages']) ? $payload['messages'] : array();
    if (empty($messages)) {
        status_header(400);
        wp_die('Messages array is required.');
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
        status_header(400);
        wp_die('No valid user message found.');
    }

    $stream_id = askwp_stream_progress_begin(isset($payload['stream_id']) ? (string) $payload['stream_id'] : '');
    $GLOBALS['askwp_stream_progress_id'] = $stream_id;

    $page_url = isset($payload['page_url']) ? esc_url_raw((string) $payload['page_url']) : '';
    $page_title = isset($payload['page_title']) ? askwp_sanitize_line($payload['page_title'], 180) : '';

    // Disable all PHP buffering mechanisms before streaming.
    @ini_set('zlib.output_compression', 0); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Required for SSE streaming.
    @ini_set('output_buffering', 'Off'); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Required for SSE streaming.
    @ini_set('implicit_flush', 1); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Required for SSE streaming.

    // Set SSE headers.
    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    header('Transfer-Encoding: chunked');
    header_remove('Content-Length');

    // Clear ALL output buffers.
    while (ob_get_level()) { ob_end_clean(); }
    ob_implicit_flush(true);
    echo ": stream-open\n\n";
    askwp_stream_force_flush(2048);

    // Status pings.
    if (askwp_chat_is_status_ping($latest_user_message)) {
        askwp_stream_emit(array('delta' => askwp_chat_status_reply($latest_user_message)));
        askwp_stream_emit_done(array(), null);
        exit;
    }

    // Injection detection.
    if (askwp_chat_is_injection_attempt($latest_user_message)) {
        askwp_stream_emit(array('delta' => 'I cannot help with that. Internal instructions, system prompts, and API keys are confidential. If you have a question about our services, I am happy to help.'));
        askwp_stream_emit_done(array(), null);
        exit;
    }

    askwp_stream_emit_status('Understanding your request');

    // Build RAG context.
    $faq_raw = (string) askwp_get_option('faq_raw', '');
    $rag_enabled = (bool) askwp_get_option('rag_enabled', 1);

    $slim_context = array(
        'current_page' => null,
        'sources'      => array(),
        'context_text' => '',
    );

    if ($rag_enabled) {
        askwp_stream_emit_status('Scanning the site for relevant context');
        $full_context = askwp_rag_build_context($page_url, $latest_user_message, $faq_raw);
        $slim_context['current_page'] = isset($full_context['current_page']) ? $full_context['current_page'] : null;
        $slim_context['sources'] = isset($full_context['sources']) ? $full_context['sources'] : array();
        $slim_context['context_text'] = askwp_rag_context_to_text($full_context);
    }

    $exclude_post_id = is_array($slim_context['current_page']) ? (int) $slim_context['current_page']['post_id'] : 0;

    // Tool handler.
    $tool_sources = array();
    $tool_handler = function ($name, $arguments) use ($exclude_post_id, &$tool_sources) {
        if ($name === 'search_website') {
            $query = isset($arguments['query']) ? trim((string) $arguments['query']) : '';
            if ($query === '') {
                return 'No search terms provided.';
            }

            $query_preview = askwp_sanitize_line(askwp_text_substr($query, 70), 70);
            askwp_stream_emit_status('Searching site for: "' . $query_preview . '"');

            $max_results = (int) askwp_get_option('rag_max_results', 4);
            $results = askwp_rag_tool_search($query, $exclude_post_id, $max_results + 2);

            if (empty($results)) {
                askwp_stream_emit_status('No strong matches found for that query yet');
                return 'No results for "' . $query . '".';
            }

            $result_count = count($results);
            askwp_stream_emit_status('Found ' . $result_count . ' matching page' . ($result_count === 1 ? '' : 's'));

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

            $url_label = askwp_stream_url_label($url);
            if ($url_label !== '') {
                askwp_stream_emit_status('Reading page: ' . $url_label);
            } else {
                askwp_stream_emit_status('Reading a page for details');
            }

            $page_payload = askwp_rag_get_page_tool_payload($url, 3000);
            if (!is_array($page_payload) || empty($page_payload['page']) || empty($page_payload['text'])) {
                askwp_stream_emit_status('Could not load that page content');
                return 'Page not found: ' . $url;
            }

            $page = $page_payload['page'];
            $tool_sources[] = array('title' => $page['title'], 'url' => $page['url']);

            if (!empty($page_payload['is_thin'])) {
                askwp_stream_emit_status('Page is thin, checking related supporting content');
            }

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

    $tools = $use_tools ? array(askwp_chat_search_tool_schema(), askwp_chat_get_page_tool_schema()) : array();

    $llm_options = array(
        'max_output_tokens' => (int) askwp_get_option('max_output_tokens', 500),
        'temperature'       => (float) askwp_get_option('temperature', 0.7),
        'tools'             => $tools,
        'tool_handler'      => $tool_handler,
    );

    // Stream with tool call loop.
    $total_usage = array('input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0);
    $max_tool_rounds = (!empty($tools) && $use_tools) ? 4 : 1;
    $current_messages = $clean_messages;

    for ($round = 0; $round < $max_tool_rounds; $round++) {
        if ($round === 0) {
            askwp_stream_emit_status($use_tools ? 'Planning the best retrieval steps' : 'Drafting a response');
        } else {
            askwp_stream_emit_status('Synthesizing findings into the final answer');
        }

        $stream_text = '';

        $on_delta = function ($text) use (&$stream_text) {
            $stream_text .= $text;
            askwp_stream_emit(array('delta' => $text));
        };

        $stream_result = $provider->send_stream($current_messages, $system_prompt, $llm_options, $on_delta);

        if (is_wp_error($stream_result)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('AskWP stream error: ' . $stream_result->get_error_code() . ' - ' . $stream_result->get_error_message());
            }
            if ($stream_text === '') {
                askwp_stream_emit(array('error' => 'Unable to generate a response. Please try again later.'));
                if ($stream_id !== '') {
                    askwp_stream_progress_mark_done($stream_id, 'Unable to generate a response. Please try again later.');
                }
            }
            break;
        }

        // Accumulate usage.
        if (isset($stream_result['usage'])) {
            $u = $stream_result['usage'];
            $total_usage['input_tokens']  += isset($u['input_tokens']) ? (int) $u['input_tokens'] : 0;
            $total_usage['output_tokens'] += isset($u['output_tokens']) ? (int) $u['output_tokens'] : 0;
            $total_usage['total_tokens']   = $total_usage['input_tokens'] + $total_usage['output_tokens'];
        }

        $tool_calls = isset($stream_result['tool_calls']) ? $stream_result['tool_calls'] : array();

        if (empty($tool_calls) || !$use_tools) {
            break;
        }

        // Execute tool calls and prepare for next round.
        $provider_name = $provider->get_name();

        if ($provider_name === 'openai') {
            // OpenAI Responses API: append function_call + function_call_output to input.
            foreach ($tool_calls as $fc) {
                // Pass through the full function_call item (id, name, arguments, call_id, status).
                $current_messages[] = $fc;

                $fc_args = json_decode(isset($fc['arguments']) ? (string) $fc['arguments'] : '{}', true);
                if (!is_array($fc_args)) {
                    $fc_args = array();
                }
                $tool_result = call_user_func($tool_handler, $fc['name'], $fc_args);

                $current_messages[] = array(
                    'type'    => 'function_call_output',
                    'call_id' => isset($fc['call_id']) ? $fc['call_id'] : '',
                    'output'  => (string) $tool_result,
                );
            }
        } elseif ($provider_name === 'anthropic') {
            // Anthropic: append assistant content blocks + user tool_result blocks.
            $content_blocks = array();
            if ($stream_text !== '') {
                $content_blocks[] = array('type' => 'text', 'text' => $stream_text);
            }
            foreach ($tool_calls as $tu) {
                $content_blocks[] = array(
                    'type'  => 'tool_use',
                    'id'    => isset($tu['id']) ? $tu['id'] : '',
                    'name'  => $tu['name'],
                    'input' => isset($tu['input']) ? $tu['input'] : array(),
                );
            }
            $current_messages[] = array('role' => 'assistant', 'content' => $content_blocks);

            $tool_results = array();
            foreach ($tool_calls as $tu) {
                $tool_result = call_user_func($tool_handler, $tu['name'], isset($tu['input']) ? $tu['input'] : array());
                $tool_results[] = array(
                    'type'        => 'tool_result',
                    'tool_use_id' => isset($tu['id']) ? $tu['id'] : '',
                    'content'     => (string) $tool_result,
                );
            }
            $current_messages[] = array('role' => 'user', 'content' => $tool_results);
        } else {
            // OpenRouter (Chat Completions format).
            $assistant_msg = array('role' => 'assistant', 'content' => $stream_text, 'tool_calls' => array());
            foreach ($tool_calls as $idx => $tc) {
                $assistant_msg['tool_calls'][] = array(
                    'id'       => isset($tc['id']) ? $tc['id'] : 'call_' . $idx,
                    'type'     => 'function',
                    'function' => array(
                        'name'      => $tc['name'],
                        'arguments' => wp_json_encode(isset($tc['input']) ? $tc['input'] : array()),
                    ),
                );
            }
            $current_messages[] = $assistant_msg;

            foreach ($tool_calls as $idx => $tc) {
                $tool_result = call_user_func($tool_handler, $tc['name'], isset($tc['input']) ? $tc['input'] : array());
                $current_messages[] = array(
                    'role'         => 'tool',
                    'tool_call_id' => isset($tc['id']) ? $tc['id'] : 'call_' . $idx,
                    'content'      => (string) $tool_result,
                );
            }
        }

        // Force text output on the last round — no more tool calls.
        if ($round >= $max_tool_rounds - 2) {
            $llm_options['tool_choice'] = 'none';
        }

        // Reset text for next round.
        $stream_text = '';
    }

    // Log usage.
    askwp_log_token_usage($total_usage);

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

    // Emit done event.
    askwp_stream_emit_done($slim_context['sources'], $total_usage);
    exit;
}

function askwp_stream_progress_handler()
{
    $origin_check = askwp_stream_validate_origin();
    if (is_wp_error($origin_check)) {
        status_header(403);
        wp_send_json_error(array('message' => 'Origin not allowed.'), 403);
    }

    $raw_stream_id = isset($_REQUEST['stream_id']) ? wp_unslash($_REQUEST['stream_id']) : '';
    $stream_id = askwp_stream_progress_sanitize_id($raw_stream_id);
    if ($stream_id === '') {
        wp_send_json_error(array('message' => 'Missing stream_id.'), 400);
    }

    $state = askwp_stream_progress_get($stream_id);
    if (!is_array($state)) {
        wp_send_json_success(array(
            'steps'      => array(),
            'done'       => false,
            'error'      => '',
            'updated_at' => 0,
        ));
    }

    wp_send_json_success($state);
}

add_action('wp_ajax_nopriv_askwp_stream_chat', 'askwp_stream_chat_handler');
add_action('wp_ajax_askwp_stream_chat', 'askwp_stream_chat_handler');
add_action('wp_ajax_nopriv_askwp_stream_progress', 'askwp_stream_progress_handler');
add_action('wp_ajax_askwp_stream_progress', 'askwp_stream_progress_handler');
