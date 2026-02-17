<?php

if (!defined('ABSPATH')) {
    exit;
}

function askwp_register_form_route()
{
    register_rest_route('askwp/v1', '/submit_form', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'askwp_rest_submit_form_handler',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'askwp_register_form_route');

function askwp_rest_submit_form_handler($request)
{
    $origin_check = askwp_validate_origin($request);
    if (is_wp_error($origin_check)) {
        return $origin_check;
    }

    $rate_limit = (int) askwp_get_option('form_rate_limit_daily', 10);
    $rate_check = askwp_enforce_rate_limit('form', $rate_limit, DAY_IN_SECONDS);
    if (is_wp_error($rate_check)) {
        return $rate_check;
    }

    if (!(bool) askwp_get_option('form_enabled', 0)) {
        return new WP_Error('askwp_form_disabled', 'Form submissions are not enabled.', array('status' => 403));
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_Error('askwp_invalid_payload', 'Invalid JSON payload.', array('status' => 400));
    }

    $payload_encoded = wp_json_encode($payload);
    if (is_string($payload_encoded) && strlen($payload_encoded) > 30000) {
        return new WP_Error('askwp_payload_too_large', 'Payload too large.', array('status' => 413));
    }

    // Load field schema.
    $fields_raw = askwp_get_option('form_fields', askwp_default_form_fields());
    $fields = is_string($fields_raw) ? json_decode($fields_raw, true) : $fields_raw;
    if (!is_array($fields) || empty($fields)) {
        $fields = json_decode(askwp_default_form_fields(), true);
    }

    // Validate and collect field values.
    $validated = array();
    $email_value = '';

    foreach ($fields as $field) {
        if (!is_array($field) || empty($field['name'])) {
            continue;
        }

        $name = sanitize_key($field['name']);
        $type = isset($field['type']) ? (string) $field['type'] : 'text';
        $label = isset($field['label']) ? (string) $field['label'] : $name;
        $required = !empty($field['required']);
        $maxlength = isset($field['maxlength']) ? absint($field['maxlength']) : 500;
        if ($maxlength < 1) {
            $maxlength = 500;
        }

        $raw_value = isset($payload[$name]) ? $payload[$name] : '';

        // Sanitize based on type.
        if ($type === 'textarea') {
            $value = askwp_sanitize_paragraph((string) $raw_value, $maxlength);
        } elseif ($type === 'email') {
            $value = sanitize_email((string) $raw_value);
        } elseif ($type === 'checkbox') {
            $value = !empty($raw_value) ? 'Yes' : 'No';
        } else {
            $value = askwp_sanitize_line((string) $raw_value, $maxlength);
        }

        // Required check.
        if ($required && ($value === '' || ($type === 'checkbox' && $value === 'No'))) {
            return new WP_Error(
                'askwp_missing_field_' . $name,
                'Required field missing: ' . $label,
                array('status' => 400)
            );
        }

        // Email validation.
        if ($type === 'email' && $value !== '' && !is_email($value)) {
            return new WP_Error(
                'askwp_invalid_email',
                'Invalid email address.',
                array('status' => 400)
            );
        }

        // Select validation: check against options if defined.
        if ($type === 'select' && $value !== '' && !empty($field['options'])) {
            $options = is_string($field['options']) ? array_map('trim', explode(',', $field['options'])) : (array) $field['options'];
            if (!in_array($value, $options, true)) {
                return new WP_Error(
                    'askwp_invalid_option_' . $name,
                    'Invalid option for: ' . $label,
                    array('status' => 400)
                );
            }
        }

        $validated[$name] = array(
            'label' => $label,
            'value' => $value,
            'type'  => $type,
        );

        // Track first email field for Reply-To.
        if ($type === 'email' && $email_value === '' && $value !== '') {
            $email_value = $value;
        }
    }

    // Build email.
    $recipient = (string) askwp_get_option('form_email_to', get_option('admin_email'));
    if (!is_email($recipient)) {
        $recipient = get_option('admin_email');
    }

    $bot_name = (string) askwp_get_option('bot_name', 'Chat Assistant');
    $subject_template = (string) askwp_get_option('form_email_subject', 'New submission from {bot_name}');
    $subject = str_replace('{bot_name}', $bot_name, $subject_template);

    // Replace field placeholders in subject.
    foreach ($validated as $name => $field_data) {
        $subject = str_replace('{' . $name . '}', $field_data['value'], $subject);
    }

    $body_lines = array(
        'New Form Submission',
        '===================',
        'Time: ' . current_time('mysql'),
        '',
    );

    foreach ($validated as $name => $field_data) {
        if ($field_data['value'] === '' && !in_array($field_data['type'], array('checkbox'), true)) {
            continue;
        }
        $body_lines[] = $field_data['label'] . ': ' . $field_data['value'];
    }

    $page_url = isset($payload['page_url']) ? esc_url_raw((string) $payload['page_url']) : '';
    if ($page_url !== '') {
        $body_lines[] = '';
        $body_lines[] = 'Submitted from: ' . $page_url;
    }

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
    );

    if ($email_value !== '' && is_email($email_value)) {
        $reply_name = '';
        // Try to find a name field for the Reply-To header.
        foreach ($validated as $field_data) {
            if (in_array($field_data['type'], array('text'), true) && $field_data['value'] !== '') {
                $reply_name = $field_data['value'];
                break;
            }
        }
        if ($reply_name !== '') {
            $headers[] = 'Reply-To: ' . $reply_name . ' <' . $email_value . '>';
        } else {
            $headers[] = 'Reply-To: ' . $email_value;
        }
    }

    $sent = wp_mail($recipient, $subject, implode("\n", $body_lines), $headers);

    if (!$sent) {
        return new WP_Error('askwp_mail_failed', 'Email could not be sent.', array('status' => 500));
    }

    return new WP_REST_Response(array('ok' => true), 200);
}
