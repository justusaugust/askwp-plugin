<?php

if (!defined('ABSPATH')) {
    exit;
}

class ASKWP_LLM_Anthropic extends ASKWP_LLM_Provider
{
    public function get_name(): string
    {
        return 'anthropic';
    }

    public function supports_tools(): bool
    {
        return true;
    }

    public function send(array $messages, string $system_prompt, array $options): mixed
    {
        $api_key = trim((string) askwp_get_option('api_key', ''));
        if ($api_key === '') {
            return new WP_Error('askwp_anthropic_missing_key', 'Anthropic API key is not configured.');
        }

        $model = trim((string) askwp_get_option('model', 'claude-sonnet-4-5-20250929'));
        if ($model === '') {
            $model = 'claude-sonnet-4-5-20250929';
        }

        $max_tokens = isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 500;
        $max_tokens = max(120, min(4000, $max_tokens));

        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.7;

        $tools = isset($options['tools']) && is_array($options['tools']) ? $options['tools'] : array();
        $tool_handler = isset($options['tool_handler']) && is_callable($options['tool_handler']) ? $options['tool_handler'] : null;

        $anthropic_messages = $this->normalize_messages($messages);
        if (empty($anthropic_messages)) {
            return new WP_Error('askwp_anthropic_invalid_input', 'No valid messages for Anthropic.');
        }

        $anthropic_tools = $this->convert_tools($tools);

        $total_usage = array('input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0);
        $max_rounds = (!empty($anthropic_tools) && $tool_handler) ? 4 : 1;

        for ($round = 0; $round < $max_rounds; $round++) {
            $payload = array(
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'temperature' => $temperature,
                'messages'   => $anthropic_messages,
            );

            if ($system_prompt !== '') {
                $payload['system'] = $system_prompt;
            }

            if (!empty($anthropic_tools)) {
                $payload['tools'] = $anthropic_tools;
            }

            $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
                'timeout' => 60,
                'headers' => array(
                    'x-api-key'         => $api_key,
                    'anthropic-version'  => '2023-06-01',
                    'Content-Type'       => 'application/json',
                ),
                'body' => wp_json_encode($payload),
            ));

            if (is_wp_error($response)) {
                return new WP_Error('askwp_anthropic_transport', 'Transport error during Anthropic request.');
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 200 || $status >= 300) {
                error_log('AskWP Anthropic HTTP error: ' . $status . ' body: ' . wp_remote_retrieve_body($response));
                return new WP_Error('askwp_anthropic_http_' . $status, 'Anthropic API error (HTTP ' . $status . ').');
            }

            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($decoded)) {
                return new WP_Error('askwp_anthropic_decode', 'Could not decode Anthropic response.');
            }

            $usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : array();
            $total_usage['input_tokens']  += isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0;
            $total_usage['output_tokens'] += isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0;
            $total_usage['total_tokens']   = $total_usage['input_tokens'] + $total_usage['output_tokens'];

            $content_blocks = isset($decoded['content']) && is_array($decoded['content']) ? $decoded['content'] : array();
            $tool_use_blocks = array();
            $text_parts = array();

            foreach ($content_blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (isset($block['type']) && $block['type'] === 'tool_use') {
                    $tool_use_blocks[] = $block;
                }
                if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    $text_parts[] = trim((string) $block['text']);
                }
            }

            if (empty($tool_use_blocks) || !$tool_handler) {
                $text = trim(implode("\n", array_filter($text_parts)));
                if ($text === '') {
                    return new WP_Error('askwp_anthropic_empty', 'Anthropic returned an empty response.');
                }

                return array(
                    'text'  => $text,
                    'usage' => $total_usage,
                );
            }

            // Append assistant message with all content blocks.
            $anthropic_messages[] = array(
                'role'    => 'assistant',
                'content' => $content_blocks,
            );

            // Execute tool calls and build tool_result blocks.
            $tool_results = array();
            foreach ($tool_use_blocks as $tu) {
                $tc_name = isset($tu['name']) ? (string) $tu['name'] : '';
                $tc_input = isset($tu['input']) && is_array($tu['input']) ? $tu['input'] : array();
                $tc_id = isset($tu['id']) ? (string) $tu['id'] : '';

                $result = call_user_func($tool_handler, $tc_name, $tc_input);

                $tool_results[] = array(
                    'type'       => 'tool_result',
                    'tool_use_id' => $tc_id,
                    'content'    => (string) $result,
                );
            }

            $anthropic_messages[] = array(
                'role'    => 'user',
                'content' => $tool_results,
            );
        }

        return new WP_Error('askwp_anthropic_tool_loop', 'Tool calls could not be completed within allowed rounds.');
    }

    private function normalize_messages(array $messages): array
    {
        $normalized = array();

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

    private function convert_tools(array $openai_tools): array
    {
        $anthropic_tools = array();

        foreach ($openai_tools as $tool) {
            if (!is_array($tool) || !isset($tool['name'])) {
                continue;
            }

            $anthropic_tool = array(
                'name'        => (string) $tool['name'],
                'description' => isset($tool['description']) ? (string) $tool['description'] : '',
                'input_schema' => isset($tool['parameters']) && is_array($tool['parameters'])
                    ? $tool['parameters']
                    : array('type' => 'object', 'properties' => new \stdClass()),
            );

            $anthropic_tools[] = $anthropic_tool;
        }

        return $anthropic_tools;
    }
}
