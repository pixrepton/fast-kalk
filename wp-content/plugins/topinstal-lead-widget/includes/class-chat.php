<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI chat for step B — conditional questions, multi-field extraction, skip per question.
 */
final class Topinstal_Lead_Widget_Chat {
    const MAX_ASSISTANT_QUESTIONS = 7;

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle($request) {
        $rate = Topinstal_Lead_Widget_Rate_Limit::check($request);
        if (is_wp_error($rate)) {
            return $rate;
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('tilw_invalid_body', 'Invalid JSON body.', array('status' => 400));
        }

        $messages = isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : array();
        $collected = Topinstal_Lead_Widget_Defaults::sanitize_collected(
            isset($body['collected']) && is_array($body['collected']) ? $body['collected'] : array()
        );

        if (!empty($body['refinement_mode'])) {
            $collected['refinement_active'] = true;
        }

        if (!empty($body['skip_current'])) {
            return self::handle_skip_current($collected, $messages);
        }

        $empty_user = self::last_user_message_is_empty($messages);
        if ($empty_user) {
            return new WP_REST_Response(
                array(
                    'ok' => false,
                    'message' => 'Wpisz odpowiedź lub wybierz jedną z opcji powyżej.',
                ),
                400
            );
        }

        $last_user = self::last_user_message($messages);
        if ($last_user !== '') {
            $collected = self::apply_answer_to_collected($collected, $messages);
            $collected = Topinstal_Lead_Widget_Defaults::sanitize_collected($collected);
        }

        $system = self::build_system_prompt($collected);
        $refinement_asked = isset($body['refinement_asked']) ? max(0, (int) $body['refinement_asked']) : 0;

        $deepseek = self::call_deepseek($system, $messages);
        if (!empty($deepseek['ok']) && isset($deepseek['parsed']) && is_array($deepseek['parsed'])) {
            if (!empty($deepseek['parsed']['collected_delta'])) {
                $collected = array_merge($collected, self::scrub_ai_collected_delta($deepseek['parsed']['collected_delta'], $collected));
                $collected = Topinstal_Lead_Widget_Defaults::sanitize_collected($collected);
            }
            return self::build_chat_response($collected, $deepseek['parsed'], $refinement_asked);
        }
        if (!empty($deepseek['fatal'])) {
            return new WP_Error('tilw_deepseek_contract_error', 'DeepSeek response contract error.', array('status' => 502));
        }

        $api_key = Topinstal_Lead_Widget_Plugin::get_option('anthropic_api_key', '');
        if ($api_key === '') {
            return self::fallback_without_ai($collected, $messages);
        }

        $model = Topinstal_Lead_Widget_Plugin::get_option('anthropic_model', 'claude-sonnet-4-20250514');
        $anthropic_messages = self::normalize_messages_for_anthropic($messages);

        $payload = array(
            'model' => $model,
            'max_tokens' => 1200,
            'system' => $system,
            'messages' => $anthropic_messages,
            'tools' => array(self::lead_parameters_tool()),
            'tool_choice' => array('type' => 'auto'),
        );

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-api-key' => $api_key,
                    'anthropic-version' => '2023-06-01',
                ),
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            return self::fallback_without_ai($collected, $messages);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            return self::fallback_without_ai($collected, $messages);
        }

        $parsed = self::parse_anthropic_response($decoded);
        if (!empty($parsed['collected_delta'])) {
            $collected = array_merge($collected, self::scrub_ai_collected_delta($parsed['collected_delta'], $collected));
            $collected = Topinstal_Lead_Widget_Defaults::sanitize_collected($collected);
        }

        return self::build_chat_response($collected, $parsed, $refinement_asked);
    }

    /**
     * @param string $system
     * @param array<int,mixed> $messages
     * @return array<string,mixed>
     */
    private static function call_deepseek($system, $messages) {
        $api_key = Topinstal_Lead_Widget_Plugin::get_option('deepseek_api_key', '');
        if ($api_key === '') {
            return array('ok' => false, 'fatal' => false, 'error' => 'not_configured');
        }

        $model = Topinstal_Lead_Widget_Plugin::get_option('deepseek_model', 'deepseek-v4-flash');
        $base_url = rtrim(Topinstal_Lead_Widget_Plugin::get_option('deepseek_base_url', 'https://api.deepseek.com'), '/');
        if ($base_url === '') {
            $base_url = 'https://api.deepseek.com';
        }

        $payload = array(
            'model' => $model,
            'max_tokens' => 1200,
            'messages' => array_merge(
                array(array('role' => 'system', 'content' => self::build_deepseek_system_prompt($system))),
                self::normalize_messages_for_openai($messages)
            ),
        );

        if (self::option_enabled('deepseek_thinking_enabled', true)) {
            $payload['thinking'] = array('type' => 'enabled');
            $payload['reasoning_effort'] = Topinstal_Lead_Widget_Plugin::get_option('deepseek_reasoning_effort', 'low');
        } else {
            $payload['temperature'] = 0.2;
        }

        $response = wp_remote_post(
            $base_url . '/chat/completions',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            return array('ok' => false, 'fatal' => false, 'error' => 'transport_error');
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            return array('ok' => false, 'fatal' => false, 'error' => 'http_error', 'status' => $code);
        }

        if (!is_array($decoded)) {
            return array('ok' => false, 'fatal' => true, 'error' => 'invalid_json', 'status' => $code);
        }

        $parsed = self::parse_deepseek_response($decoded);
        if ($parsed === null) {
            return array('ok' => false, 'fatal' => true, 'error' => 'invalid_shape', 'status' => $code);
        }

        return array('ok' => true, 'fatal' => false, 'parsed' => $parsed, 'status' => $code);
    }

    /**
     * @param string $system
     * @return string
     */
    private static function build_deepseek_system_prompt($system) {
        return $system . "\n\n" . implode(
            "\n",
            array(
                'Return only a JSON object. Do not use markdown.',
                'Schema: {"message": string, "done": boolean, "collected_delta": object}.',
                'Allowed collected_delta keys: powierzchnia, on_corner, obecne_ogrzewanie, keep_existing_heat_source, dhw_persons, dhw_usage, postal_code, ventilation_type.',
                'Never infer keep_existing_heat_source from obecne_ogrzewanie; set it only when the current question asks whether the old heat source should remain as backup/support.',
                'Answer message must be in Polish and ask at most one next question.',
            )
        );
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>|null
     */
    private static function parse_deepseek_response($decoded) {
        if (
            !isset($decoded['choices'])
            || !is_array($decoded['choices'])
            || !isset($decoded['choices'][0])
            || !is_array($decoded['choices'][0])
            || !isset($decoded['choices'][0]['message'])
            || !is_array($decoded['choices'][0]['message'])
            || !array_key_exists('content', $decoded['choices'][0]['message'])
        ) {
            return null;
        }

        $content_value = $decoded['choices'][0]['message']['content'];
        if (is_array($content_value) || is_object($content_value)) {
            return null;
        }

        $content = trim((string) $content_value);
        if ($content === '') {
            return null;
        }

        return self::parse_assistant_json($content);
    }

    /**
     * @param string $key
     * @param bool $default
     * @return bool
     */
    private static function option_enabled($key, $default) {
        $value = Topinstal_Lead_Widget_Plugin::get_option($key, $default ? '1' : '0');
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }
        return !in_array($value, array('0', 'false', 'no', 'off'), true);
    }

    /**
     * @param array<string,mixed> $collected
     * @param array<int,mixed> $messages
     * @return WP_REST_Response
     */
    private static function handle_skip_current($collected, $messages) {
        $pending = Topinstal_Lead_Widget_Defaults::active_pending_questions($collected);
        if (!empty($pending)) {
            $collected = Topinstal_Lead_Widget_Defaults::mark_field_skipped($collected, $pending[0]['field']);
            $collected = Topinstal_Lead_Widget_Defaults::sanitize_collected($collected);
        }

        return self::fallback_without_ai($collected, $messages, true);
    }

    /**
     * @return array<string,mixed>
     */
    private static function lead_parameters_tool() {
        return array(
            'name' => 'update_lead_parameters',
            'description' => 'Zapisz parametry zebrane z odpowiedzi klienta. Możesz ustawić wiele pól naraz z jednego zdania.',
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'message' => array(
                        'type' => 'string',
                        'description' => 'Krótkie pytanie lub podsumowanie po polsku.',
                    ),
                    'done' => array(
                        'type' => 'boolean',
                        'description' => 'true gdy wszystkie pending są zebrane.',
                    ),
                    'powierzchnia' => array('type' => 'integer'),
                    'on_corner' => array('type' => 'boolean'),
                    'obecne_ogrzewanie' => array(
                        'type' => 'string',
                        'enum' => array('wegiel', 'gaz', 'olej', 'prad', 'inne'),
                    ),
                    'keep_existing_heat_source' => array('type' => 'boolean'),
                    'dhw_persons' => array('type' => 'integer'),
                    'dhw_usage' => array(
                        'type' => 'string',
                        'enum' => array('shower', 'shower_bath', 'bath'),
                    ),
                    'postal_code' => array('type' => 'string'),
                    'ventilation_type' => array(
                        'type' => 'string',
                        'enum' => array('natural', 'mechanical_recovery'),
                    ),
                ),
            ),
        );
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private static function parse_anthropic_response($decoded) {
        $message = '';
        $done = false;
        $delta = array();

        if (!isset($decoded['content']) || !is_array($decoded['content'])) {
            return array('message' => '', 'done' => false, 'collected_delta' => array());
        }

        foreach ($decoded['content'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                $text = trim((string) $block['text']);
                if ($text !== '') {
                    $json_parsed = self::parse_assistant_json($text);
                    if (!empty($json_parsed['collected_delta'])) {
                        $delta = array_merge($delta, $json_parsed['collected_delta']);
                    }
                    if ($json_parsed['message'] !== '') {
                        $message = $json_parsed['message'];
                    }
                    if (!empty($json_parsed['done'])) {
                        $done = true;
                    }
                }
            }
            if (isset($block['type']) && $block['type'] === 'tool_use' && isset($block['input']) && is_array($block['input'])) {
                $tool_delta = self::tool_input_to_delta($block['input']);
                $delta = array_merge($delta, $tool_delta);
                if (isset($block['input']['message'])) {
                    $message = (string) $block['input']['message'];
                }
                if (!empty($block['input']['done'])) {
                    $done = true;
                }
            }
        }

        if (!empty($delta)) {
            $delta = Topinstal_Lead_Widget_Defaults::sanitize_collected($delta);
        }

        return array(
            'message' => $message,
            'done' => $done,
            'collected_delta' => $delta,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private static function tool_input_to_delta($input) {
        $delta = array();
        $keys = array(
            'powierzchnia',
            'on_corner',
            'obecne_ogrzewanie',
            'keep_existing_heat_source',
            'dhw_persons',
            'dhw_usage',
            'postal_code',
            'ventilation_type',
        );
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $delta[$key] = $input[$key];
            }
        }
        return $delta;
    }

    /**
     * @param array<string,mixed> $collected
     * @param array<string,mixed> $parsed
     * @return WP_REST_Response
     */
    private static function build_chat_response($collected, $parsed, $refinement_asked = 0) {
        $pending = Topinstal_Lead_Widget_Defaults::active_pending_questions($collected);
        $refinement_mode = !empty($collected['refinement_active']);
        $done = !empty($parsed['done']);
        if ($refinement_mode) {
            if ($refinement_asked >= 4) {
                $done = true;
            } elseif (empty($pending)) {
                $done = $refinement_asked > 0;
            }
        } elseif (empty($pending)) {
            $done = true;
        }
        $message = isset($parsed['message']) ? (string) $parsed['message'] : '';

        if ($message === '' && !$done && !empty($pending)) {
            $message = $pending[0]['message'];
        }
        if ($refinement_mode && empty($pending) && $refinement_asked === 0 && !$done) {
            $done = true;
            if ($message === '') {
                $message = 'Kluczowe parametry są już potwierdzone — przeliczam dobór z doprecyzowanymi danymi.';
            }
        }
        if ($message === '' && $done) {
            $message = $refinement_mode
                ? 'Dziękuję — przeliczam dobór z doprecyzowanymi danymi.'
                : 'Mam już wystarczające dane — przechodzę do wstępnego doboru.';
        }

        return self::rest_chat_payload($collected, $message, $done, $refinement_mode);
    }

    /**
     * @param array<string,mixed> $collected
     * @param string $message
     * @param bool $done
     * @param bool $refinement_mode
     * @return WP_REST_Response
     */
    private static function rest_chat_payload($collected, $message, $done, $refinement_mode) {
        $pending_list = Topinstal_Lead_Widget_Defaults::active_pending_questions($collected);
        $pending_field = '';
        if (!$done && !empty($pending_list)) {
            $pending_field = (string) $pending_list[0]['field'];
        }

        $param_score = Topinstal_Lead_Widget_Defaults::count_satisfied_parameters($collected);

        return new WP_REST_Response(
            array(
                'message' => $message,
                'done' => $done,
                'collected' => $collected,
                'refinement_mode' => $refinement_mode,
                'pending_field' => $pending_field,
                'hint' => Topinstal_Lead_Widget_Defaults::field_hint($pending_field),
                'pending_labels' => $param_score['pending_labels'],
                'assumed_count' => $param_score['assumed'],
                'parameters_filled' => $param_score['filled'],
                'parameters_total' => $param_score['total'],
            ),
            200
        );
    }

    /**
     * @param array<string,mixed> $collected
     * @param array<int,mixed> $messages
     * @param bool $after_skip
     * @return WP_REST_Response
     */
    private static function fallback_without_ai($collected, $messages, $after_skip = false) {
        $assistant_count = self::assistant_message_count($messages);
        $last_user = self::last_user_message($messages);

        if ($last_user !== '' && !$after_skip) {
            $collected = self::apply_answer_to_collected($collected, $messages);
            $collected = Topinstal_Lead_Widget_Defaults::sanitize_collected($collected);
        }

        $refinement_mode = !empty($collected['refinement_active']);
        $pending = Topinstal_Lead_Widget_Defaults::active_pending_questions($collected);
        $refinement_asked = 0;
        foreach ($messages as $msg) {
            if (is_array($msg) && isset($msg['role']) && $msg['role'] === 'assistant') {
                $refinement_asked++;
            }
        }
        if ($refinement_mode) {
            $refinement_asked = max(0, $refinement_asked - 1);
        }

        $refinement_done = false;
        if ($refinement_mode) {
            $refinement_done = $refinement_asked >= 4 || (empty($pending) && $refinement_asked > 0);
        } elseif (empty($pending) || $assistant_count >= self::MAX_ASSISTANT_QUESTIONS) {
            $refinement_done = true;
        }

        if ($refinement_done || empty($pending)) {
            return self::rest_chat_payload(
                $collected,
                $refinement_mode
                    ? 'Dziękuję — przeliczam dobór z doprecyzowanymi danymi.'
                    : 'Zebrane dane wystarczą — przechodzę do wstępnego doboru.',
                true,
                $refinement_mode
            );
        }

        $next = $pending[0];
        $msg = $after_skip
            ? 'Rozumiem — pomijamy to pytanie. ' . $next['message']
            : $next['message'];

        return self::rest_chat_payload($collected, $msg, false, $refinement_mode);
    }

    /**
     * @param array<string,mixed> $collected
     * @param array<int,mixed> $messages
     * @return array<string,mixed>
     */
    private static function apply_answer_to_collected($collected, $messages) {
        $pending_before = Topinstal_Lead_Widget_Defaults::active_pending_questions($collected);
        if (empty($pending_before)) {
            return $collected;
        }

        $field = $pending_before[0]['field'];
        $answer = self::last_user_message($messages);
        if ($answer === '') {
            return $collected;
        }

        $multi = Topinstal_Lead_Widget_Answer_Extractor::extract_from_text($answer, $collected, $field);
        if (!empty($multi)) {
            if (!empty($multi['insulation_level'])) {
                $multi['insulation_confirmed'] = true;
            }
            $collected = array_merge($collected, $multi);
        }

        return self::merge_answer($collected, $field, $answer);
    }

    /**
     * @param array<string,mixed> $collected
     * @param string $field
     * @param string $answer
     * @return array<string,mixed>
     */
    private static function merge_answer($collected, $field, $answer) {
        $answer = trim($answer);
        $lower = strtolower($answer);

        switch ($field) {
            case 'powierzchnia':
                if (preg_match('/(\d{2,3})/', $answer, $m)) {
                    $collected['powierzchnia'] = (int) $m[1];
                }
                break;
            case 'on_corner':
                $collected['on_corner'] = (
                    strpos($lower, 'tak') !== false
                    || strpos($lower, 'naro') !== false
                    || strpos($lower, 'corner') !== false
                ) && strpos($lower, 'nie') === false;
                break;
            case 'obecne_ogrzewanie':
                if (strpos($lower, 'weg') !== false || strpos($lower, 'węg') !== false) {
                    $collected['obecne_ogrzewanie'] = 'wegiel';
                } elseif (strpos($lower, 'gaz') !== false) {
                    $collected['obecne_ogrzewanie'] = 'gaz';
                } elseif (strpos($lower, 'olej') !== false) {
                    $collected['obecne_ogrzewanie'] = 'olej';
                } elseif (strpos($lower, 'prąd') !== false || strpos($lower, 'prad') !== false || strpos($lower, 'elektr') !== false) {
                    $collected['obecne_ogrzewanie'] = 'prad';
                } else {
                    $collected['obecne_ogrzewanie'] = 'inne';
                }
                break;
            case 'keep_existing_heat_source':
                $collected['keep_existing_heat_source'] = self::parse_yes_no($lower);
                break;
            case 'dhw_persons':
                $persons = Topinstal_Lead_Widget_Defaults::normalize_dhw_persons_input($answer);
                if ($persons >= 1) {
                    $collected['dhw_persons'] = $persons;
                }
                break;
            case 'dhw_usage':
                $usage = Topinstal_Lead_Widget_Defaults::normalize_dhw_usage_input($answer);
                if ($usage !== '') {
                    $collected['dhw_usage'] = $usage;
                }
                break;
            case 'insulation_level':
                $insulation = Topinstal_Lead_Widget_Defaults::normalize_insulation_input($answer);
                if ($insulation !== '') {
                    $collected['insulation_level'] = $insulation;
                    $collected['insulation_confirmed'] = true;
                }
                break;
            case 'postal_code':
                if (preg_match('/(\d{2}-\d{3}|\d{5})/', $answer, $m)) {
                    $collected['postal_code'] = $m[1];
                } else {
                    $collected['postal_code'] = preg_replace('/\s+/', '', $answer);
                }
                break;
            case 'ventilation_type':
                if (strpos($lower, 'reku') !== false || strpos($lower, 'odzysk') !== false) {
                    $collected['ventilation_type'] = 'mechanical_recovery';
                } else {
                    $collected['ventilation_type'] = $answer;
                }
                break;
            case 'radiators_is_ht':
                $collected['radiators_is_ht'] = self::parse_yes_no_ht_radiators($lower);
                $collected['hydraulics_confirmed'] = true;
                break;
            case 'has_underfloor_actuators':
                $collected['has_underfloor_actuators'] = self::parse_yes_no($lower);
                $collected['hydraulics_confirmed'] = true;
                break;
            default:
                $collected[$field] = $answer;
                break;
        }

        return Topinstal_Lead_Widget_Defaults::unmark_field_skipped($collected, $field);
    }

    /**
     * @param string $lower
     * @return bool
     */
    private static function parse_yes_no($lower) {
        if (strpos($lower, 'nie') !== false && strpos($lower, 'tak') === false) {
            return false;
        }
        return strpos($lower, 'tak') !== false || strpos($lower, 'yes') !== false;
    }

    /**
     * @param string $lower
     * @return bool
     */
    private static function parse_yes_no_ht_radiators($lower) {
        if (
            strpos($lower, 'płyt') !== false
            || strpos($lower, 'plyt') !== false
            || strpos($lower, 'alum') !== false
            || strpos($lower, 'nisk') !== false
        ) {
            return false;
        }
        if (
            strpos($lower, 'stal') !== false
            || strpos($lower, 'żel') !== false
            || strpos($lower, 'zel') !== false
            || strpos($lower, 'wysok') !== false
        ) {
            return true;
        }
        return self::parse_yes_no($lower);
    }

    /**
     * AI must not pre-fill insulation from building age — only the user may answer that question.
     *
     * @param array<string,mixed> $delta
     * @return array<string,mixed>
     */
    private static function scrub_ai_collected_delta($delta, $collected) {
        if (!is_array($delta)) {
            return array();
        }
        $pending = Topinstal_Lead_Widget_Defaults::active_pending_questions($collected);
        $pending_field = !empty($pending[0]['field']) ? (string) $pending[0]['field'] : '';
        unset(
            $delta['insulation_level'],
            $delta['insulation_confirmed'],
            $delta['radiators_is_ht'],
            $delta['has_underfloor_actuators'],
            $delta['hydraulics_confirmed']
        );
        if ($pending_field !== 'keep_existing_heat_source') {
            unset($delta['keep_existing_heat_source']);
        }
        return $delta;
    }

    /**
     * @return string
     */
    private static function load_company_context() {
        $path = self::resolve_company_context_path();
        if ($path === '') {
            return '';
        }
        $contents = file_get_contents($path);
        return is_string($contents) ? trim($contents) : '';
    }

    /**
     * @return string Absolute path or empty when not found.
     */
    private static function resolve_company_context_path() {
        $env = getenv('TOPINSTAL_COMPANY_CONTEXT_PATH');
        if (is_string($env) && $env !== '' && is_readable($env)) {
            return $env;
        }

        $candidates = array(
            TOPINSTAL_LEAD_WIDGET_DIR . 'data/company_context.md',
            dirname(TOPINSTAL_LEAD_WIDGET_DIR, 3) . '/knowledge/company_context.md',
            dirname(TOPINSTAL_LEAD_WIDGET_DIR, 4) . '/knowledge/company_context.md',
        );

        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $collected
     * @return string
     */
    private static function build_system_prompt($collected) {
        $pending = Topinstal_Lead_Widget_Defaults::active_pending_questions($collected);
        $assume_hp = Topinstal_Lead_Widget_Defaults::should_assume_existing_heat_pump($collected);
        $refinement_mode = !empty($collected['refinement_active']);

        $chat_instructions = implode(
            "\n",
            array(
                'Jesteś doradcą technicznym TOP-INSTAL (pompy Panasonic Aquarea K). Leadgen — krótko, po polsku.',
                $refinement_mode
                    ? 'Tryb DOPRECYZOWANIA po wstępnym wyniku: max 4 pytania z pending (najważniejsze braki). Nie powtarzaj pytań o pola już zebrane.'
                    : 'Krok A już zebrany: typ budynku, rok budowy, emiter. NIE pytaj ponownie.',
                'Z jednej odpowiedzi klienta wyciągnij pasujące pola (powierzchnia, kod, CWU, ogrzewanie, wentylacja) — NIE z pola „rok budowy”.',
                'Ocieplenie (insulation_level) NIE wolno zgadywać z roku budowy — musi paść osobne pytanie i odpowiedź klienta (słabe/przeciętne/dobre/bardzo dobre).',
                'Obecne ogrzewanie i decyzja o pozostawieniu starego źródła to dwa różne fakty. NIE ustawiaj keep_existing_heat_source tylko dlatego, że klient podał gaz/węgiel/olej/prąd.',
                'Przy grzejnikach lub układzie mieszanym zapytaj o typ grzejników (stal/żeliwo vs płyty/aluminium) — to wpływa na bufor CO.',
                'NIE ustawiaj radiators_is_ht ani has_underfloor_actuators bez wyraźnej odpowiedzi klienta.',
                'Użyj narzędzia update_lead_parameters — ustaw rozpoznane pola naraz + message z JEDNYM następnym pytaniem (pierwsze z pending).',
                'Max ' . self::MAX_ASSISTANT_QUESTIONS . ' pytań łącznie.',
                'assume_existing_heat_pump=' . ($assume_hp ? 'true' : 'false'),
                'Pending: ' . wp_json_encode($pending, JSON_UNESCAPED_UNICODE),
                'Zebrane: ' . wp_json_encode($collected, JSON_UNESCAPED_UNICODE),
            )
        );

        $company = self::load_company_context();
        if ($company !== '') {
            return "---\n" . $company . "\n---\n" . $chat_instructions;
        }

        return $chat_instructions;
    }

    /**
     * @param array<int,mixed> $messages
     * @return int
     */
    private static function assistant_message_count($messages) {
        $count = 0;
        foreach ($messages as $msg) {
            if (is_array($msg) && isset($msg['role']) && $msg['role'] === 'assistant') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<int,mixed> $messages
     * @return string
     */
    private static function last_user_message($messages) {
        $last = '';
        foreach ($messages as $msg) {
            if (is_array($msg) && isset($msg['role']) && $msg['role'] === 'user' && isset($msg['content'])) {
                $last = trim((string) $msg['content']);
            }
        }
        return $last;
    }

    /**
     * True when the latest message in the thread is an empty user turn.
     *
     * @param array<int,mixed> $messages
     * @return bool
     */
    private static function last_user_message_is_empty($messages) {
        if (!is_array($messages) || $messages === array()) {
            return false;
        }
        $last = $messages[count($messages) - 1];
        if (!is_array($last) || !isset($last['role']) || $last['role'] !== 'user') {
            return false;
        }
        return trim((string) ($last['content'] ?? '')) === '';
    }

    /**
     * @param array<int,mixed> $messages
     * @return array<int,array<string,string>>
     */
    private static function normalize_messages_for_anthropic($messages) {
        return self::normalize_messages_for_openai($messages);
    }

    /**
     * @param array<int,mixed> $messages
     * @return array<int,array<string,string>>
     */
    private static function normalize_messages_for_openai($messages) {
        $out = array();
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = isset($msg['role']) ? (string) $msg['role'] : '';
            $content = isset($msg['content']) ? (string) $msg['content'] : '';
            if ($content === '') {
                continue;
            }
            if ($role === 'user' || $role === 'assistant') {
                $out[] = array(
                    'role' => $role,
                    'content' => $content,
                );
            }
        }
        if (empty($out)) {
            $out[] = array(
                'role' => 'user',
                'content' => 'Zacznij od pierwszego brakującego pytania z pending.',
            );
        }
        return $out;
    }

    /**
     * @param string $text
     * @return array<string,mixed>
     */
    private static function parse_assistant_json($text) {
        $text = trim($text);
        if ($text === '') {
            return array('message' => '', 'done' => true, 'collected_delta' => array());
        }

        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                $message = isset($decoded['message']) ? (string) $decoded['message'] : $text;
                $done = !empty($decoded['done']);
                $delta = isset($decoded['collected_delta']) && is_array($decoded['collected_delta'])
                    ? $decoded['collected_delta']
                    : array();
                foreach ($decoded as $key => $value) {
                    if (in_array($key, array('message', 'done', 'collected_delta'), true)) {
                        continue;
                    }
                    $delta[$key] = $value;
                }
                if (!empty($delta)) {
                    $delta = Topinstal_Lead_Widget_Defaults::sanitize_collected($delta);
                }
                return array(
                    'message' => $message,
                    'done' => $done,
                    'collected_delta' => $delta,
                );
            }
        }

        return array(
            'message' => $text,
            'done' => false,
            'collected_delta' => array(),
        );
    }
}
