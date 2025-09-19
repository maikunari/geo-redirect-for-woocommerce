<?php
/**
 * Plugin Name:       WooCommerce Geo-Redirect for Dual Stores
 * Plugin URI:        https://github.com/maikunari/geo-redirect-for-woocommerce
 * Description:       Automatically redirects visitors between regional WooCommerce stores (.ca and .com) based on their geographic location using WooCommerce's built-in geolocation features.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Mike Sewell
 * Author URI:        https://github.com/maikunari/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-geo-redirect
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.0
 * 
 * @package WooCommerceGeoRedirect
 */

// Prevent direct access
defined('ABSPATH') || exit;

// Define plugin constants
define('WC_GEO_REDIRECT_VERSION', '1.0.0');
define('WC_GEO_REDIRECT_PLUGIN_FILE', __FILE__);
define('WC_GEO_REDIRECT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_GEO_REDIRECT_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 * 
 * @since 1.0.0
 */
class WC_Geo_Redirect {
    
    /**
     * Single instance of the class
     * 
     * @var WC_Geo_Redirect
     */
    protected static $_instance = null;
    
    /**
     * Canadian domain
     * 
     * @var string
     */
    private $ca_domain;
    
    /**
     * US domain
     * 
     * @var string
     */
    private $us_domain;
    
    /**
     * Cookie name for storing user preference
     * 
     * @var string
     */
    private $cookie_name = 'wc_geo_store_choice';
    
    /**
     * Cookie expiration in days
     * 
     * @var int
     */
    private $cookie_duration = 30;
    
    /**
     * Main instance
     * 
     * @since 1.0.0
     * @return WC_Geo_Redirect
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Constructor
     * 
     * @since 1.0.0
     */
    public function __construct() {
        // Load configuration
        $this->ca_domain = defined('WC_GEO_CA_DOMAIN') ? WC_GEO_CA_DOMAIN : get_option('wc_geo_redirect_ca_domain', 'yourstore.ca');
        $this->us_domain = defined('WC_GEO_US_DOMAIN') ? WC_GEO_US_DOMAIN : get_option('wc_geo_redirect_us_domain', 'yourstore.com');
        
        // Initialize hooks
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     * 
     * @since 1.0.0
     */
    private function init_hooks() {
        // Check WooCommerce dependency
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        
        // Frontend redirect logic - use template_redirect for better compatibility
        if (!is_admin() && !wp_doing_ajax() && !wp_doing_cron()) {
            add_action('template_redirect', array($this, 'maybe_redirect'), 1);
        }
        
        // Add store switcher
        add_action('wp_footer', array($this, 'render_store_switcher'));
        
        // Admin settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Plugin activation/deactivation
        register_activation_hook(WC_GEO_REDIRECT_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(WC_GEO_REDIRECT_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Add settings link in plugins page
        add_filter('plugin_action_links_' . plugin_basename(WC_GEO_REDIRECT_PLUGIN_FILE), array($this, 'add_settings_link'));
        
        // HPOS compatibility
        add_action('before_woocommerce_init', function() {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WC_GEO_REDIRECT_PLUGIN_FILE, true);
            }
        });
    }
    
    /**
     * Check plugin dependencies
     * 
     * @since 1.0.0
     */
    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Check if geolocation is enabled
        $customer_location = get_option('woocommerce_default_customer_address');
        if (!in_array($customer_location, array('geolocation', 'geolocation_ajax'), true)) {
            add_action('admin_notices', array($this, 'geolocation_disabled_notice'));
        }
    }
    
    /**
     * WooCommerce missing notice
     * 
     * @since 1.0.0
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('WooCommerce Geo-Redirect requires WooCommerce to be installed and activated.', 'wc-geo-redirect'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Geolocation disabled notice
     * 
     * @since 1.0.0
     */
    public function geolocation_disabled_notice() {
        ?>
        <div class="notice notice-warning">
            <p>
                <?php 
                printf(
                    /* translators: %s: Settings URL */
                    esc_html__('WooCommerce Geo-Redirect works best with geolocation enabled. %s', 'wc-geo-redirect'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings')) . '">' . esc_html__('Enable it here', 'wc-geo-redirect') . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Maybe redirect user based on location
     * 
     * @since 1.0.0
     */
    public function maybe_redirect() {
        // Don't redirect if not enabled
        if (!get_option('wc_geo_redirect_enabled', true)) {
            return;
        }
        
        // Skip login/register/password reset pages
        if (is_user_logged_in() || is_login() || is_register() || wp_login_url() === ( $_SERVER['REQUEST_URI'] ?? '' )) {
            return;
        }
        
        // Skip wp-admin, wp-login.php, and system files
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $skip_paths = array('/wp-admin', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-json');
        foreach ($skip_paths as $path) {
            if (strpos($request_uri, $path) !== false) {
                return;
            }
        }
        
        // Skip if already redirected (prevent loops)
        if (isset($_GET['geo_redirected'])) {
            return;
        }
        
        // Security check for nonce if coming from form submission
        if (isset($_POST['_wpnonce']) && !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wc_geo_redirect')) {
            return;
        }
        
        // Skip if user has manually chosen a store
        if (isset($_COOKIE[$this->cookie_name])) {
            return;
        }
        
        // Skip search engine bots
        if ($this->is_bot()) {
            return;
        }
        
        // Skip if in preview mode
        if (is_preview()) {
            return;
        }
        
        // Skip local development domains
        if ($this->is_local_development()) {
            return;
        }
        
        // Get visitor country
        $country = $this->get_visitor_country();
        if (empty($country)) {
            return;
        }
        
        // Determine if redirect is needed
        $current_host = sanitize_text_field($_SERVER['HTTP_HOST'] ?? '');
        $redirect_to = null;
        
        // Check for domain match more precisely
        $is_ca_site = ($current_host === $this->ca_domain || $current_host === 'www.' . $this->ca_domain);
        $is_us_site = ($current_host === $this->us_domain || $current_host === 'www.' . $this->us_domain);
        
        if ('US' === $country && $is_ca_site) {
            $redirect_to = $this->us_domain;
        } elseif ('CA' === $country && $is_us_site) {
            $redirect_to = $this->ca_domain;
        }
        
        if ($redirect_to && $redirect_to !== $current_host) {
            $this->perform_redirect($redirect_to);
        }
    }
    
    /**
     * Perform the redirect
     * 
     * @since 1.0.0
     * @param string $domain Target domain
     */
    private function perform_redirect($domain) {
        // Build redirect URL - maintain path and query string
        $request_uri = sanitize_text_field($_SERVER['REQUEST_URI'] ?? '/');
        
        // Add geo_redirected parameter to prevent loops
        $separator = (strpos($request_uri, '?') !== false) ? '&' : '?';
        $redirect_url = 'https://' . $domain . $request_uri . $separator . 'geo_redirected=1';
        
        // Validate URL
        if (!wp_http_validate_url($redirect_url)) {
            return;
        }
        
        // Log redirect for debugging
        if (WP_DEBUG && WP_DEBUG_LOG) {
            error_log('WC Geo Redirect: Redirecting to ' . $redirect_url);
        }
        
        // Use WordPress safe redirect with 302 (temporary)
        wp_safe_redirect($redirect_url, 302);
        exit;
    }
    
    /**
     * Check if local development environment
     * 
     * @since 1.0.0
     * @return bool
     */
    private function is_local_development() {
        $local_domains = array('localhost', '.local', '.test', '.dev', '127.0.0.1', '::1');
        $current_host = $_SERVER['HTTP_HOST'] ?? '';
        
        foreach ($local_domains as $local) {
            if (strpos($current_host, $local) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get visitor's country code
     * 
     * @since 1.0.0
     * @return string|null Country code or null if not found
     */
    private function get_visitor_country() {
        // Testing override (only in debug mode)
        if (WP_DEBUG && isset($_GET['test_country'])) {
            return sanitize_text_field($_GET['test_country']);
        }
        
        // Method 1: Check for CDN country headers (CloudFlare, etc)
        $cdn_headers = array(
            'HTTP_CF_IPCOUNTRY',        // CloudFlare
            'HTTP_CLOUDFRONT_VIEWER_COUNTRY', // AWS CloudFront
            'HTTP_X_COUNTRY_CODE',       // Various CDNs
            'HTTP_X_GEO_COUNTRY'         // Various CDNs
        );
        
        foreach ($cdn_headers as $header) {
            if (!empty($_SERVER[$header]) && $_SERVER[$header] !== 'XX') {
                return sanitize_text_field($_SERVER[$header]);
            }
        }
        
        // Method 2: WooCommerce geolocation cookie
        if (isset($_COOKIE['woocommerce_geo_country'])) {
            return sanitize_text_field($_COOKIE['woocommerce_geo_country']);
        }
        
        // Method 3: WooCommerce Geolocation API
        if (class_exists('WC_Geolocation')) {
            $geo = WC_Geolocation::geolocate_ip();
            if (!empty($geo['country'])) {
                return $geo['country'];
            }
        }
        
        return null;
    }
    
    /**
     * Check if user agent is a bot
     * 
     * @since 1.0.0
     * @return bool
     */
    private function is_bot() {
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }
        
        $user_agent = strtolower(sanitize_text_field($_SERVER['HTTP_USER_AGENT']));
        $bot_patterns = array(
            'bot', 'crawler', 'spider', 'crawling', 
            'google', 'bing', 'yandex', 'baidu',
            'duckduckgo', 'facebook', 'twitter', 'linkedin',
            'whatsapp', 'telegram', 'slack'
        );
        
        foreach ($bot_patterns as $pattern) {
            if (false !== strpos($user_agent, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Render store switcher in footer
     * 
     * @since 1.0.0
     */
    public function render_store_switcher() {
        // Don't show in admin or during AJAX
        if (is_admin() || wp_doing_ajax()) {
            return;
        }
        
        $current_host = sanitize_text_field($_SERVER['HTTP_HOST'] ?? '');
        $is_ca = false !== strpos($current_host, '.ca');
        $other_domain = $is_ca ? $this->us_domain : $this->ca_domain;
        
        // Security nonce for JavaScript
        $nonce = wp_create_nonce('wc_geo_redirect_switch');
        ?>
        <div id="wc-geo-store-switcher" style="text-align: center; padding: 10px; background: #f8f8f8; font-size: 14px; border-top: 1px solid #ddd;">
            <?php
            printf(
                /* translators: %s: Country flag and name */
                esc_html__('Shopping from %s', 'wc-geo-redirect'),
                $is_ca ? '🇨🇦 Canada' : '🇺🇸 USA'
            );
            ?> | 
            <a href="#" id="wc-geo-switch-link" data-domain="<?php echo esc_attr($other_domain); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
                <?php
                printf(
                    /* translators: %s: Store name */
                    esc_html__('Switch to %s Store', 'wc-geo-redirect'),
                    $is_ca ? 'US' : 'Canadian'
                );
                ?>
            </a>
        </div>
        
        <script type="text/javascript">
        (function() {
            'use strict';
            
            document.getElementById('wc-geo-switch-link').addEventListener('click', function(e) {
                e.preventDefault();
                
                // Set cookie to remember manual choice
                var expires = new Date();
                expires.setTime(expires.getTime() + (<?php echo absint($this->cookie_duration); ?> * 24 * 60 * 60 * 1000));
                document.cookie = '<?php echo esc_js($this->cookie_name); ?>=manual;path=/;expires=' + expires.toUTCString() + ';SameSite=Lax';
                
                // Redirect to same page on other store
                var domain = this.getAttribute('data-domain');
                var protocol = window.location.protocol;
                var pathname = window.location.pathname;
                var search = window.location.search;
                
                window.location.href = protocol + '//' + domain + pathname + search;
            });
        })();
        </script>
        <?php
    }
    
    /**
     * Add admin menu
     * 
     * @since 1.0.0
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Geo-Redirect Settings', 'wc-geo-redirect'),
            __('Geo-Redirect', 'wc-geo-redirect'),
            'manage_options',
            'wc-geo-redirect',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Register settings
     * 
     * @since 1.0.0
     */
    public function register_settings() {
        register_setting(
            'wc_geo_redirect_settings',
            'wc_geo_redirect_ca_domain',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'yourstore.ca'
            )
        );
        
        register_setting(
            'wc_geo_redirect_settings',
            'wc_geo_redirect_us_domain',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'yourstore.com'
            )
        );
        
        register_setting(
            'wc_geo_redirect_settings',
            'wc_geo_redirect_enabled',
            array(
                'type' => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => true
            )
        );
    }
    
    /**
     * Render admin settings page
     * 
     * @since 1.0.0
     */
    public function render_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if settings were saved
        if (isset($_GET['settings-updated'])) {
            add_settings_error('wc_geo_redirect_messages', 'wc_geo_redirect_message', __('Settings Saved', 'wc-geo-redirect'), 'updated');
        }
        
        // Show error/update messages
        settings_errors('wc_geo_redirect_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('wc_geo_redirect_settings');
                ?>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wc_geo_redirect_enabled"><?php esc_html_e('Enable Geo-Redirect', 'wc-geo-redirect'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="wc_geo_redirect_enabled" name="wc_geo_redirect_enabled" value="1" <?php checked(get_option('wc_geo_redirect_enabled', true)); ?> />
                            <p class="description"><?php esc_html_e('Enable or disable automatic geo-redirection.', 'wc-geo-redirect'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="wc_geo_redirect_ca_domain"><?php esc_html_e('Canadian Domain', 'wc-geo-redirect'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wc_geo_redirect_ca_domain" name="wc_geo_redirect_ca_domain" value="<?php echo esc_attr(get_option('wc_geo_redirect_ca_domain', 'yourstore.ca')); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Enter your .ca domain (without https://)', 'wc-geo-redirect'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="wc_geo_redirect_us_domain"><?php esc_html_e('US Domain', 'wc-geo-redirect'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wc_geo_redirect_us_domain" name="wc_geo_redirect_us_domain" value="<?php echo esc_attr(get_option('wc_geo_redirect_us_domain', 'yourstore.com')); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Enter your .com domain (without https://)', 'wc-geo-redirect'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Settings', 'wc-geo-redirect')); ?>
            </form>
            
            <div class="card">
                <h2><?php esc_html_e('Testing Your Configuration', 'wc-geo-redirect'); ?></h2>
                <p><?php esc_html_e('To test the geo-redirect functionality:', 'wc-geo-redirect'); ?></p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Use a VPN service to change your apparent location', 'wc-geo-redirect'); ?></li>
                    <li><?php esc_html_e('Clear your browser cookies between tests', 'wc-geo-redirect'); ?></li>
                    <li><?php esc_html_e('Use incognito/private browsing mode for testing', 'wc-geo-redirect'); ?></li>
                    <li><?php esc_html_e('Check that WooCommerce geolocation is enabled in WooCommerce → Settings → General', 'wc-geo-redirect'); ?></li>
                </ul>
                
                <h3><?php esc_html_e('Current Status', 'wc-geo-redirect'); ?></h3>
                <p>
                    <?php
                    $geo_enabled = get_option('woocommerce_default_customer_address');
                    if (in_array($geo_enabled, array('geolocation', 'geolocation_ajax'), true)) {
                        echo '<span style="color: green;">✓</span> ' . esc_html__('WooCommerce geolocation is enabled', 'wc-geo-redirect');
                    } else {
                        echo '<span style="color: red;">✗</span> ' . esc_html__('WooCommerce geolocation is disabled', 'wc-geo-redirect');
                    }
                    ?>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Add settings link to plugins page
     * 
     * @since 1.0.0
     * @param array $links
     * @return array
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-geo-redirect')) . '">' . __('Settings', 'wc-geo-redirect') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Plugin activation
     * 
     * @since 1.0.0
     */
    public function activate() {
        // Set default options
        add_option('wc_geo_redirect_ca_domain', 'yourstore.ca');
        add_option('wc_geo_redirect_us_domain', 'yourstore.com');
        add_option('wc_geo_redirect_enabled', true);
        
        // Clear any existing transients
        delete_transient('wc_geo_redirect_cache');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     * 
     * @since 1.0.0
     */
    public function deactivate() {
        // Clean up transients
        delete_transient('wc_geo_redirect_cache');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 * 
 * @since 1.0.0
 * @return WC_Geo_Redirect
 */
function wc_geo_redirect() {
    return WC_Geo_Redirect::instance();
}

// Initialize plugin
add_action('plugins_loaded', 'wc_geo_redirect', 0);