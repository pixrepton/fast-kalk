<?php
/**
 * Plugin Name: TOP-INSTAL Lead Widget
 * Description: Wstępny dobór pompy ciepła — widget leadgen z chatem AI i silnikami kalk-top.
 * Version: 0.6.7
 * Author: TOP-INSTAL
 * Text Domain: topinstal-lead-widget
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TOPINSTAL_LEAD_WIDGET_VERSION', '0.6.7');
define('TOPINSTAL_LEAD_WIDGET_DIR', plugin_dir_path(__FILE__));
define('TOPINSTAL_LEAD_WIDGET_URL', plugin_dir_url(__FILE__));

require_once TOPINSTAL_LEAD_WIDGET_DIR . 'includes/class-rate-limit.php';
require_once TOPINSTAL_LEAD_WIDGET_DIR . 'includes/class-defaults.php';
require_once TOPINSTAL_LEAD_WIDGET_DIR . 'includes/class-session-store.php';
require_once TOPINSTAL_LEAD_WIDGET_DIR . 'includes/class-answer-extractor.php';
require_once TOPINSTAL_LEAD_WIDGET_DIR . 'includes/class-calculator.php';
require_once TOPINSTAL_LEAD_WIDGET_DIR . 'includes/class-chat.php';
require_once TOPINSTAL_LEAD_WIDGET_DIR . 'includes/class-lead-registry.php';
require_once TOPINSTAL_LEAD_WIDGET_DIR . 'includes/class-offer-dispatch.php';
require_once TOPINSTAL_LEAD_WIDGET_DIR . 'includes/class-os-event-client.php';

/**
 * Bootstrap plugin.
 */
final class Topinstal_Lead_Widget_Plugin {
    const OPTION_GROUP = 'topinstal_lead_widget';
    const REST_NAMESPACE = 'topinstal-lead/v1';
    const SHORTCODE = 'topinstal_lead_widget';

    /**
     * @var bool
     */
    private static $shortcode_used = false;

    /**
     * @return void
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'register_shortcode'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue_assets'), 20);
        add_action('elementor/frontend/after_enqueue_scripts', array(__CLASS__, 'enqueue_widget_assets'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_menu', array(__CLASS__, 'register_settings_page'));
    }

    /**
     * @return void
     */
    public static function register_shortcode() {
        add_shortcode('topinstal_lead_widget', array(__CLASS__, 'render_shortcode'));
    }

    /**
     * @param array<string,mixed>|string $atts
     * @return string
     */
    public static function render_shortcode($atts) {
        self::$shortcode_used = true;
        self::enqueue_widget_assets();

        $config = self::get_public_config();
        $attrs = sprintf(
            ' data-rest-base="%s" data-nonce="%s" data-pdf-url="%s" data-cta-url="%s"',
            esc_attr($config['restBase']),
            esc_attr($config['nonce']),
            esc_attr($config['pdfUrl']),
            esc_attr($config['ctaUrl'])
        );

        return '<div id="topinstal-lead-widget-root" class="tilw-root" aria-live="polite"' . $attrs . '></div>';
    }

    /**
     * Enqueue early when shortcode is in post/Elementor JSON (fixes page builders).
     *
     * @return void
     */
    public static function maybe_enqueue_assets() {
        if (self::$shortcode_used || self::current_page_has_shortcode()) {
            self::enqueue_widget_assets();
        }
    }

    /**
     * @return bool
     */
    private static function current_page_has_shortcode() {
        if (is_singular()) {
            $post_id = (int) get_queried_object_id();
            return $post_id > 0 && self::post_contains_shortcode($post_id);
        }

        if (is_front_page() || is_home()) {
            $front_id = (int) get_option('page_on_front');
            if ($front_id > 0) {
                return self::post_contains_shortcode($front_id);
            }
            $posts_page_id = (int) get_option('page_for_posts');
            if (is_home() && $posts_page_id > 0) {
                return self::post_contains_shortcode($posts_page_id);
            }
        }

        return false;
    }

    /**
     * @param int $post_id
     * @return bool
     */
    private static function post_contains_shortcode($post_id) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return false;
        }

        if (has_shortcode($post->post_content, self::SHORTCODE)) {
            return true;
        }

        $elementor_raw = get_post_meta($post_id, '_elementor_data', true);
        if (is_string($elementor_raw) && $elementor_raw !== '' && strpos($elementor_raw, self::SHORTCODE) !== false) {
            return true;
        }

        return false;
    }

    /**
     * @return array{restBase:string,nonce:string,pdfUrl:string,ctaUrl:string}
     */
    private static function get_public_config() {
        return array(
            'restBase' => self::sanitize_ascii_value(esc_url_raw(rest_url(self::REST_NAMESPACE))),
            'nonce' => self::sanitize_ascii_value(wp_create_nonce('wp_rest')),
            'pdfUrl' => self::sanitize_ascii_value(esc_url_raw(self::get_option('pdf_url', ''))),
            'ctaUrl' => self::sanitize_ascii_value(esc_url_raw(self::get_option('cta_url', ''))),
        );
    }

    /**
     * Strip UTF-8 BOM and non-ASCII from values used in fetch headers (fixes ByteString error).
     *
     * @param string $value
     * @return string
     */
    public static function sanitize_ascii_value($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value !== '' && strncmp($value, "\xEF\xBB\xBF", 3) === 0) {
            $value = substr($value, 3);
        }
        $value = preg_replace('/[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{2060}]/u', '', $value) ?? $value;
        $cleaned = preg_replace('/[^\x20-\x7E]/', '', $value);
        return is_string($cleaned) ? $cleaned : $value;
    }

    /**
     * @return void
     */
    public static function register_assets() {
        wp_register_style(
            'topinstal-lead-widget',
            TOPINSTAL_LEAD_WIDGET_URL . 'assets/widget.css',
            array(),
            TOPINSTAL_LEAD_WIDGET_VERSION
        );
        wp_register_script(
            'topinstal-lead-widget',
            TOPINSTAL_LEAD_WIDGET_URL . 'assets/widget.js',
            array(),
            TOPINSTAL_LEAD_WIDGET_VERSION,
            true
        );
    }

    /**
     * @return void
     */
    public static function enqueue_widget_assets() {
        self::register_assets();
        wp_enqueue_style('topinstal-lead-widget');
        wp_enqueue_script('topinstal-lead-widget');
        wp_localize_script('topinstal-lead-widget', 'TopinstalLeadWidget', self::get_public_config());
    }

    /**
     * @return void
     */
    public static function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/chat',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array('Topinstal_Lead_Widget_Chat', 'handle'),
                'permission_callback' => array(__CLASS__, 'rest_permission'),
            )
        );
        register_rest_route(
            self::REST_NAMESPACE,
            '/calculate',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array('Topinstal_Lead_Widget_Calculator', 'handle'),
                'permission_callback' => array(__CLASS__, 'rest_permission'),
            )
        );
        register_rest_route(
            self::REST_NAMESPACE,
            '/register',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array('Topinstal_Lead_Widget_Lead_Registry', 'handle'),
                'permission_callback' => array(__CLASS__, 'rest_permission'),
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function rest_permission($request) {
        $nonce = self::sanitize_ascii_value((string) $request->get_header('X-WP-Nonce'));
        if ($nonce === '') {
            $nonce = self::sanitize_ascii_value((string) $request->get_param('nonce'));
        }
        if ($nonce !== '' && wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }
        return new WP_Error('tilw_forbidden', 'Invalid nonce.', array('status' => 403));
    }

    /**
     * @return void
     */
    public static function register_settings() {
        $fields = array(
            'anthropic_api_key',
            'anthropic_model',
            'calc_agent_api_key',
            'calc_rest_url',
            'node_b_registry_url',
            'node_b_registry_token',
            'pdf_url',
            'cta_url',
            'generator_url',
            'generator_agent_key',
            'operator_email',
            'lead_email_override',
            'offer_test_mode',
            'mail_from',
        );
        foreach ($fields as $field) {
            register_setting(
                self::OPTION_GROUP,
                'topinstal_lead_widget_' . $field,
                array(
                    'type' => 'string',
                    'sanitize_callback' => array(__CLASS__, 'sanitize_setting'),
                    'default' => '',
                )
            );
        }
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function sanitize_setting($value) {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return void
     */
    public static function register_settings_page() {
        add_options_page(
            'TOP-INSTAL Lead Widget',
            'TOP-INSTAL Lead Widget',
            'manage_options',
            'topinstal-lead-widget',
            array(__CLASS__, 'render_settings_page')
        );
    }

    /**
     * @return void
     */
    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>TOP-INSTAL Lead Widget</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="anthropic_api_key">Anthropic API Key</label></th>
                        <td><input type="password" class="regular-text" id="anthropic_api_key" name="topinstal_lead_widget_anthropic_api_key" value="<?php echo esc_attr(self::get_option('anthropic_api_key')); ?>" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="anthropic_model">Anthropic model</label></th>
                        <td><input type="text" class="regular-text" id="anthropic_model" name="topinstal_lead_widget_anthropic_model" value="<?php echo esc_attr(self::get_option('anthropic_model', 'claude-sonnet-4-20250514')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="calc_agent_api_key">kalk-top Agent Key</label></th>
                        <td><input type="password" class="regular-text" id="calc_agent_api_key" name="topinstal_lead_widget_calc_agent_api_key" value="<?php echo esc_attr(self::get_option('calc_agent_api_key')); ?>" autocomplete="off" />
                        <p class="description">Ten sam co <code>TOPINSTAL_CALC_AGENT_API_KEY</code> — wywołanie calculate-offer z PHP.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="calc_rest_url">calculate-offer URL</label></th>
                        <td><input type="url" class="large-text" id="calc_rest_url" name="topinstal_lead_widget_calc_rest_url" value="<?php echo esc_attr(self::get_option('calc_rest_url', rest_url('topinstal/v1/calculate-offer'))); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="node_b_registry_url">Node B registry URL</label></th>
                        <td><input type="url" class="large-text" id="node_b_registry_url" name="topinstal_lead_widget_node_b_registry_url" value="<?php echo esc_attr(self::get_option('node_b_registry_url', 'http://127.0.0.1:8766')); ?>" />
                        <p class="description">Baza gmail-agent (np. <code>http://127.0.0.1:8766</code> host), nie cieplo-worker :8000.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="node_b_registry_token">Node B registry token</label></th>
                        <td><input type="password" class="regular-text" id="node_b_registry_token" name="topinstal_lead_widget_node_b_registry_token" value="<?php echo esc_attr(self::get_option('node_b_registry_token')); ?>" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pdf_url">PDF URL (przykładowa oferta)</label></th>
                        <td><input type="url" class="large-text" id="pdf_url" name="topinstal_lead_widget_pdf_url" value="<?php echo esc_attr(self::get_option('pdf_url')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cta_url">CTA URL</label></th>
                        <td><input type="url" class="large-text" id="cta_url" name="topinstal_lead_widget_cta_url" value="<?php echo esc_attr(self::get_option('cta_url')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="generator_url">Generator URL</label></th>
                        <td><input type="url" class="large-text" id="generator_url" name="topinstal_lead_widget_generator_url" value="<?php echo esc_attr(self::get_option('generator_url')); ?>" />
                        <p class="description">Baza WP z top-instal-generator (np. <code>https://topinstal.com.pl</code>).</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="generator_agent_key">Generator Agent Key</label></th>
                        <td><input type="password" class="regular-text" id="generator_agent_key" name="topinstal_lead_widget_generator_agent_key" value="<?php echo esc_attr(self::get_option('generator_agent_key')); ?>" autocomplete="off" />
                        <p class="description">Puste = ten sam co kalk-top Agent Key.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="operator_email">Operator e-mail ([NOWY LEAD])</label></th>
                        <td><input type="email" class="regular-text" id="operator_email" name="topinstal_lead_widget_operator_email" value="<?php echo esc_attr(self::get_option('operator_email')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lead_email_override">Lead e-mail override (test)</label></th>
                        <td><input type="email" class="regular-text" id="lead_email_override" name="topinstal_lead_widget_lead_email_override" value="<?php echo esc_attr(self::get_option('lead_email_override')); ?>" />
                        <p class="description">Gdy wyłączony tryb testowy — oferta trafia tutaj zamiast na adres klienta.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="offer_test_mode">Tryb testowy oferty</label></th>
                        <td>
                            <input type="hidden" name="topinstal_lead_widget_offer_test_mode" value="0" />
                            <label><input type="checkbox" id="offer_test_mode" name="topinstal_lead_widget_offer_test_mode" value="1" <?php checked(self::get_option('offer_test_mode', '1'), '1'); ?> /> Tylko operator dostaje e-mail (bez wysyłki do klienta)</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mail_from">Mail From</label></th>
                        <td><input type="email" class="regular-text" id="mail_from" name="topinstal_lead_widget_mail_from" value="<?php echo esc_attr(self::get_option('mail_from')); ?>" />
                        <p class="description">Opcjonalnie — domyślnie admin_email WP.</p></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function get_option($key, $default = '') {
        $const_map = array(
            'calc_agent_api_key' => 'TOPINSTAL_CALC_AGENT_API_KEY',
            'node_b_registry_token' => 'NODE_B_REGISTRY_TOKEN',
            'anthropic_api_key' => 'ANTHROPIC_API_KEY',
        );
        if (isset($const_map[$key]) && defined($const_map[$key])) {
            $from_const = constant($const_map[$key]);
            if (is_string($from_const) && trim($from_const) !== '') {
                return trim($from_const);
            }
        }

        $value = get_option('topinstal_lead_widget_' . $key, $default);
        return is_string($value) ? $value : (string) $default;
    }
}

Topinstal_Lead_Widget_Plugin::init();
