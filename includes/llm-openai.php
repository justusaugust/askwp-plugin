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
                error_log('AskWP OpenAI HTTP error: ' . $status . ' body: ' . wp_remote_retrieve_body($response));
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

            $role = isset($message['role']) ? strtolower((string) $message['role']) : 'user';
            if (!in_array($role, array('user', 'assistant'), true)) {
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
