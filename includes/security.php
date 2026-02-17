<?php

if (!defined('ABSPATH')) {
    exit;
}

function askwp_text_substr($value, $max_len)
{
    if ($max_len <= 0) {
        return (string) $value;
    }

    if (function_exists('mb_substr')) {
        return mb_substr((string) $value, 0, $max_len);
    }

    return substr((string) $value, 0, $max_len);
}

function askwp_text_lower($value)
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower((string) $value);
    }

    return strtolower((string) $value);
}

function askwp_get_client_ip()
{
    $candidates = array(
        isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : '',
        isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '',
        isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
    );

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        $parts = explode(',', $candidate);
        $ip = trim($parts[0]);

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '0.0.0.0';
}

function askwp_hash_ip($ip)
{
    return substr(hash('sha256', 'askwp|' . $ip . '|' . wp_salt('auth')), 0, 32);
}

function askwp_enforce_rate_limit($bucket, $limit, $window_seconds)
{
    $limit = absint($limit);
    $window_seconds = absint($window_seconds);

    if ($limit < 1 || $window_seconds < 1) {
        return true;
    }

    $ip_hash = askwp_hash_ip(askwp_get_client_ip());
    $time_bucket = (int) floor(time() / $window_seconds);
    $transient_key = 'askwp_rl_' . sanitize_key($bucket) . '_' . $ip_hash . '_' . $time_bucket;

    $count = (int) get_transient($transient_key);

    if ($count >= $limit) {
        return new WP_Error(
            'askwp_rate_limited',
            'Too many requests. Please try again later.',
            array('status' => 429)
        );
    }

    $count++;
    set_transient($transient_key, $count, $window_seconds + 60);

    return true;
}

function askwp_validate_origin($request)
{
    $origin = $request->get_header('origin');
    if (!is_string($origin) || trim($origin) === '') {
        return true;
    }

    $origin_host = wp_parse_url($origin, PHP_URL_HOST);
    $site_host = wp_parse_url(site_url(), PHP_URL_HOST);

    if (!$origin_host || !$site_host) {
        return new WP_Error(
            'askwp_origin_invalid',
            'Invalid origin header.',
            array('status' => 403)
        );
    }

    if (strtolower($origin_host) !== strtolower($site_host)) {
        return new WP_Error(
            'askwp_origin_blocked',
            'Origin not allowed.',
            array('status' => 403)
        );
    }

    return true;
}

function askwp_sanitize_message_text($text, $max_len)
{
    $text = wp_strip_all_tags((string) $text, true);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    $text = askwp_text_substr($text, $max_len);

    return $text;
}

function askwp_sanitize_line($value, $max_len)
{
    $value = sanitize_text_field((string) $value);
    $value = askwp_text_substr($value, $max_len);

    return trim($value);
}

function askwp_sanitize_paragraph($value, $max_len)
{
    $value = sanitize_textarea_field((string) $value);
    $value = trim($value);

    $value = askwp_text_substr($value, $max_len);

    return $value;
}
