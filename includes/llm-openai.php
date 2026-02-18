<?php

if (!defined('ABSPATH')) {
    exit;
}

class ASKWP_LLM_OpenAI extends ASKWP_LLM_Provider
{
    public function get_name(): string
    {
        return 'openai';
    }

    public function supports_tools(): bool
    {
        return true;
    }

    public function supports_streaming(): bool
    {
        return true;
    }

    public function send(array $messages, string $system_prompt, array $options): mixed
    {
        $api_key = trim((string) askwp_get_option('api_key', ''));
        if ($api_key === '') {
            return new WP_Error('askwp_openai_missing_key', 'OpenAI API key is not configured.');
        }

        $model = trim((string) askwp_get_option('model', 'gpt-4o'));
        if ($model === '') {
            $model = 'gpt-4o';
        }

        $max_output_tokens = isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 500;
        $max_output_tokens = max(120, min(4000, $max_output_tokens));

        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.7;

        $tools = isset($options['tools']) && is_array($options['tools']) ? $options['tools'] : array();
        $tool_handler = isset($options['tool_handler']) && is_callable($options['tool_handler']) ? $options['tool_handler'] : null;

        $input = $this->normalize_messages($messages, $system_prompt);
        if (empty($input)) {
            return new WP_Error('askwp_openai_invalid_input', 'No valid messages for OpenAI.');
        }

        $total_usage = array('input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0);
        $max_rounds = (!empty($tools) && $tool_handler) ? 4 : 1;
        $tool_used = false;

        for ($round = 0; $round < $max_rounds; $round++) {
            $payload = array(
                'model'            => $model,
                'store'            => false,
                'max_output_tokens' => $max_output_tokens,
                'temperature'      => $temperature,
                'input'            => $input,
            );

            if (!empty($tools)) {
                $payload['tools'] = $tools;
                if ($tool_used && $round >= $max_rounds - 1) {
                    $payload['tool_choice'] = 'none';
                }
            }

            $response = wp_remote_post('https://api.openai.com/v1/responses', array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode($payload),
            ));

            if (is_wp_error($response)) {
                return new WP_Error('askwp_openai_transport', 'Transport error during OpenAI request.');
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 200 || $status >= 300) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log('AskWP OpenAI HTTP error: ' . $status . ' body: ' . wp_remote_retrieve_body($response));
                }
                return new WP_Error('askwp_openai_http_' . $status, 'OpenAI API error (HTTP ' . $status . ').');
            }

            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($decoded)) {
                return new WP_Error('askwp_openai_decode', 'Could not decode OpenAI response.');
            }

            $round_usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : array();
            $total_usage['input_tokens']  += isset($round_usage['input_tokens']) ? (int) $round_usage['input_tokens'] : 0;
            $total_usage['output_tokens'] += isset($round_usage['output_tokens']) ? (int) $round_usage['output_tokens'] : 0;
            $total_usage['total_tokens']  += isset($round_usage['total_tokens']) ? (int) $round_usage['total_tokens'] : 0;

            $function_calls = array();
            if (!empty($decoded['output']) && is_array($decoded['output'])) {
                foreach ($decoded['output'] as $item) {
                    if (isset($item['type']) && $item['type'] === 'function_call') {
                        $function_calls[] = $item;
                    }
                }
            }

            if (empty($function_calls) || !$tool_handler) {
                $text = $this->extract_text($decoded);
                if ($text === '') {
                    return new WP_Error('askwp_openai_empty', 'OpenAI returned an empty response.');
                }

                return array(
                    'text'  => $text,
                    'usage' => $total_usage,
                );
            }

            $tool_used = true;
            foreach ($decoded['output'] as $output_item) {
                $input[] = $output_item;
            }

            foreach ($function_calls as $fc) {
                $fc_name = isset($fc['name']) ? (string) $fc['name'] : '';
                $fc_args = json_decode(isset($fc['arguments']) ? (string) $fc['arguments'] : '{}', true);
                if (!is_array($fc_args)) {
                    $fc_args = array();
                }

                $tool_result = call_user_func($tool_handler, $fc_name, $fc_args);

                $input[] = array(
                    'type'    => 'function_call_output',
                    'call_id' => isset($fc['call_id']) ? (string) $fc['call_id'] : '',
                    'output'  => (string) $tool_result,
                );
            }
        }

        return new WP_Error('askwp_openai_tool_loop', 'Tool calls could not be completed within allowed rounds.');
    }

    public function send_stream(array $messages, string $system_prompt, array $options, callable $on_delta): mixed
    {
        $api_key = trim((string) askwp_get_option('api_key', ''));
        if ($api_key === '') {
            return new WP_Error('askwp_openai_missing_key', 'OpenAI API key is not configured.');
        }

        $model = trim((string) askwp_get_option('model', 'gpt-4o'));
        if ($model === '') {
            $model = 'gpt-4o';
        }

        $max_output_tokens = isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 500;
        $max_output_tokens = max(120, min(4000, $max_output_tokens));
        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.7;

        $tools = isset($options['tools']) && is_array($options['tools']) ? $options['tools'] : array();

        $input = $this->normalize_messages($messages, $system_prompt);
        if (empty($input)) {
            return new WP_Error('askwp_openai_invalid_input', 'No valid messages for OpenAI.');
        }

        $payload = array(
            'model'            => $model,
            'store'            => false,
            'max_output_tokens' => $max_output_tokens,
            'temperature'      => $temperature,
            'input'            => $input,
            'stream'           => true,
        );

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            if (isset($options['tool_choice'])) {
                $payload['tool_choice'] = $options['tool_choice'];
            }
        }

        $buffer = '';
        $usage = array('input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0);
        $function_calls = array();
        $fc_items_map = array(); // Keyed by item_id, populated from response.output_item.added.

        $write_callback = function ($ch, $chunk) use (&$buffer, $on_delta, &$usage, &$function_calls, &$fc_items_map) {
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if ($line === '' || strpos($line, 'data: ') !== 0) {
                    continue;
                }

                $json_str = substr($line, 6);
                if ($json_str === '[DONE]') {
                    continue;
                }

                $event = json_decode($json_str, true);
                if (!is_array($event)) {
                    continue;
                }

                $type = isset($event['type']) ? $event['type'] : '';

                if ($type === 'response.output_text.delta' && isset($event['delta'])) {
                    call_user_func($on_delta, (string) $event['delta']);
                }

                // Capture function call metadata (call_id, name) when the item is first added.
                if ($type === 'response.output_item.added' && isset($event['item']['type']) && $event['item']['type'] === 'function_call') {
                    $item = $event['item'];
                    $item_id = isset($item['id']) ? (string) $item['id'] : '';
                    if ($item_id !== '') {
                        $fc_items_map[$item_id] = array(
                            'call_id' => isset($item['call_id']) ? (string) $item['call_id'] : '',
                            'name'    => isset($item['name']) ? (string) $item['name'] : '',
                        );
                    }
                }

                // Merge with metadata captured above to build the complete function_call item.
                if ($type === 'response.function_call_arguments.done') {
                    $item_id = isset($event['item_id']) ? (string) $event['item_id'] : '';
                    $meta = isset($fc_items_map[$item_id]) ? $fc_items_map[$item_id] : array();

                    $function_calls[] = array(
                        'type'      => 'function_call',
                        'id'        => $item_id,
                        'name'      => isset($meta['name']) ? $meta['name'] : '',
                        'arguments' => isset($event['arguments']) ? (string) $event['arguments'] : '{}',
                        'call_id'   => isset($meta['call_id']) ? $meta['call_id'] : '',
                        'status'    => 'completed',
                    );
                }

                if ($type === 'response.completed' && isset($event['response']['usage'])) {
                    $u = $event['response']['usage'];
                    $usage['input_tokens']  = isset($u['input_tokens']) ? (int) $u['input_tokens'] : 0;
                    $usage['output_tokens'] = isset($u['output_tokens']) ? (int) $u['output_tokens'] : 0;
                    $usage['total_tokens']  = isset($u['total_tokens']) ? (int) $u['total_tokens'] : 0;
                }
            }

            return strlen($chunk);
        };

        $result = $this->stream_request(
            'https://api.openai.com/v1/responses',
            array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            $payload,
            $write_callback
        );

        if (is_wp_error($result)) {
            return new WP_Error('askwp_openai_transport', $result->get_error_message());
        }

        return array(
            'usage'          => $usage,
            'tool_calls'     => $function_calls,
        );
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

            // Pass through OpenAI Responses API tool items (function_call, function_call_output).
            if (isset($message['type']) && in_array($message['type'], array('function_call', 'function_call_output'), true)) {
                $normalized[] = $message;
                continue;
            }

            $role = isset($message['role']) ? strtolower((string) $message['role']) : 'user';
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
                        'type' => 'input_text',
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
                        'type'      => 'input_image',
                        'image_url' => $image_url,
                    );
                }
            }
        }

        return $parts;
    }

    private function extract_text(array $decoded): string
    {
        if (isset($decoded['output_text']) && is_string($decoded['output_text'])) {
            $text = trim($decoded['output_text']);
            if ($text !== '') {
                return $text;
            }
        }

        $buffer = array();

        if (!empty($decoded['output']) && is_array($decoded['output'])) {
            foreach ($decoded['output'] as $item) {
                if (!empty($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $content_item) {
                        if (isset($content_item['text']) && is_string($content_item['text'])) {
                            $line = trim($content_item['text']);
                            if ($line !== '') {
                                $buffer[] = $line;
                            }
                        }
                    }
                }
            }
        }

        return trim(implode("\n", $buffer));
    }
}
