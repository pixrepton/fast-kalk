<?php



if (!defined('ABSPATH')) {

    exit;

}



/**

 * Heuristic multi-field extraction from free-form Polish answers (one sentence → many params).

 */

final class Topinstal_Lead_Widget_Answer_Extractor {

    /** @var list<string> */

    private static $hydraulics_fields = array(

        'radiators_is_ht',

        'has_underfloor_actuators',

    );



    /**

     * @param string $text

     * @param array<string,mixed> $collected

     * @param string $pending_field

     * @return array<string,mixed>

     */

    public static function extract_from_text($text, $collected = array(), $pending_field = '') {

        $text = trim($text);

        if ($text === '') {

            return array();

        }



        $lower = strtolower($text);

        $delta = array();

        $allow_hydraulics = self::pending_field_allows_hydraulics(trim((string) $pending_field));



        if (preg_match('/(\d{2,3})\s*(m2|m²|metr|mkw)/iu', $text, $m)) {

            $delta['powierzchnia'] = (int) $m[1];

        } elseif (preg_match('/\b(\d{2,3})\b/u', $text, $m) && empty($collected['powierzchnia'])) {

            $value = (int) $m[1];

            if ($value >= 40 && $value <= 500) {

                $delta['powierzchnia'] = $value;

            }

        }



        if ($pending_field === 'dhw_persons' || $pending_field === '') {
            $persons = Topinstal_Lead_Widget_Defaults::normalize_dhw_persons_input($text);
            if ($persons >= 1 && empty($collected['dhw_persons'])) {
                $delta['dhw_persons'] = $persons;
            }
        }

        if ($pending_field === 'dhw_usage' || $pending_field === '') {
            $usage = Topinstal_Lead_Widget_Defaults::normalize_dhw_usage_input($text);
            if ($usage !== '' && empty($collected['dhw_usage'])) {
                $delta['dhw_usage'] = $usage;
            }
        }



        if (preg_match('/(\d{2}-\d{3}|\d{5})/u', $text, $m)) {

            $delta['postal_code'] = $m[1];

        }



        if (strpos($lower, 'reku') !== false || strpos($lower, 'odzysk') !== false) {

            $delta['ventilation_type'] = 'mechanical_recovery';

        } elseif (strpos($lower, 'wentyl') !== false && strpos($lower, 'natural') !== false) {

            $delta['ventilation_type'] = 'natural';

        }



        if (strpos($lower, 'naro') !== false || strpos($lower, 'corner') !== false) {

            $delta['on_corner'] = (strpos($lower, 'nie') === false && strpos($lower, 'tak') !== false)

                || strpos($lower, 'naro') !== false;

        } elseif (preg_match('/\b(tak|nie)\b/u', $lower) && isset($collected['typ_budynku']) && $collected['typ_budynku'] === 'szeregowiec') {

            $delta['on_corner'] = (strpos($lower, 'tak') !== false);

        }



        if (strpos($lower, 'weg') !== false || strpos($lower, 'węg') !== false) {

            $delta['obecne_ogrzewanie'] = 'wegiel';

        } elseif (strpos($lower, 'gaz') !== false) {

            $delta['obecne_ogrzewanie'] = 'gaz';

        } elseif (strpos($lower, 'olej') !== false) {

            $delta['obecne_ogrzewanie'] = 'olej';

        } elseif (strpos($lower, 'prąd') !== false || strpos($lower, 'prad') !== false || strpos($lower, 'elektr') !== false) {

            $delta['obecne_ogrzewanie'] = 'prad';

        }

        if ($pending_field === 'keep_existing_heat_source') {

            $delta['keep_existing_heat_source'] = strpos($lower, 'tak') !== false

                && strpos($lower, 'nie') === false;

        }



        if (

            strpos($lower, 'ociepl') !== false

            || strpos($lower, 'izol') !== false

            || strpos($lower, 'styrop') !== false

            || strpos($lower, 'weln') !== false

        ) {

            $insulation = Topinstal_Lead_Widget_Defaults::normalize_insulation_input($text);

            if ($insulation !== '') {

                $delta['insulation_level'] = $insulation;

            }

        }



        if (strpos($lower, 'podlog') !== false) {

            $delta['emitter_type'] = 'podlogowka';

        } elseif (strpos($lower, 'grzej') !== false) {

            $delta['emitter_type'] = 'grzejniki';

        }



        if ($allow_hydraulics) {

            if (

                strpos($lower, 'stal') !== false

                || strpos($lower, 'żel') !== false

                || strpos($lower, 'zel') !== false

                || strpos($lower, 'wysokotemper') !== false

            ) {

                $delta['radiators_is_ht'] = true;

                $delta['hydraulics_confirmed'] = true;

            } elseif (

                strpos($lower, 'płyt') !== false

                || strpos($lower, 'plyt') !== false

                || strpos($lower, 'alum') !== false

            ) {

                $delta['radiators_is_ht'] = false;

                $delta['hydraulics_confirmed'] = true;

            }



            if (strpos($lower, 'siłown') !== false || strpos($lower, 'silown') !== false || strpos($lower, 'stref') !== false) {

                $delta['has_underfloor_actuators'] = strpos($lower, 'nie') === false;

                $delta['hydraulics_confirmed'] = true;

            }

        }



        return $delta;

    }



    /**

     * @param string $pending_field

     * @return bool

     */

    private static function pending_field_allows_hydraulics($pending_field) {

        return in_array($pending_field, self::$hydraulics_fields, true);

    }

}
