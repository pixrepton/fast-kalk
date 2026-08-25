<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps widget "collected" answers to CalcRequestDTO v1.0 (aligned with kalk-top formDataProcessor / OZC).
 */
final class Topinstal_Lead_Widget_Defaults {
    const INDOOR_TEMPERATURE_C = 22;

    /** @var array<int,string> */
    const REFINEMENT_FIELD_PRIORITY = array(
        'insulation_level',
        'powierzchnia',
        'postal_code',
        'radiators_is_ht',
        'has_underfloor_actuators',
        'dhw_persons',
        'dhw_usage',
        'ventilation_type',
        'obecne_ogrzewanie',
        'keep_existing_heat_source',
        'on_corner',
    );

    /** @var array<string,string> */
    const FIELD_LABELS_PL = array(
        'powierzchnia' => 'powierzchnia ogrzewana',
        'insulation_level' => 'ocieplenie budynku',
        'postal_code' => 'kod pocztowy',
        'radiators_is_ht' => 'typ grzejników',
        'has_underfloor_actuators' => 'sterowanie strefowe podłogówki',
        'dhw_persons' => 'liczba osób (CWU)',
        'dhw_usage' => 'zużycie ciepłej wody',
        'ventilation_type' => 'wentylacja',
        'obecne_ogrzewanie' => 'obecne ogrzewanie',
        'keep_existing_heat_source' => 'pozostawienie obecnego źródła',
        'on_corner' => 'dom narożny',
    );

    /**
     * @return array<string,array{lat:float,lon:float}>
     */
    private static function location_coords_map() {
        return array(
            'PL_STREFA_I' => array('lat' => 54.352, 'lon' => 18.6466),
            'PL_STREFA_II' => array('lat' => 52.2297, 'lon' => 21.0122),
            'PL_STREFA_III' => array('lat' => 50.0647, 'lon' => 19.945),
            'PL_STREFA_IV' => array('lat' => 49.6216, 'lon' => 20.697),
            'PL_STREFA_V' => array('lat' => 49.2992, 'lon' => 19.9496),
            'PL_ZAKOPANE' => array('lat' => 49.2992, 'lon' => 19.9496),
            'PL_GDANSK' => array('lat' => 54.352, 'lon' => 18.6466),
        );
    }

    /**
     * @param array<string,mixed> $collected
     * @return array<int,array<string,string>>
     */
    /**
     * @param array<string,mixed> $collected
     * @param string $field
     * @return bool
     */
    public static function is_chat_field_satisfied($collected, $field) {
        if (
            self::was_field_skipped($collected, $field)
            && !self::skip_counts_as_pre_result_satisfied($collected, $field)
        ) {
            return false;
        }
        if (self::was_field_skipped($collected, $field)) {
            return true;
        }

        switch ($field) {
            case 'powierzchnia':
                return !empty($collected['powierzchnia']);
            case 'on_corner':
                return array_key_exists('on_corner', $collected);
            case 'obecne_ogrzewanie':
                return !empty($collected['obecne_ogrzewanie']) || self::should_assume_existing_heat_pump($collected);
            case 'keep_existing_heat_source':
                if (!self::should_ask_keep_existing_heat_source($collected)) {
                    return true;
                }
                return array_key_exists('keep_existing_heat_source', $collected);
            case 'dhw_persons':
                return !empty($collected['dhw_persons']);
            case 'dhw_usage':
                return !empty($collected['dhw_usage']);
            case 'insulation_level':
                return !empty($collected['insulation_level']) && !empty($collected['insulation_confirmed']);
            case 'postal_code':
                return !empty($collected['postal_code']);
            case 'ventilation_type':
                return !empty($collected['ventilation_type']);
            case 'radiators_is_ht':
                if (!self::emitter_needs_radiators_ht_question($collected)) {
                    return true;
                }
                return !empty($collected['hydraulics_confirmed']) && array_key_exists('radiators_is_ht', $collected);
            case 'has_underfloor_actuators':
                if (!self::emitter_needs_actuators_question($collected)) {
                    return true;
                }
                return array_key_exists('has_underfloor_actuators', $collected);
            default:
                return !empty($collected[$field]);
        }
    }

    /**
     * Skipped optional fields can stay assumed; critical pre-result fields must be answered.
     *
     * @param array<string,mixed> $collected
     * @param string $field
     * @return bool
     */
    public static function skip_counts_as_pre_result_satisfied($collected, $field) {
        if (!self::is_refinement_field_applicable($collected, $field)) {
            return true;
        }
        return self::is_parameter_user_provided($collected, $field);
    }

    /**
     * @param array<string,mixed> $collected
     * @return bool
     */
    public static function emitter_needs_radiators_ht_question($collected) {
        $emitter = isset($collected['emitter_type']) ? strtolower(trim((string) $collected['emitter_type'])) : '';
        return in_array($emitter, array('grzejniki', 'mieszane'), true);
    }

    /**
     * @param array<string,mixed> $collected
     * @return bool
     */
    public static function emitter_needs_actuators_question($collected) {
        $emitter = isset($collected['emitter_type']) ? strtolower(trim((string) $collected['emitter_type'])) : '';
        return $emitter === 'mieszane';
    }

    /**
     * @param array<string,mixed> $collected
     * @return bool
     */
    public static function should_force_buffer_refinement($collected) {
        if (!self::emitter_needs_radiators_ht_question($collected)) {
            return false;
        }
        if (self::is_chat_field_satisfied($collected, 'radiators_is_ht')) {
            return false;
        }
        $last_bufor = isset($collected['last_bufor_display']) ? (string) $collected['last_bufor_display'] : '';
        if ($last_bufor !== '' && stripos($last_bufor, 'NIE WYMAGANY') === false) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $collected
     * @return array<int,string>
     */
    public static function pending_field_labels($collected) {
        $labels = array();
        foreach (self::pending_chat_questions($collected) as $item) {
            $field = isset($item['field']) ? (string) $item['field'] : '';
            if ($field !== '' && isset(self::FIELD_LABELS_PL[$field])) {
                $labels[] = self::FIELD_LABELS_PL[$field];
            }
        }
        return array_values(array_unique($labels));
    }

    /**
     * @param array<string,mixed> $collected
     * @param string $field
     * @return array<string,mixed>
     */
    /**
     * @param array<string,mixed> $collected
     * @param string $field
     * @return bool
     */
    public static function was_field_skipped($collected, $field) {
        $skipped = isset($collected['skipped_fields']) && is_array($collected['skipped_fields'])
            ? $collected['skipped_fields']
            : array();
        return in_array($field, $skipped, true);
    }

    /**
     * @param array<string,mixed> $collected
     * @param string $field
     * @return array<string,mixed>
     */
    public static function mark_field_skipped($collected, $field) {
        if (!is_array($collected)) {
            $collected = array();
        }
        if (!isset($collected['skipped_fields']) || !is_array($collected['skipped_fields'])) {
            $collected['skipped_fields'] = array();
        }
        if (!in_array($field, $collected['skipped_fields'], true)) {
            $collected['skipped_fields'][] = $field;
        }
        return $collected;
    }

    /**
     * @param array<string,mixed> $collected
     * @param string $field
     * @return array<string,mixed>
     */
    public static function unmark_field_skipped($collected, $field) {
        if (!is_array($collected) || !isset($collected['skipped_fields']) || !is_array($collected['skipped_fields'])) {
            return is_array($collected) ? $collected : array();
        }
        $collected['skipped_fields'] = array_values(
            array_filter(
                $collected['skipped_fields'],
                static function ($item) use ($field) {
                    return (string) $item !== (string) $field;
                }
            )
        );
        if ($collected['skipped_fields'] === array()) {
            unset($collected['skipped_fields']);
        }
        return $collected;
    }

    /**
     * @param string $field
     * @return string
     */
    public static function chat_question_message($field) {
        switch ($field) {
            case 'powierzchnia':
                return 'Jaka jest powierzchnia ogrzewana w domu (m²)?';
            case 'insulation_level':
                return 'Jak dobrze jest ocieplony budynek?';
            case 'postal_code':
                return 'Podaj kod pocztowy inwestycji — ustalimy strefę klimatyczną.';
            case 'dhw_persons':
                return 'Ile osób korzysta z ciepłej wody?';
            case 'dhw_usage':
                return 'Jak intensywnie korzystacie z ciepłej wody?';
            case 'obecne_ogrzewanie':
                return 'Czym ogrzewany jest dom obecnie — gaz, węgiel, olej, prąd lub inne?';
            case 'keep_existing_heat_source':
                return 'Czy obecne źródło ciepła ma pozostać jako dodatkowe źródło wspomagające pompę?';
            case 'on_corner':
                return 'Czy dom szeregowy stoi na narożu ulicy (dom narożny)?';
            case 'ventilation_type':
                return 'Jaka wentylacja jest w domu — naturalna czy rekuperacja?';
            case 'radiators_is_ht':
                return 'Czy grzejniki to stal lub żeliwo (wysokotemperaturowe)?';
            case 'has_underfloor_actuators':
                return 'Czy ogrzewanie podłogowe ma sterowanie strefowe (siłowniki na pętlach)?';
            default:
                return '';
        }
    }

    /**
     * @param array<string,mixed> $collected
     * @param string $field
     * @return bool
     */
    public static function is_refinement_field_applicable($collected, $field) {
        $building_type = self::map_building_type(isset($collected['typ_budynku']) ? $collected['typ_budynku'] : '');
        $year = self::map_construction_year(isset($collected['standard']) ? $collected['standard'] : '');

        switch ($field) {
            case 'powierzchnia':
            case 'insulation_level':
            case 'postal_code':
            case 'obecne_ogrzewanie':
                return !self::should_assume_existing_heat_pump($collected);
            case 'keep_existing_heat_source':
                return self::should_ask_keep_existing_heat_source($collected);
            case 'dhw_persons':
            case 'dhw_usage':
                return self::is_residential_lead_type($building_type);
            case 'on_corner':
                return $building_type === 'row_house';
            case 'ventilation_type':
                return $year >= 2000;
            case 'radiators_is_ht':
                return self::emitter_needs_radiators_ht_question($collected);
            case 'has_underfloor_actuators':
                return self::emitter_needs_actuators_question($collected);
            default:
                return false;
        }
    }

    /**
     * Post-result refinement should re-ask skipped or assumed fields, not only empty ones.
     *
     * @param array<string,mixed> $collected
     * @param string $field
     * @return bool
     */
    public static function is_refinement_field_needed($collected, $field) {
        if (!self::is_refinement_field_applicable($collected, $field)) {
            return false;
        }
        if ($field === 'radiators_is_ht' && self::should_force_buffer_refinement($collected)) {
            return true;
        }
        if (self::was_field_skipped($collected, $field)) {
            return true;
        }
        return !self::is_parameter_user_provided($collected, $field);
    }

    /**
     * @param array<string,mixed> $collected
     * @return array<int,array<string,string>>
     */
    public static function pending_chat_questions($collected) {
        $collected = self::sanitize_collected($collected);
        $pending = array();
        $building_type = self::map_building_type(isset($collected['typ_budynku']) ? $collected['typ_budynku'] : '');
        $year = self::map_construction_year(isset($collected['standard']) ? $collected['standard'] : '');

        if (!self::is_chat_field_satisfied($collected, 'powierzchnia')) {
            $pending[] = array(
                'field' => 'powierzchnia',
                'message' => 'Jaka jest powierzchnia ogrzewana w domu (m²)?',
            );
        }

        if (!self::is_chat_field_satisfied($collected, 'insulation_level')) {
            $pending[] = array(
                'field' => 'insulation_level',
                'message' => 'Jak dobrze jest ocieplony budynek?',
            );
        }

        if (!self::is_chat_field_satisfied($collected, 'postal_code')) {
            $pending[] = array(
                'field' => 'postal_code',
                'message' => 'Podaj kod pocztowy inwestycji — ustalimy strefę klimatyczną.',
            );
        }

        if (self::is_residential_lead_type($building_type) && !self::is_chat_field_satisfied($collected, 'dhw_persons')) {
            $pending[] = array(
                'field' => 'dhw_persons',
                'message' => 'Ile osób korzysta z ciepłej wody?',
            );
        }

        if (self::is_residential_lead_type($building_type) && !self::is_chat_field_satisfied($collected, 'dhw_usage')) {
            $pending[] = array(
                'field' => 'dhw_usage',
                'message' => 'Jak intensywnie korzystacie z ciepłej wody?',
            );
        }

        if (!self::is_chat_field_satisfied($collected, 'obecne_ogrzewanie')) {
            $pending[] = array(
                'field' => 'obecne_ogrzewanie',
                'message' => 'Czym ogrzewany jest dom obecnie — gaz, węgiel, olej, prąd lub inne?',
            );
        }

        if (self::should_ask_keep_existing_heat_source($collected) && !self::is_chat_field_satisfied($collected, 'keep_existing_heat_source')) {
            $pending[] = array(
                'field' => 'keep_existing_heat_source',
                'message' => 'Czy obecne źródło ciepła ma pozostać jako dodatkowe źródło wspomagające pompę?',
            );
        }

        if ($building_type === 'row_house' && !self::is_chat_field_satisfied($collected, 'on_corner')) {
            $pending[] = array(
                'field' => 'on_corner',
                'message' => 'Czy dom szeregowy stoi na narożu ulicy (dom narożny)?',
            );
        }

        if ($year >= 2000 && !self::is_chat_field_satisfied($collected, 'ventilation_type')) {
            $pending[] = array(
                'field' => 'ventilation_type',
                'message' => 'Jaka wentylacja jest w domu — naturalna czy rekuperacja?',
            );
        }

        if (self::emitter_needs_radiators_ht_question($collected) && !self::is_chat_field_satisfied($collected, 'radiators_is_ht')) {
            $pending[] = array(
                'field' => 'radiators_is_ht',
                'message' => 'Czy grzejniki to stal lub żeliwo (wysokotemperaturowe)?',
            );
        }

        if (self::emitter_needs_actuators_question($collected) && !self::is_chat_field_satisfied($collected, 'has_underfloor_actuators')) {
            $pending[] = array(
                'field' => 'has_underfloor_actuators',
                'message' => 'Czy ogrzewanie podłogowe ma sterowanie strefowe (siłowniki na pętlach)?',
            );
        }

        return $pending;
    }

    /**
     * Up to 4 highest-impact missing fields for post-result refinement chat.
     *
     * @param array<string,mixed> $collected
     * @param int $limit
     * @return array<int,array<string,string>>
     */
    public static function refinement_pending_questions($collected, $limit = 4) {
        $collected = self::sanitize_collected($collected);
        $ordered = array();
        $seen = array();

        foreach (self::REFINEMENT_FIELD_PRIORITY as $field) {
            if (!self::is_refinement_field_needed($collected, $field)) {
                continue;
            }
            $ordered[] = array(
                'field' => $field,
                'message' => self::chat_question_message($field),
            );
            $seen[$field] = true;
        }

        foreach (self::pending_chat_questions($collected) as $item) {
            $field = isset($item['field']) ? (string) $item['field'] : '';
            if ($field === '' || isset($seen[$field])) {
                continue;
            }
            if (!self::is_refinement_field_needed($collected, $field)) {
                continue;
            }
            $ordered[] = array(
                'field' => $field,
                'message' => isset($item['message']) ? (string) $item['message'] : self::chat_question_message($field),
            );
            $seen[$field] = true;
        }

        if ($limit < 1) {
            return $ordered;
        }

        return array_slice($ordered, 0, $limit);
    }

    /**
     * Step 2 queue — same fields as refinement, ordered by impact before first result.
     *
     * @param array<string,mixed> $collected
     * @return array<int,array<string,string>>
     */
    public static function pre_result_pending_questions($collected) {
        $by_field = array();
        foreach (self::pending_chat_questions($collected) as $item) {
            $field = isset($item['field']) ? (string) $item['field'] : '';
            if ($field === '') {
                continue;
            }
            $by_field[$field] = $item;
        }

        $ordered = array();
        foreach (self::REFINEMENT_FIELD_PRIORITY as $field) {
            if (isset($by_field[$field])) {
                $ordered[] = $by_field[$field];
                unset($by_field[$field]);
            }
        }
        foreach ($by_field as $item) {
            $ordered[] = $item;
        }

        return $ordered;
    }

    /**
     * Pending list for active chat mode (initial vs refinement).
     *
     * @param array<string,mixed> $collected
     * @return array<int,array<string,string>>
     */
    public static function active_pending_questions($collected) {
        if (!empty($collected['refinement_active'])) {
            return self::refinement_pending_questions($collected, 4);
        }

        return self::pre_result_pending_questions($collected);
    }

    /**
     * @param array<string,mixed> $collected
     * @return array{filled:int,total:int,assumed:int,pending_labels:array<int,string>}
     */
    public static function count_satisfied_parameters($collected) {
        $collected = self::sanitize_collected($collected);
        $items = self::parameter_check_definitions($collected);

        $filled = 0;
        $assumed = 0;
        $pending_labels = array();

        foreach ($items as $item) {
            if (!$item['satisfied']) {
                $pending_labels[] = $item['label'];
                continue;
            }
            if ($item['user_provided']) {
                $filled++;
            } else {
                $assumed++;
            }
        }

        return array(
            'filled' => $filled,
            'total' => count($items),
            'assumed' => $assumed,
            'pending_labels' => array_values(array_unique($pending_labels)),
        );
    }

    /**
     * @param array<string,mixed> $collected
     * @return array<int,array{field:string,label:string,satisfied:bool,user_provided:bool}>
     */
    private static function parameter_check_definitions($collected) {
        $building_type = self::map_building_type(isset($collected['typ_budynku']) ? $collected['typ_budynku'] : '');
        $year = self::map_construction_year(isset($collected['standard']) ? $collected['standard'] : '');

        $defs = array(
            array('field' => 'typ_budynku', 'label' => 'typ budynku'),
            array('field' => 'standard', 'label' => 'rok budowy'),
            array('field' => 'emitter_type', 'label' => 'typ ogrzewania'),
            array('field' => 'powierzchnia', 'label' => self::FIELD_LABELS_PL['powierzchnia']),
            array('field' => 'postal_code', 'label' => self::FIELD_LABELS_PL['postal_code']),
            array('field' => 'insulation_level', 'label' => self::FIELD_LABELS_PL['insulation_level']),
            array('field' => 'dhw_persons', 'label' => self::FIELD_LABELS_PL['dhw_persons']),
            array('field' => 'dhw_usage', 'label' => self::FIELD_LABELS_PL['dhw_usage']),
            array('field' => 'obecne_ogrzewanie', 'label' => self::FIELD_LABELS_PL['obecne_ogrzewanie']),
        );
        if (self::should_ask_keep_existing_heat_source($collected) || array_key_exists('keep_existing_heat_source', $collected)) {
            $defs[] = array('field' => 'keep_existing_heat_source', 'label' => self::FIELD_LABELS_PL['keep_existing_heat_source']);
        }

        if ($year >= 2000) {
            $defs[] = array('field' => 'ventilation_type', 'label' => self::FIELD_LABELS_PL['ventilation_type']);
        }
        if ($building_type === 'row_house') {
            $defs[] = array('field' => 'on_corner', 'label' => self::FIELD_LABELS_PL['on_corner']);
        }
        if (self::emitter_needs_radiators_ht_question($collected)) {
            $defs[] = array('field' => 'radiators_is_ht', 'label' => self::FIELD_LABELS_PL['radiators_is_ht']);
        }
        if (self::emitter_needs_actuators_question($collected)) {
            $defs[] = array('field' => 'has_underfloor_actuators', 'label' => self::FIELD_LABELS_PL['has_underfloor_actuators']);
        }

        $items = array();
        foreach ($defs as $def) {
            $field = $def['field'];
            $satisfied = self::is_parameter_satisfied_for_score($collected, $field);
            $items[] = array(
                'field' => $field,
                'label' => $def['label'],
                'satisfied' => $satisfied,
                'user_provided' => $satisfied && self::is_parameter_user_provided($collected, $field),
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $collected
     * @param string $field
     * @return bool
     */
    private static function is_parameter_satisfied_for_score($collected, $field) {
        switch ($field) {
            case 'typ_budynku':
            case 'standard':
            case 'emitter_type':
                return !empty($collected[$field]);
            case 'dhw_persons':
            case 'dhw_usage':
                return self::is_residential_lead_type(
                    self::map_building_type(isset($collected['typ_budynku']) ? $collected['typ_budynku'] : '')
                )
                    ? self::is_chat_field_satisfied($collected, $field)
                    : true;
            case 'obecne_ogrzewanie':
                return self::is_chat_field_satisfied($collected, 'obecne_ogrzewanie');
            case 'keep_existing_heat_source':
                return self::is_chat_field_satisfied($collected, 'keep_existing_heat_source');
            default:
                return self::is_chat_field_satisfied($collected, $field);
        }
    }

    /**
     * @param array<string,mixed> $collected
     * @param string $field
     * @return bool
     */
    private static function is_parameter_user_provided($collected, $field) {
        if (self::was_field_skipped($collected, $field)) {
            return false;
        }
        switch ($field) {
            case 'typ_budynku':
            case 'standard':
            case 'emitter_type':
                return !empty($collected[$field]);
            case 'insulation_level':
                return !empty($collected['insulation_confirmed']);
            case 'radiators_is_ht':
                return !empty($collected['hydraulics_confirmed']) && array_key_exists('radiators_is_ht', $collected);
            case 'has_underfloor_actuators':
                return array_key_exists('has_underfloor_actuators', $collected);
            case 'keep_existing_heat_source':
                return array_key_exists('keep_existing_heat_source', $collected);
            default:
                return self::is_chat_field_satisfied($collected, $field);
        }
    }

    /**
     * UI hint under chat question (optional).
     *
     * @param string $field
     * @return string
     */
    public static function field_hint($field) {
        switch ($field) {
            case 'powierzchnia':
                return 'Podaj liczbę metrów kwadratowych powierzchni ogrzewanej.';
            case 'insulation_level':
                return 'Wybierz jeden poziom: słabo / brak · przeciętnie · dobrze · bardzo dobrze.';
            case 'postal_code':
                return 'Format: 00-000 (np. 30-001).';
            case 'dhw_persons':
                return 'Wybierz przedział liczby osób korzystających z CWU.';
            case 'dhw_usage':
                return 'Wybierz intensywność zużycia ciepłej wody.';
            case 'obecne_ogrzewanie':
                return 'Np. gaz, węgiel, pompa ciepła, prąd (ogrzewanie elektryczne).';
            case 'keep_existing_heat_source':
                return 'Odpowiedz: tak, jeśli stare źródło ma zostać jako wsparcie; nie, jeśli ma zostać wyłączone/usunięte.';
            case 'on_corner':
                return 'Odpowiedz: tak lub nie.';
            case 'ventilation_type':
                return 'Np. naturalna (kratki) lub rekuperacja.';
            case 'radiators_is_ht':
                return 'Stal lub żeliwo = wysokotemperaturowe; płyty/aluminium = niskotemperaturowe.';
            case 'has_underfloor_actuators':
                return 'Siłowniki na pętlach podłogówki pozwalają sterować strefami.';
            default:
                return '';
        }
    }

    /**
     * Map Polish/free-text answer to engine insulation level.
     *
     * @param string $raw
     * @return string poor|average|good|very_good|""
     */
    public static function normalize_insulation_input($raw) {
        return self::normalize_insulation_level($raw);
    }

    /**
     * @param array<string,mixed> $collected
     * @return array<string,mixed>
     */
    public static function sanitize_collected($collected) {
        if (!is_array($collected)) {
            return array();
        }

        $out = array();
        $string_fields = array(
            'typ_budynku',
            'standard',
            'emitter_type',
            'obecne_ogrzewanie',
            'keep_existing_heat_source',
            'contact_email',
            'postal_code',
            'ventilation_type',
            'dhw_usage',
            'session_id',
        );
        foreach ($string_fields as $key) {
            if (!isset($collected[$key])) {
                continue;
            }
            $out[$key] = sanitize_text_field((string) $collected[$key]);
        }

        $allowed_insulation = array('poor', 'average', 'good', 'very_good');
        if (isset($collected['insulation_level']) || !empty($collected['insulation_confirmed'])) {
            $insulation = self::normalize_insulation_input(
                isset($collected['insulation_level']) ? (string) $collected['insulation_level'] : ''
            );
            if (
                $insulation !== ''
                && in_array($insulation, $allowed_insulation, true)
                && !empty($collected['insulation_confirmed'])
            ) {
                $out['insulation_level'] = $insulation;
                $out['insulation_confirmed'] = true;
            }
        }
        if (isset($collected['powierzchnia'])) {
            $area = (int) $collected['powierzchnia'];
            if ($area >= 40 && $area <= 500) {
                $out['powierzchnia'] = $area;
            }
        }
        if (array_key_exists('dhw_persons', $collected)) {
            $persons = self::normalize_dhw_persons_input($collected['dhw_persons']);
            if ($persons >= 1 && $persons <= 12) {
                $out['dhw_persons'] = $persons;
            }
        }
        if (isset($collected['dhw_usage']) && (string) $collected['dhw_usage'] !== '') {
            $usage = self::normalize_dhw_usage_input($collected['dhw_usage']);
            if ($usage !== '') {
                $out['dhw_usage'] = $usage;
            }
        }
        if (isset($collected['on_corner'])) {
            $out['on_corner'] = self::to_bool($collected['on_corner']);
        }
        if (!empty($collected['assumed_existing_heat_pump'])) {
            $out['assumed_existing_heat_pump'] = true;
        }
        if (!empty($collected['refinement_active'])) {
            $out['refinement_active'] = true;
        }
        if (!empty($collected['refinement_complete'])) {
            $out['refinement_complete'] = true;
        }
        if (!empty($collected['hydraulics_confirmed'])) {
            $out['hydraulics_confirmed'] = true;
        }
        if (array_key_exists('radiators_is_ht', $collected)) {
            $out['radiators_is_ht'] = self::to_bool($collected['radiators_is_ht']);
        }
        if (array_key_exists('has_underfloor_actuators', $collected)) {
            $out['has_underfloor_actuators'] = self::to_bool($collected['has_underfloor_actuators']);
        }
        if (array_key_exists('keep_existing_heat_source', $collected)) {
            $out['keep_existing_heat_source'] = self::to_bool($collected['keep_existing_heat_source']);
        }
        if (isset($collected['last_bufor_display'])) {
            $out['last_bufor_display'] = sanitize_text_field((string) $collected['last_bufor_display']);
        }
        if (isset($collected['calc_revision'])) {
            $out['calc_revision'] = (int) $collected['calc_revision'];
        }

        if (isset($collected['skipped_fields']) && is_array($collected['skipped_fields'])) {
            $skipped = array();
            foreach ($collected['skipped_fields'] as $field) {
                $field = sanitize_text_field((string) $field);
                if ($field !== '') {
                    $skipped[] = $field;
                }
            }
            $out['skipped_fields'] = array_values(array_unique($skipped));
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $collected
     * @return array<string,mixed>
     */
    public static function to_calc_request($collected) {
        $collected = self::sanitize_collected($collected);
        $session_id = !empty($collected['session_id'])
            ? $collected['session_id']
            : 'lead-widget-' . wp_generate_uuid4();

        $building_type = self::map_building_type(isset($collected['typ_budynku']) ? $collected['typ_budynku'] : '');
        $standard = isset($collected['standard']) ? $collected['standard'] : '';
        $construction_year = self::map_construction_year($standard);
        $profile = self::building_profile_for_standard($standard);
        $area = isset($collected['powierzchnia']) ? (int) $collected['powierzchnia'] : 120;
        $emitter = self::map_emitter_type(isset($collected['emitter_type']) ? $collected['emitter_type'] : '');
        $persons = isset($collected['dhw_persons']) ? (int) $collected['dhw_persons'] : 4;
        $usage_profile = self::map_dhw_usage(isset($collected['dhw_usage']) ? $collected['dhw_usage'] : '');
        $ventilation = self::resolve_ventilation_type($collected, $construction_year);
        $location = self::resolve_location(isset($collected['postal_code']) ? $collected['postal_code'] : '');

        $building = array(
            'heated_area' => $area,
            'total_area' => $area,
            'floor_area' => $area,
            'construction_year' => $construction_year,
            'construction_type' => $profile['construction_type'],
            'building_type' => $building_type,
            'windows_type' => $profile['windows_type'],
            'number_doors' => $profile['number_doors'],
            'number_windows' => $profile['number_windows'],
            'source_type' => 'air-water',
            'location_id' => $location['location_id'],
            'include_hot_water' => 'yes',
            'hot_water_persons' => $persons,
            'hot_water_usage' => $usage_profile,
            'indoor_temperature' => self::INDOOR_TEMPERATURE_C,
            'ventilation_type' => $ventilation,
        );

        if (!empty($location['latitude']) && !empty($location['longitude'])) {
            $building['latitude'] = $location['latitude'];
            $building['longitude'] = $location['longitude'];
        }

        if ($building_type === 'row_house') {
            $building['on_corner'] = isset($collected['on_corner']) ? (bool) $collected['on_corner'] : false;
        }

        $insulation_level = self::resolve_insulation_level_for_calc($collected, $profile);
        $building = array_merge($building, self::insulation_level_to_ozc_fields($insulation_level));

        if (!self::should_assume_existing_heat_pump($collected) && self::should_keep_existing_heat_source($collected)) {
            $secondary = self::map_secondary_source(isset($collected['obecne_ogrzewanie']) ? $collected['obecne_ogrzewanie'] : '');
            if ($secondary !== '') {
                $building['secondary_source_type'] = $secondary;
                $building['bivalent_enabled'] = true;
            }
        }

        $profile_id = 'lead_widget_' . ($standard !== '' ? $standard : 'domyslny');
        $hydraulics_inputs = self::build_hydraulics_inputs($collected, $building);

        $context = array(
            'source' => 'lead_widget',
            'channel' => 'fast-kalk',
            'profileId' => $profile_id,
            'mode' => 'orientacyjny',
        );
        if (!empty($hydraulics_inputs)) {
            $context['configurator'] = array(
                'hydraulics_inputs' => $hydraulics_inputs,
            );
        }

        return array(
            'schemaVersion' => '1.0',
            'traceId' => 'lead-widget-' . substr(md5($session_id . '|' . $area), 0, 12),
            'sessionId' => $session_id,
            'lead' => array(
                'sessionId' => $session_id,
                'contact' => self::build_lead_contact($collected),
            ),
            'building' => $building,
            'preferences' => array(
                'hasBuffer' => true,
                'heating' => array(
                    'emitterType' => $emitter,
                    'indoorTemperatureC' => self::INDOOR_TEMPERATURE_C,
                    'ventilationType' => $ventilation,
                ),
                'dhw' => array(
                    'enabled' => true,
                    'persons' => $persons,
                    'usageProfile' => $usage_profile,
                ),
                'options' => array(
                    'pumpOptionId' => 'split',
                ),
            ),
            'context' => $context,
        );
    }

    /**
     * @param array<string,mixed> $collected
     * @param array<string,mixed> $building
     * @return array<string,mixed>
     */
    public static function build_hydraulics_inputs($collected, $building = array()) {
        $inputs = array();

        if (self::emitter_needs_radiators_ht_question($collected)
            && !empty($collected['hydraulics_confirmed'])
            && array_key_exists('radiators_is_ht', $collected)
        ) {
            $inputs['radiators_is_ht'] = (bool) $collected['radiators_is_ht'];
        }

        if (self::emitter_needs_actuators_question($collected)
            && array_key_exists('has_underfloor_actuators', $collected)
        ) {
            $inputs['has_underfloor_actuators'] = (bool) $collected['has_underfloor_actuators'];
        }

        $bivalent_enabled = !empty($building['bivalent_enabled']);
        if ($bivalent_enabled) {
            $inputs['bivalent_enabled'] = true;
            $source = self::map_buffer_bivalent_source(
                isset($collected['obecne_ogrzewanie']) ? (string) $collected['obecne_ogrzewanie'] : ''
            );
            if ($source !== '') {
                $inputs['bivalent_source_type'] = $source;
            }
        }

        return $inputs;
    }

    /**
     * BufferEngine::map_secondary_type compatible values.
     *
     * @param string $obecne
     * @return string
     */
    public static function map_buffer_bivalent_source($obecne) {
        $raw = strtolower(trim($obecne));
        if ($raw === '') {
            return '';
        }
        if (strpos($raw, 'gaz') !== false) {
            return 'gas_boiler';
        }
        if (strpos($raw, 'wegiel') !== false || strpos($raw, 'węgiel') !== false || strpos($raw, 'pellet') !== false) {
            return 'solid_fuel_boiler';
        }
        if (strpos($raw, 'olej') !== false) {
            return 'solid_fuel_boiler';
        }
        return '';
    }

    /**
     * @param array<string,mixed> $collected
     * @return bool
     */
    public static function should_assume_existing_heat_pump($collected) {
        if (!empty($collected['assumed_existing_heat_pump'])) {
            return true;
        }
        $standard = isset($collected['standard']) ? strtolower(trim((string) $collected['standard'])) : '';
        if ($standard === 'bardzo_nowy' || $standard === 'po_2020' || $standard === 'w_budowie') {
            return true;
        }
        return self::map_construction_year($standard) >= 2022;
    }

    /**
     * @param array<string,mixed> $collected
     * @return bool
     */
    public static function should_keep_existing_heat_source($collected) {
        $collected = self::sanitize_collected($collected);
        return array_key_exists('keep_existing_heat_source', $collected)
            && !empty($collected['keep_existing_heat_source']);
    }

    /**
     * @param array<string,mixed> $collected
     * @return bool
     */
    public static function should_ask_keep_existing_heat_source($collected) {
        if (self::should_assume_existing_heat_pump($collected)) {
            return false;
        }
        $current = isset($collected['obecne_ogrzewanie']) ? (string) $collected['obecne_ogrzewanie'] : '';
        return self::map_secondary_source($current) !== '';
    }

    /**
     * @param array<string,mixed> $collected
     * @return true|WP_Error
     */
    public static function validate_collected_for_calculate($collected) {
        $collected = self::sanitize_collected($collected);
        $raw = isset($collected['typ_budynku']) ? (string) $collected['typ_budynku'] : '';
        if ($raw !== '' && self::map_building_type($raw) === '') {
            return new WP_Error(
                'tilw_unsupported_building_type',
                'Nieobsługiwany typ budynku.',
                array('status' => 400)
            );
        }
        return true;
    }

    /**
     * @param string $building_type
     * @return bool
     */
    private static function is_residential_lead_type($building_type) {
        return in_array($building_type, array('single_house', 'double_house', 'row_house'), true);
    }

    /**
     * @param array<string,mixed> $collected
     * @param array<string,mixed> $profile
     * @return string poor|average|good|very_good
     */
    public static function resolve_insulation_level_for_calc($collected, $profile) {
        $allowed = array('poor', 'average', 'good', 'very_good');
        if (!empty($collected['insulation_confirmed']) && !empty($collected['insulation_level'])) {
            $level = self::normalize_insulation_level((string) $collected['insulation_level']);
            if (in_array($level, $allowed, true)) {
                return $level;
            }
        }
        if (self::was_field_skipped($collected, 'insulation_level') && !empty($profile['default_insulation_level'])) {
            $level = (string) $profile['default_insulation_level'];
            if (in_array($level, $allowed, true)) {
                return $level;
            }
        }

        return 'average';
    }

    /**
     * Mirrors kalkulator/js/formDataProcessor.js simplified insulation conversion.
     *
     * @param string $level poor|average|good|very_good
     * @return array<string,mixed>
     */
    private static function insulation_level_to_ozc_fields($level) {
        $out = array();
        if ($level === 'average') {
            $out['external_wall_isolation'] = array('material' => 88, 'size' => 10);
            $out['top_isolation'] = array('material' => 68, 'size' => 15);
            $out['bottom_isolation'] = array('material' => 88, 'size' => 10);
        } elseif ($level === 'good') {
            $out['external_wall_isolation'] = array('material' => 88, 'size' => 18);
            $out['top_isolation'] = array('material' => 68, 'size' => 25);
            $out['bottom_isolation'] = array('material' => 88, 'size' => 15);
        } elseif ($level === 'very_good') {
            $out['external_wall_isolation'] = array('material' => 88, 'size' => 25);
            $out['top_isolation'] = array('material' => 68, 'size' => 30);
            $out['bottom_isolation'] = array('material' => 88, 'size' => 20);
        }
        return $out;
    }

    /**
     * @param string $raw
     * @return string poor|average|good|very_good|""
     */
    private static function normalize_insulation_level($raw) {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return '';
        }
        if (strpos($raw, 'bardzo') !== false || $raw === 'very_good') {
            return 'very_good';
        }
        if (strpos($raw, 'dobr') !== false && strpos($raw, 'bardzo') === false || $raw === 'good') {
            return 'good';
        }
        if (strpos($raw, 'przec') !== false || strpos($raw, 'sredn') !== false || $raw === 'average') {
            return 'average';
        }
        if (
            strpos($raw, 'slab') !== false
            || strpos($raw, 'brak') !== false
            || strpos($raw, 'nieociepl') !== false
            || preg_match('/s[łl]ab/u', $raw)
            || $raw === 'poor'
            || $raw === 'slabe'
        ) {
            return 'poor';
        }
        $allowed = array('poor', 'average', 'good', 'very_good');
        return in_array($raw, $allowed, true) ? $raw : '';
    }

    /**
     * @param string $standard
     * @return array<string,mixed>
     */
    private static function building_profile_for_standard($standard) {
        $key = strtolower(trim($standard));
        $profiles = array(
            'stary' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'old_double_glass',
                'number_doors' => 1,
                'number_windows' => 12,
                'default_insulation_level' => 'poor',
            ),
            'przed_1990' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'old_double_glass',
                'number_doors' => 1,
                'number_windows' => 12,
                'default_insulation_level' => 'poor',
            ),
            'sredni' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'semi_new_double_glass',
                'number_doors' => 1,
                'number_windows' => 10,
                'default_insulation_level' => 'average',
            ),
            '1990_2010' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'semi_new_double_glass',
                'number_doors' => 1,
                'number_windows' => 10,
                'default_insulation_level' => 'average',
            ),
            'nowy' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'new_double_glass',
                'number_doors' => 1,
                'number_windows' => 8,
                'default_insulation_level' => 'good',
            ),
            'po_2010' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'new_double_glass',
                'number_doors' => 1,
                'number_windows' => 8,
                'default_insulation_level' => 'good',
            ),
            'przed_2000' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'old_double_glass',
                'number_doors' => 1,
                'number_windows' => 12,
                'default_insulation_level' => 'poor',
            ),
            '2000_2010' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'semi_new_double_glass',
                'number_doors' => 1,
                'number_windows' => 10,
                'default_insulation_level' => 'average',
            ),
            'w_budowie' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'new_triple_glass',
                'number_doors' => 1,
                'number_windows' => 8,
                'default_insulation_level' => 'very_good',
            ),
            'bardzo_nowy' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'new_triple_glass',
                'number_doors' => 1,
                'number_windows' => 8,
                'default_insulation_level' => 'very_good',
            ),
            'po_2020' => array(
                'construction_type' => 'traditional',
                'windows_type' => 'new_triple_glass',
                'number_doors' => 1,
                'number_windows' => 8,
                'default_insulation_level' => 'very_good',
            ),
        );

        if (isset($profiles[$key])) {
            return $profiles[$key];
        }

        return array(
            'construction_type' => 'traditional',
            'windows_type' => 'semi_new_double_glass',
            'number_doors' => 1,
            'number_windows' => 10,
            'default_insulation_level' => 'average',
        );
    }

    /**
     * @param array<string,mixed> $collected
     * @param int $construction_year
     * @return string
     */
    private static function resolve_ventilation_type($collected, $construction_year) {
        if (!empty($collected['ventilation_type'])) {
            return self::map_ventilation_type((string) $collected['ventilation_type']);
        }
        if ($construction_year < 2000) {
            return 'natural';
        }
        return 'natural';
    }

    /**
     * @param string $raw
     * @return string
     */
    private static function map_ventilation_type($raw) {
        $raw = strtolower(trim($raw));
        if (strpos($raw, 'reku') !== false || strpos($raw, 'odzysk') !== false || strpos($raw, 'recovery') !== false) {
            return 'mechanical_recovery';
        }
        if (strpos($raw, 'mechan') !== false) {
            return 'mechanical';
        }
        return 'natural';
    }

    /**
     * @param string $postal
     * @return array{location_id:string,latitude:float|null,longitude:float|null}
     */
    private static function resolve_location($postal) {
        $location_id = self::map_postal_code_to_location_id($postal);
        $coords_map = self::location_coords_map();
        $coords = isset($coords_map[$location_id]) ? $coords_map[$location_id] : $coords_map['PL_STREFA_III'];

        return array(
            'location_id' => $location_id,
            'latitude' => $coords['lat'],
            'longitude' => $coords['lon'],
        );
    }

    /**
     * @param string $postal
     * @return string
     */
    private static function map_postal_code_to_location_id($postal) {
        $digits = preg_replace('/\D/', '', $postal);
        if (strlen($digits) < 2) {
            return 'PL_STREFA_III';
        }

        $prefix = (int) substr($digits, 0, 2);

        if (in_array($prefix, array(34, 43, 44, 45, 46, 47, 48, 49), true)) {
            return 'PL_ZAKOPANE';
        }
        if ($prefix >= 80 && $prefix <= 84) {
            return 'PL_STREFA_I';
        }
        if ($prefix >= 70 && $prefix <= 79) {
            return 'PL_STREFA_II';
        }
        if ($prefix >= 50 && $prefix <= 59) {
            return 'PL_STREFA_IV';
        }
        if ($prefix >= 10 && $prefix <= 19) {
            return 'PL_STREFA_V';
        }
        if ($prefix >= 30 && $prefix <= 39) {
            return 'PL_STREFA_III';
        }
        if ($prefix >= 0 && $prefix <= 9) {
            return 'PL_STREFA_III';
        }
        if ($prefix >= 20 && $prefix <= 29) {
            return 'PL_STREFA_III';
        }

        return 'PL_STREFA_III';
    }

    /**
     * @param mixed $raw
     * @return int
     */
    public static function normalize_dhw_persons_input($raw) {
        if (is_numeric($raw)) {
            $persons = (int) $raw;
            return ($persons >= 1 && $persons <= 12) ? $persons : 0;
        }

        $text = strtolower(trim((string) $raw));
        if ($text === '') {
            return 0;
        }
        if (preg_match('/2\s*[-–]\s*3/u', $text) || (strpos($text, '2') !== false && strpos($text, '3') !== false)) {
            return 3;
        }
        if (preg_match('/4\s*[-–]\s*5/u', $text) || (strpos($text, '4') !== false && strpos($text, '5') !== false)) {
            return 5;
        }
        if (strpos($text, 'wiecej') !== false || strpos($text, 'więcej') !== false || strpos($text, '>') !== false) {
            return 6;
        }
        if (preg_match('/(\d{1,2})/u', $text, $m)) {
            $persons = (int) $m[1];
            if ($persons >= 1 && $persons <= 12) {
                return $persons;
            }
        }

        return 0;
    }

    /**
     * @param mixed $raw
     * @return string shower|shower_bath|bath|""
     */
    public static function normalize_dhw_usage_input($raw) {
        $text = strtolower(trim((string) $raw));
        if ($text === '') {
            return '';
        }
        if (in_array($text, array('shower', 'shower_bath', 'bath'), true)) {
            return $text;
        }
        if (strpos($text, 'oszcz') !== false || strpos($text, 'eco') !== false) {
            return 'shower';
        }
        if (
            strpos($text, 'intens') !== false
            || strpos($text, 'duzo') !== false
            || strpos($text, 'dużo') !== false
            || strpos($text, 'wann') !== false
        ) {
            return 'bath';
        }
        if (strpos($text, 'standard') !== false || strpos($text, 'normal') !== false) {
            return 'shower_bath';
        }

        return '';
    }

    /**
     * @param string $raw
     * @return string shower|shower_bath|bath
     */
    private static function map_dhw_usage($raw) {
        $canonical = self::normalize_dhw_usage_input($raw);
        if ($canonical === 'shower' || $canonical === 'shower_bath' || $canonical === 'bath') {
            return $canonical;
        }

        return 'shower_bath';
    }

    /**
     * @param array<string,mixed> $collected
     * @return array<string,mixed>
     */
    private static function build_lead_contact($collected) {
        $email = isset($collected['contact_email']) ? sanitize_email((string) $collected['contact_email']) : '';
        if ($email !== '' && is_email($email)) {
            return array('email' => $email);
        }
        return array();
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function to_bool($value) {
        if (is_bool($value)) {
            return $value;
        }
        $raw = strtolower(trim((string) $value));
        return in_array($raw, array('1', 'true', 'yes', 'tak', 't'), true)
            || strpos($raw, 'naro') !== false
            || strpos($raw, 'corner') !== false;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function map_building_type($value) {
        $map = array(
            'wolnostojacy' => 'single_house',
            'wolnostojący' => 'single_house',
            'dom' => 'single_house',
            'single_house' => 'single_house',
            'blizniak' => 'double_house',
            'bliźniak' => 'double_house',
            'double_house' => 'double_house',
            'szeregowiec' => 'row_house',
            'row_house' => 'row_house',
        );
        $key = strtolower(trim($value));
        return isset($map[$key]) ? $map[$key] : '';
    }

    /**
     * @param string $value
     * @return int
     */
    private static function map_construction_year($value) {
        $map = array(
            'stary' => 1995,
            'przed_1990' => 1995,
            'przed_2000' => 1995,
            'sredni' => 2005,
            '1990_2010' => 2005,
            '2000_2010' => 2005,
            'nowy' => 2015,
            'po_2010' => 2015,
            'bardzo_nowy' => 2024,
            'po_2020' => 2024,
            'w_budowie' => 2024,
        );
        $key = strtolower(trim($value));
        return isset($map[$key]) ? (int) $map[$key] : 2000;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function map_secondary_source($value) {
        $map = array(
            'wegiel' => 'solid_fuel',
            'węgiel' => 'solid_fuel',
            'gaz' => 'gas',
            'olej' => 'gas',
            'prad' => 'electric',
            'prąd' => 'electric',
            'elektryczne' => 'electric',
            'pompa' => '',
            'pc' => '',
            'inne' => '',
        );
        $key = strtolower(trim($value));
        if (strpos($key, 'pomp') !== false) {
            return '';
        }
        return isset($map[$key]) ? (string) $map[$key] : '';
    }

    /**
     * @param string $value
     * @return string
     */
    private static function map_emitter_type($value) {
        $raw = strtolower(trim($value));
        if ($raw === '' || $raw === 'nie_wiem') {
            return 'floor_heating';
        }
        if (strpos($raw, 'podlog') !== false || $raw === 'underfloor' || $raw === 'floor_heating' || $raw === 'podlogowka') {
            return 'floor_heating';
        }
        if (strpos($raw, 'grzej') !== false || $raw === 'radiators') {
            return 'radiators';
        }
        if (strpos($raw, 'miesz') !== false) {
            return 'mixed';
        }
        return 'floor_heating';
    }
}
