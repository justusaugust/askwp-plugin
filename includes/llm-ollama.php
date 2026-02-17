<?php

if (!defined('ABSPATH')) {
    exit;
}

class ASKWP_LLM_Ollama extends ASKWP_LLM_Provider
{
    public function get_name(): string
    {
        return 'ollama';
    }

    public function supports_tools(): bool
    {
        return false;
    }

    public function send(array $messages, string $system_prompt, array $options): mixed
    {
        $endpoint = trim((string) askwp_get_option('ollama_endpoint', 'http://localhost:11434'));
        if ($endpoint === '') {
            $endpoint = 'http://localhost:11434';
        }
        $endpoint = rtrim($endpoint, '/');

        $model = trim((string) askwp_get_option('model', 'llama3'));
        if ($model === '') {
            $model = 'llama3';
        }

        $max_tokens = isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 500;
        $max_tokens = max(120, min(4000, $max_tokens));

        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.7;

        $openai_messages = array();

        if ($system_prompt !== '') {
            $openai_messages[] = array(
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

            $openai_messages[] = array(
                'role'    => $role,
                'content' => $content,
            );
        }

        if (empty($openai_messages)) {
            return new WP_Error('askwp_ollama_invalid_input', 'No valid messages for Ollama.');
        }

        $payload = array(
            'model'       => $model,
            'messages'    => $openai_messages,
            'max_tokens'  => $max_tokens,
            'temperature' => $temperature,
            'stream'      => false,
        );

        $response = wp_remote_post($endpoint . '/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('askwp_ollama_transport', 'Transport error during Ollama request. Is Ollama running?');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            error_log('AskWP Ollama HTTP error: ' . $status . ' body: ' . wp_remote_retrieve_body($response));
            return new WP_Error('askwp_ollama_http_' . $status, 'Ollama API error (HTTP ' . $status . ').');
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return new WP_Error('askwp_ollama_decode', 'Could not decode Ollama response.');
        }

        $text = '';
        if (isset($decoded['choices'][0]['message']['content'])) {
            $text = trim((string) $decoded['choices'][0]['message']['content']);
        }

        if ($text === '') {
            return new WP_Error('askwp_ollama_empty', 'Ollama returned an empty response.');
        }

        $usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : array();

        return array(
            'text'  => $text,
            'usage' => array(
                'input_tokens'  => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0,
                'output_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0,
                'total_tokens'  => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : 0,
            ),
        );
    }
}
