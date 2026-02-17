<?php

if (!defined('ABSPATH')) {
    exit;
}

abstract class ASKWP_LLM_Provider
{
    /**
     * Send a chat completion request.
     *
     * @param array  $messages      Array of {role, content} messages.
     * @param string $system_prompt System prompt text.
     * @param array  $options       max_output_tokens, temperature, tools, tool_handler.
     * @return array|WP_Error       ['text' => string, 'usage' => ['input_tokens' => int, 'output_tokens' => int]] or WP_Error.
     */
    abstract public function send(array $messages, string $system_prompt, array $options): mixed;

    abstract public function get_name(): string;

    abstract public function supports_tools(): bool;
}
