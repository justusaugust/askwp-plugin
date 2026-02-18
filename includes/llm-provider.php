<?php

if (!defined('ABSPATH')) {
    exit;
}

abstract class ASKWP_LLM_Provider
{
    private $_stream_write_fn = null;
    private $_stream_target_url = null;
    private $_stream_raw_data = '';

    /**
     * Send a chat completion request.
     *
     * @param array  $messages      Array of {role, content} messages.
     * @param string $system_prompt System prompt text.
     * @param array  $options       max_output_tokens, temperature, tools, tool_handler.
     * @return array|WP_Error       ['text' => string, 'usage' => ['input_tokens' => int, 'output_tokens' => int]] or WP_Error.
     */
    abstract public function send(array $messages, string $system_prompt, array $options): mixed;

    /**
     * Send a streaming chat completion request.
     *
     * @param array    $messages      Array of {role, content} messages.
     * @param string   $system_prompt System prompt text.
     * @param array    $options       max_output_tokens, temperature, tools, tool_handler.
     * @param callable $on_delta      Callback invoked with each text chunk: function(string $text).
     * @return array|WP_Error         ['usage' => [...], 'tool_calls' => [...]] or WP_Error.
     */
    public function send_stream(array $messages, string $system_prompt, array $options, callable $on_delta): mixed
    {
        return new WP_Error('askwp_stream_unsupported', 'This provider does not support streaming.');
    }

    abstract public function get_name(): string;

    abstract public function supports_tools(): bool;

    public function supports_streaming(): bool
    {
        return false;
    }

    /**
     * Execute a streaming HTTP POST via wp_remote_post with real-time chunk processing.
     *
     * Uses the http_api_curl action to set CURLOPT_WRITEFUNCTION on the underlying
     * cURL handle, enabling real-time SSE chunk delivery while keeping the request
     * routed through the WordPress HTTP API.
     *
     * @param string   $url            API endpoint URL.
     * @param array    $headers        Associative array of HTTP headers.
     * @param array    $payload        Request body (will be JSON-encoded).
     * @param callable $write_callback cURL WRITEFUNCTION callback: function($ch, $chunk).
     * @return true|WP_Error           True on success, WP_Error on failure.
     */
    protected function stream_request($url, $headers, $payload, callable $write_callback)
    {
        $this->_stream_write_fn = $write_callback;
        $this->_stream_target_url = $url;
        $this->_stream_raw_data = '';

        // Wrap the write callback to capture raw response for error logging.
        $original_fn = $write_callback;
        $self = $this;
        $this->_stream_write_fn = function ($ch, $chunk) use ($original_fn, $self) {
            $self->_stream_raw_data .= $chunk;
            return call_user_func($original_fn, $ch, $chunk);
        };

        add_action('http_api_curl', array($this, 'askwp_configure_stream_curl'), 10, 3);

        $response = wp_remote_post($url, array(
            'timeout'    => 60,
            'headers'    => $headers,
            'body'       => wp_json_encode($payload),
            'decompress' => false,
        ));

        remove_action('http_api_curl', array($this, 'askwp_configure_stream_curl'), 10);
        $this->_stream_write_fn = null;
        $this->_stream_target_url = null;

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('AskWP stream_request HTTP ' . $status . ' body: ' . substr($this->_stream_raw_data, 0, 2000));
            }
            $this->_stream_raw_data = '';
            return new WP_Error('askwp_stream_http_' . $status, 'API error (HTTP ' . $status . ').');
        }

        $this->_stream_raw_data = '';
        return true;
    }

    /**
     * Hook callback: inject CURLOPT_WRITEFUNCTION for SSE streaming.
     *
     * @param resource $handle      cURL handle.
     * @param array    $parsed_args WP HTTP request args.
     * @param string   $url         Request URL.
     */
    public function askwp_configure_stream_curl($handle, $parsed_args, $url)
    {
        if ($url !== $this->_stream_target_url || !is_callable($this->_stream_write_fn)) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Used inside http_api_curl hook, the official WordPress mechanism for customizing cURL behavior.
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, $this->_stream_write_fn);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        curl_setopt($handle, CURLOPT_BUFFERSIZE, 256);
        // Disable low-speed limit — LLM APIs can pause while "thinking" before streaming.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        curl_setopt($handle, CURLOPT_LOW_SPEED_LIMIT, 0);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        curl_setopt($handle, CURLOPT_LOW_SPEED_TIME, 0);
    }
}
