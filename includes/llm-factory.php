<?php

if (!defined('ABSPATH')) {
    exit;
}

function askwp_get_llm_provider(): ASKWP_LLM_Provider
{
    $provider = strtolower(trim((string) askwp_get_option('llm_provider', 'openai')));

    switch ($provider) {
        case 'anthropic':
            return new ASKWP_LLM_Anthropic();
        case 'ollama':
            return new ASKWP_LLM_Ollama();
        default:
            return new ASKWP_LLM_OpenAI();
    }
}
