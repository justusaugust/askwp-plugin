<?php

if (!defined('ABSPATH')) {
    exit;
}

class ASKWP_LLM_OpenRouter extends ASKWP_LLM_Provider
{
    public function get_name(): string
    {
        return 'openrouter';
    }

    public function supports_tools(): bool
    {
        return true;
    }

    public function supports_streaming(): bool
    {
        return true;
    }

    public function send_stream(array $messages, string $system_prompt, array $options, callable $on_delta): mixed
    {
        $api_key = trim((string) askwp_get_option('api_key', ''));
        if ($api_key === '') {
            return new WP_Error('askwp_openrouter_missing_key', 'OpenRouter API key is not configured.');
        }

        $model = trim((string) askwp_get_option('model', 'openai/gpt-4o'));
        if ($model === '') {
            $model = 'openai/gpt-4o';
        }

        $max_tokens = isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 500;
        $max_tokens = max(120, min(4000, $max_tokens));
        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.7;

        $tools = isset($options['tools']) && is_array($options['tools']) ? $options['tools'] : array();

        $chat_messages = $this->normalize_messages($messages, $system_prompt);
        if (empty($chat_messages)) {
            return new WP_Error('askwp_openrouter_invalid_input', 'No valid messages for OpenRouter.');
        }

        $chat_tools = array();
        foreach ($tools as $tool) {
            if (!is_array($tool) || !isset($tool['name'])) { continue; }
            $chat_tools[] = array(
                'type'     => 'function',
                'function' => array(
                    'name'        => (string) $tool['name'],
                    'description' => isset($tool['description']) ? (string) $tool['description'] : '',
                    'parameters'  => isset($tool['parameters']) && is_array($tool['parameters'])
                        ? $tool['parameters']
                        : array('type' => 'object', 'properties' => new \stdClass()),
                ),
            );
        }

        $payload = array(
            'model'       => $model,
            'messages'    => $chat_messages,
            'max_tokens'  => $max_tokens,
            'temperature' => $temperature,
            'stream'      => true,
        );

        if (!empty($chat_tools)) {
            $payload['tools'] = $chat_tools;
            if (isset($options['tool_choice'])) {
                $payload['tool_choice'] = $options['tool_choice'];
            }
        }

        $buffer = '';
        $usage = array('input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0);
        $tool_calls_map = array();

        $write_callback = function ($ch, $chunk) use (&$buffer, $on_delta, &$usage, &$tool_calls_map) {
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if ($line === '' || strpos($line, 'data: ') !== 0) { continue; }

                $json_str = substr($line, 6);
                if ($json_str === '[DONE]') { continue; }

                $event = json_decode($json_str, true);
                if (!is_array($event)) { continue; }

                $delta = isset($event['choices'][0]['delta']) ? $event['choices'][0]['delta'] : array();

                if (isset($delta['content']) && (string) $delta['content'] !== '') {
                    call_user_func($on_delta, (string) $delta['content']);
                }

                if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = isset($tc['index']) ? (int) $tc['index'] : 0;
                        if (!isset($tool_calls_map[$idx])) {
                            $tool_calls_map[$idx] = array(
                                'id'        => isset($tc['id']) ? (string) $tc['id'] : '',
                                'name'      => '',
                                'arguments' => '',
                            );
                        }
                        if (isset($tc['id']) && $tc['id'] !== '') {
                            $tool_calls_map[$idx]['id'] = (string) $tc['id'];
                        }
                        if (isset($tc['function']['name'])) {
                            $tool_calls_map[$idx]['name'] = (string) $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $tool_calls_map[$idx]['arguments'] .= (string) $tc['function']['arguments'];
                        }
                    }
                }

                if (isset($event['usage'])) {
                    $u = $event['usage'];
                    $usage['input_tokens']  = isset($u['prompt_tokens']) ? (int) $u['prompt_tokens'] : $usage['input_tokens'];
                    $usage['output_tokens'] = isset($u['completion_tokens']) ? (int) $u['completion_tokens'] : $usage['output_tokens'];
                    $usage['total_tokens']  = $usage['input_tokens'] + $usage['output_tokens'];
                }
            }

            return strlen($chunk);
        };

        $result = $this->stream_request(
            'https://openrouter.ai/api/v1/chat/completions',
            array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
                'X-Title'       => (string) get_bloginfo('name'),
            ),
            $payload,
            $write_callback
        );

        if (is_wp_error($result)) {
            return new WP_Error('askwp_openrouter_transport', $result->get_error_message());
        }

        $tool_calls = array();
        foreach ($tool_calls_map as $tc) {
            $tool_calls[] = array(
                'id'    => $tc['id'],
                'name'  => $tc['name'],
                'input' => json_decode($tc['arguments'], true) ?: array(),
            );
        }

        return array(
            'usage'      => $usage,
            'tool_calls' => $tool_calls,
        );
    }

    public function send(array $messages, string $system_prompt, array $options): mixed
    {
        $api_key = trim((string) askwp_get_option('api_key', ''));
        if ($api_key === '') {
            return new WP_Error('askwp_openrouter_missing_key', 'OpenRouter API key is not configured.');
        }

        $model = trim((string) askwp_get_option('model', 'openai/gpt-4o'));
        if ($model === '') {
            $model = 'openai/gpt-4o';
        }

        $max_tokens = isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 500;
        $max_tokens = max(120, min(4000, $max_tokens));

        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.7;

        $tools = isset($options['tools']) && is_array($options['tools']) ? $options['tools'] : array();
        $tool_handler = isset($options['tool_handler']) && is_callable($options['tool_handler']) ? $options['tool_handler'] : null;

        $chat_messages = $this->normalize_messages($messages, $system_prompt);
        if (empty($chat_messages)) {
            return new WP_Error('askwp_openrouter_invalid_input', 'No valid messages for OpenRouter.');
        }

        // Convert tool schemas from OpenAI Responses format to Chat Completions format.
        $chat_tools = array();
        foreach ($tools as $tool) {
            if (!is_array($tool) || !isset($tool['name'])) {
                continue;
            }
            $chat_tools[] = array(
                'type'     => 'function',
                'function' => array(
                    'name'        => (string) $tool['name'],
                    'description' => isset($tool['description']) ? (string) $tool['description'] : '',
                    'parameters'  => isset($tool['parameters']) && is_array($tool['parameters'])
                        ? $tool['parameters']
                        : array('type' => 'object', 'properties' => new \stdClass()),
                ),
            );
        }

        $total_usage = array('input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0);
        $max_rounds = (!empty($chat_tools) && $tool_handler) ? 6 : 1;
        $tool_used = false;

        for ($round = 0; $round < $max_rounds; $round++) {
            $payload = array(
                'model'       => $model,
                'messages'    => $chat_messages,
                'max_tokens'  => $max_tokens,
                'temperature' => $temperature,
            );

            if (!empty($chat_tools)) {
                $payload['tools'] = $chat_tools;
                if ($tool_used && $round >= $max_rounds - 1) {
                    $payload['tool_choice'] = 'none';
                }
            }

            $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => (string) get_bloginfo('name'),
                ),
                'body' => wp_json_encode($payload),
            ));

            if (is_wp_error($response)) {
                return new WP_Error('askwp_openrouter_transport', 'Transport error during OpenRouter request.');
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 200 || $status >= 300) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log('AskWP OpenRouter HTTP error: ' . $status . ' body: ' . wp_remote_retrieve_body($response));
                }
                return new WP_Error('askwp_openrouter_http_' . $status, 'OpenRouter API error (HTTP ' . $status . ').');
            }

            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($decoded)) {
                return new WP_Error('askwp_openrouter_decode', 'Could not decode OpenRouter response.');
            }

            $usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : array();
            $total_usage['input_tokens']  += isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0;
            $total_usage['output_tokens'] += isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0;
            $total_usage['total_tokens']   = $total_usage['input_tokens'] + $total_usage['output_tokens'];

            $choice = isset($decoded['choices'][0]['message']) && is_array($decoded['choices'][0]['message'])
                ? $decoded['choices'][0]['message']
                : array();

            $tool_calls = isset($choice['tool_calls']) && is_array($choice['tool_calls']) ? $choice['tool_calls'] : array();

            if (empty($tool_calls) || !$tool_handler) {
                $text = isset($choice['content']) ? trim((string) $choice['content']) : '';
                if ($text === '') {
                    return new WP_Error('askwp_openrouter_empty', 'OpenRouter returned an empty response.');
                }

                return array(
                    'text'  => $text,
                    'usage' => $total_usage,
                );
            }

            $tool_used = true;

            // Append assistant message with tool calls.
            $chat_messages[] = $choice;

            // Execute tool calls and append results.
            foreach ($tool_calls as $tc) {
                $tc_id = isset($tc['id']) ? (string) $tc['id'] : '';
                $tc_name = isset($tc['function']['name']) ? (string) $tc['function']['name'] : '';
                $tc_args = json_decode(isset($tc['function']['arguments']) ? (string) $tc['function']['arguments'] : '{}', true);
                if (!is_array($tc_args)) {
                    $tc_args = array();
                }

                $result = call_user_func($tool_handler, $tc_name, $tc_args);

                $chat_messages[] = array(
                    'role'         => 'tool',
                    'tool_call_id' => $tc_id,
                    'content'      => (string) $result,
                );
            }
        }

        return new WP_Error('askwp_openrouter_tool_loop', 'Tool calls could not be completed within allowed rounds.');
    }

    private function normalize_messages(array $messages, string $system_prompt): array
    {
        $normalized = array();

        if ($system_prompt !== '') {
            $normalized[] = array(
                'role'    => 'system',
                'content' => $system_prompt,
            );
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            // Pass through tool role messages and assistant messages with tool_calls.
            $role = isset($message['role']) ? strtolower((string) $message['role']) : 'user';
            if ($role === 'tool' || ($role === 'assistant' && isset($message['tool_calls']))) {
                $normalized[] = $message;
                continue;
            }

            if (!in_array($role, array('user', 'assistant'), true)) {
                continue;
            }

            if (isset($message['content']) && is_array($message['content'])) {
                $parts = $this->normalize_multimodal_content($message['content']);
                if (empty($parts)) {
                    continue;
                }

                $normalized[] = array(
                    'role'    => $role,
                    'content' => $parts,
                );
                continue;
            }

            $content = isset($message['content']) ? trim((string) $message['content']) : '';
            if ($content === '') {
                continue;
            }

            $normalized[] = array(
                'role'    => $role,
                'content' => $content,
            );
        }

        return $normalized;
    }

    private function normalize_multimodal_content(array $content_blocks): array
    {
        $parts = array();

        foreach ($content_blocks as $block) {
            if (!is_array($block) || !isset($block['type'])) {
                continue;
            }

            $type = strtolower((string) $block['type']);

            if ($type === 'text') {
                $text = isset($block['text']) ? trim((string) $block['text']) : '';
                if ($text !== '') {
                    $parts[] = array(
                        'type' => 'text',
                        'text' => $text,
                    );
                }
                continue;
            }

            if ($type === 'image' || $type === 'image_url') {
                $image_url = '';

                if (isset($block['data_url'])) {
                    $image_url = trim((string) $block['data_url']);
                } elseif (isset($block['image_url']) && is_array($block['image_url']) && isset($block['image_url']['url'])) {
                    $image_url = trim((string) $block['image_url']['url']);
                } elseif (isset($block['image_url'])) {
                    $image_url = trim((string) $block['image_url']);
                }

                if ($image_url !== '') {
                    $parts[] = array(
                        'type'      => 'image_url',
                        'image_url' => array('url' => $image_url),
                    );
                }
            }
        }

        return $parts;
    }
}
