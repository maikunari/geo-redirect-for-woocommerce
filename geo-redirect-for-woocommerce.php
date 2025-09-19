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
        
        // Frontend logic based on mode
        $mode = get_option('wc_geo_redirect_mode', 'popup');
        if (!is_admin() && !wp_doing_ajax() && !wp_doing_cron()) {
            if ($mode === 'redirect') {
                add_action('template_redirect', array($this, 'maybe_redirect'), 1);
            }
        }

        // Add store switcher
        add_action('wp_footer', array($this, 'render_store_switcher'));

        // AJAX handlers
        add_action('wp_ajax_wc_geo_check_location', array($this, 'ajax_check_location'));
        add_action('wp_ajax_nopriv_wc_geo_check_location', array($this, 'ajax_check_location'));

        // Enqueue scripts for popup mode
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
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

        // Add allowed redirect hosts for cross-domain redirects
        add_filter('allowed_redirect_hosts', array($this, 'add_allowed_redirect_hosts'));

        // Cache compatibility - Breeze
        add_filter('breeze_excluded_urls', array($this, 'breeze_exclude_ajax'));

        // Cache compatibility - exclude AJAX from caching
        add_action('init', array($this, 'set_cache_headers'));
    }
    
    /**
     * Add allowed redirect hosts
     *
     * @since 1.0.0
     * @param array $hosts Allowed hosts
     * @return array
     */
    public function add_allowed_redirect_hosts($hosts) {
        // Add both configured domains to allowed hosts
        $hosts[] = $this->ca_domain;
        $hosts[] = $this->us_domain;

        // Also add www versions
        $hosts[] = 'www.' . $this->ca_domain;
        $hosts[] = 'www.' . $this->us_domain;

        return $hosts;
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
        
        // Get request URI once
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Skip wp-admin, wp-login.php, and system files
        $skip_paths = array('/wp-admin', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-json', '/wp-includes', '/wp-content/uploads');
        foreach ($skip_paths as $path) {
            if (strpos($request_uri, $path) !== false) {
                return;
            }
        }

        // Skip if user is logged in (check after path exclusions to avoid issues)
        if (is_user_logged_in()) {
            return;
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
        
        // Extract just the domain part (without paths) for comparison
        $current_host_clean = str_replace('www.', '', $current_host);

        // Extract domain parts from configured domains (handle cases like "github.com/maikunari")
        $ca_domain_parts = explode('/', str_replace('www.', '', $this->ca_domain));
        $ca_domain_clean = $ca_domain_parts[0];

        $us_domain_parts = explode('/', str_replace('www.', '', $this->us_domain));
        $us_domain_clean = $us_domain_parts[0];

        // Debug logging
        if (WP_DEBUG && WP_DEBUG_LOG) {
            error_log('WC Geo Redirect Debug: Current host = ' . $current_host);
            error_log('WC Geo Redirect Debug: CA domain = ' . $this->ca_domain);
            error_log('WC Geo Redirect Debug: US domain = ' . $this->us_domain);
            error_log('WC Geo Redirect Debug: Country = ' . $country);
            error_log('WC Geo Redirect Debug: Current host clean = ' . $current_host_clean);
            error_log('WC Geo Redirect Debug: CA domain clean = ' . $ca_domain_clean);
            error_log('WC Geo Redirect Debug: US domain clean = ' . $us_domain_clean);
        }

        // Determine which site we're on and where to redirect
        $redirect_to = null;

        // If we're on the CA domain and visitor is from US
        if ($country === 'US' && $current_host_clean === $ca_domain_clean) {
            $redirect_to = $this->us_domain;
            if (WP_DEBUG && WP_DEBUG_LOG) {
                error_log('WC Geo Redirect: US visitor on CA site, redirecting to: ' . $redirect_to);
            }
        }
        // If we're on the US domain and visitor is from CA
        elseif ($country === 'CA' && $current_host_clean === $us_domain_clean) {
            $redirect_to = $this->ca_domain;
            if (WP_DEBUG && WP_DEBUG_LOG) {
                error_log('WC Geo Redirect: CA visitor on US site, redirecting to: ' . $redirect_to);
            }
        }

        // Only redirect if we have a valid target
        if ($redirect_to) {
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
        // Don't redirect to default placeholder domains
        if ($domain === 'yourstore.com' || $domain === 'yourstore.ca' || empty($domain)) {
            if (WP_DEBUG && WP_DEBUG_LOG) {
                error_log('WC Geo Redirect: Skipping redirect - domain not configured: ' . $domain);
            }
            return;
        }

        // Clean up the domain - remove any paths if included
        // This handles cases like "github.com/maikunari" -> "github.com"
        $domain_parts = parse_url('http://' . $domain);
        $clean_domain = $domain_parts['host'] ?? $domain;

        // For domains with paths (like github.com/maikunari), keep the full path
        $domain_path = '';
        if (strpos($domain, '/') !== false) {
            $parts = explode('/', $domain, 2);
            $clean_domain = $parts[0];
            $domain_path = '/' . $parts[1];
        }

        // Build redirect URL
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Parse the URL to properly add geo_redirected parameter
        $parsed_url = parse_url($request_uri);
        $path = $domain_path . ($parsed_url['path'] ?? '/');
        $query = $parsed_url['query'] ?? '';

        // Add geo_redirected parameter to prevent loops
        if (!empty($query)) {
            $query .= '&geo_redirected=1';
        } else {
            $query = 'geo_redirected=1';
        }

        // Build the complete redirect URL
        $redirect_url = 'https://' . $clean_domain . $path;
        if (!empty($query)) {
            $redirect_url .= '?' . $query;
        }

        // Log redirect for debugging
        if (WP_DEBUG && WP_DEBUG_LOG) {
            error_log('WC Geo Redirect: Redirecting from ' . $_SERVER['HTTP_HOST'] . $request_uri . ' to ' . $redirect_url);
        }

        // Use wp_redirect for cross-domain redirects (not wp_safe_redirect which blocks external domains)
        wp_redirect($redirect_url, 302);
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
     * Exclude AJAX from Breeze cache
     *
     * @since 1.0.0
     * @param array $urls
     * @return array
     */
    public function breeze_exclude_ajax($urls) {
        $urls[] = 'wp-admin/admin-ajax.php';
        $urls[] = 'action=wc_geo_check_location';
        return $urls;
    }

    /**
     * Set cache headers for AJAX requests
     *
     * @since 1.0.0
     */
    public function set_cache_headers() {
        if (wp_doing_ajax() && isset($_POST['action']) && $_POST['action'] === 'wc_geo_check_location') {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }

    /**
     * Get client IP address
     *
     * @since 1.0.0
     * @return string
     */
    private function get_client_ip() {
        // Check for CloudFlare
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        // Check for proxy headers
        $headers = array(
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
        );

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }

        // Default to REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
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

        // Also check POST for AJAX requests
        if (WP_DEBUG && isset($_POST['test_country'])) {
            return sanitize_text_field($_POST['test_country']);
        }

        // Check transient cache for IP-based country
        $ip_address = $this->get_client_ip();
        $cache_key = 'wc_geo_country_' . md5($ip_address);
        $cached_country = get_transient($cache_key);

        if ($cached_country !== false) {
            return $cached_country;
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
                $country = sanitize_text_field($_SERVER[$header]);
                // Cache for 1 hour
                set_transient($cache_key, $country, HOUR_IN_SECONDS);
                return $country;
            }
        }

        // Method 2: WooCommerce geolocation cookie
        if (isset($_COOKIE['woocommerce_geo_country'])) {
            $country = sanitize_text_field($_COOKIE['woocommerce_geo_country']);
            // Cache for 1 hour
            set_transient($cache_key, $country, HOUR_IN_SECONDS);
            return $country;
        }

        // Method 3: WooCommerce Geolocation API
        if (class_exists('WC_Geolocation')) {
            $geo = WC_Geolocation::geolocate_ip();
            if (!empty($geo['country'])) {
                $country = $geo['country'];
                // Cache for 1 hour
                set_transient($cache_key, $country, HOUR_IN_SECONDS);
                return $country;
            }
        }

        // Cache empty result for 10 minutes to avoid repeated lookups
        set_transient($cache_key, '', 10 * MINUTE_IN_SECONDS);
        return null;
    }
    
    /**
     * AJAX handler for checking user location
     *
     * @since 1.0.0
     */
    public function ajax_check_location() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_geo_redirect_ajax')) {
            wp_die('Security check failed');
        }

        // Check if plugin is enabled
        if (!get_option('wc_geo_redirect_enabled', true)) {
            wp_send_json(array(
                'shouldSuggest' => false,
                'reason' => 'plugin_disabled'
            ));
        }

        // Check for test mode first
        $test_popup = isset($_POST['test_popup']) && $_POST['test_popup'] === '1';
        $test_country = isset($_POST['test_country']) ? sanitize_text_field($_POST['test_country']) : '';

        // Skip cookie check if in test mode
        if (!$test_popup && !$test_country && isset($_COOKIE[$this->cookie_name])) {
            wp_send_json(array(
                'shouldSuggest' => false,
                'reason' => 'manual_choice'
            ));
        }

        // Skip for bots (but not in test mode)
        if (!$test_popup && !$test_country && $this->is_bot()) {
            wp_send_json(array(
                'shouldSuggest' => false,
                'reason' => 'bot_detected'
            ));
        }

        // Get visitor country
        $country = $this->get_visitor_country();

        // Override with test country if provided
        if (!empty($test_country) && in_array($test_country, array('US', 'CA'))) {
            $country = $test_country;
            $test_popup = true; // Enable test mode when test_country is used
        }

        // Allow testing without country detection
        if (!$test_popup && empty($country)) {
            wp_send_json(array(
                'shouldSuggest' => false,
                'reason' => 'no_country_detected'
            ));
        }

        // Use US as default for testing only if no country detected
        if ($test_popup && empty($country)) {
            $country = 'US';
        }

        // Get current host
        $current_host = sanitize_text_field($_SERVER['HTTP_HOST'] ?? '');
        $current_host_clean = str_replace('www.', '', $current_host);

        // Extract domain parts from configured domains
        $ca_domain_parts = explode('/', str_replace('www.', '', $this->ca_domain));
        $ca_domain_clean = $ca_domain_parts[0];

        $us_domain_parts = explode('/', str_replace('www.', '', $this->us_domain));
        $us_domain_clean = $us_domain_parts[0];

        // Check if redirect is needed
        $shouldSuggest = false;
        $redirectUrl = '';
        $message = '';
        $storeName = '';

        // For testing, always show popup if test_popup is true
        if ($test_popup) {
            // Determine which popup to show based on country
            if ($country === 'US') {
                $shouldSuggest = true;
                $redirectUrl = 'https://' . $this->us_domain;
                $message = get_option('wc_geo_redirect_message_us', __('It looks like you\'re visiting from the United States. Would you like to switch to our US store for local currency and shipping?', 'wc-geo-redirect'));
                $storeName = __('US Store', 'wc-geo-redirect');
            } elseif ($country === 'CA') {
                $shouldSuggest = true;
                $redirectUrl = 'https://' . $this->ca_domain;
                $message = get_option('wc_geo_redirect_message_ca', __('It looks like you\'re visiting from Canada. Would you like to switch to our Canadian store for local currency and shipping?', 'wc-geo-redirect'));
                $storeName = __('Canadian Store', 'wc-geo-redirect');
            }
        } else {
            // Normal operation - check if redirect is needed
            if ($country === 'US' && $current_host_clean === $ca_domain_clean) {
                $shouldSuggest = true;
                $redirectUrl = 'https://' . $this->us_domain;
                $message = get_option('wc_geo_redirect_message_us', __('It looks like you\'re visiting from the United States. Would you like to switch to our US store for local currency and shipping?', 'wc-geo-redirect'));
                $storeName = __('US Store', 'wc-geo-redirect');
            } elseif ($country === 'CA' && $current_host_clean === $us_domain_clean) {
                $shouldSuggest = true;
                $redirectUrl = 'https://' . $this->ca_domain;
                $message = get_option('wc_geo_redirect_message_ca', __('It looks like you\'re visiting from Canada. Would you like to switch to our Canadian store for local currency and shipping?', 'wc-geo-redirect'));
                $storeName = __('Canadian Store', 'wc-geo-redirect');
            }
        }

        $response = array(
            'shouldSuggest' => $shouldSuggest,
            'redirectUrl' => $redirectUrl,
            'message' => $message,
            'country' => $country,
            'storeName' => $storeName
        );

        // Add debug info if in test mode
        if ($test_popup || WP_DEBUG) {
            $response['debug'] = array(
                'test_popup' => $test_popup,
                'test_country' => $test_country,
                'detected_country' => $country,
                'current_host' => $current_host,
                'ca_domain' => $this->ca_domain,
                'us_domain' => $this->us_domain,
                'current_host_clean' => $current_host_clean,
                'ca_domain_clean' => $ca_domain_clean,
                'us_domain_clean' => $us_domain_clean
            );
        }

        wp_send_json($response);
    }

    /**
     * Enqueue scripts for popup mode
     *
     * @since 1.0.0
     */
    public function enqueue_scripts() {
        // Check if plugin is enabled
        if (!get_option('wc_geo_redirect_enabled', true)) {
            return;
        }

        // Only enqueue if popup mode is enabled
        $mode = get_option('wc_geo_redirect_mode', 'popup');
        if ($mode !== 'popup') {
            return;
        }

        // Don't enqueue on admin pages
        if (is_admin()) {
            return;
        }

        // Register and enqueue the CSS
        wp_enqueue_style(
            'wc-geo-popup',
            WC_GEO_REDIRECT_PLUGIN_URL . 'assets/css/geo-popup.css',
            array(),
            WC_GEO_REDIRECT_VERSION
        );

        // Register and enqueue the script
        wp_enqueue_script(
            'wc-geo-popup',
            WC_GEO_REDIRECT_PLUGIN_URL . 'assets/js/geo-popup.js',
            array('jquery'),
            WC_GEO_REDIRECT_VERSION,
            true
        );

        // Localize script with necessary data
        wp_localize_script('wc-geo-popup', 'wc_geo_redirect', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_geo_redirect_ajax'),
            'cookie_name' => $this->cookie_name,
            'cookie_days' => $this->cookie_duration,
            'popup_delay' => get_option('wc_geo_redirect_popup_delay', 2) * 1000, // Convert to milliseconds
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG
        ));
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
                'sanitize_callback' => array($this, 'sanitize_ca_domain'),
                'default' => 'yourstore.ca'
            )
        );

        register_setting(
            'wc_geo_redirect_settings',
            'wc_geo_redirect_us_domain',
            array(
                'type' => 'string',
                'sanitize_callback' => array($this, 'sanitize_us_domain'),
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

        register_setting(
            'wc_geo_redirect_settings',
            'wc_geo_redirect_mode',
            array(
                'type' => 'string',
                'sanitize_callback' => array($this, 'sanitize_mode'),
                'default' => 'popup'
            )
        );

        register_setting(
            'wc_geo_redirect_settings',
            'wc_geo_redirect_popup_delay',
            array(
                'type' => 'number',
                'sanitize_callback' => 'absint',
                'default' => 2
            )
        );

        register_setting(
            'wc_geo_redirect_settings',
            'wc_geo_redirect_message_us',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default' => __('It looks like you\'re visiting from the United States. Would you like to switch to our US store for local currency and shipping?', 'wc-geo-redirect')
            )
        );

        register_setting(
            'wc_geo_redirect_settings',
            'wc_geo_redirect_message_ca',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default' => __('It looks like you\'re visiting from Canada. Would you like to switch to our Canadian store for local currency and shipping?', 'wc-geo-redirect')
            )
        );
    }

    /**
     * Sanitize Canadian domain input
     *
     * @since 1.0.0
     * @param string $domain
     * @return string
     */
    public function sanitize_ca_domain($domain) {
        // Remove https://, http://, and trailing slashes
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        $domain = sanitize_text_field($domain);

        return $domain;
    }

    /**
     * Sanitize US domain input
     *
     * @since 1.0.0
     * @param string $domain
     * @return string
     */
    public function sanitize_us_domain($domain) {
        // Remove https://, http://, and trailing slashes
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        $domain = sanitize_text_field($domain);

        // Check if both domains are the same after both are submitted
        if (isset($_POST['wc_geo_redirect_ca_domain'])) {
            $ca_domain = sanitize_text_field($_POST['wc_geo_redirect_ca_domain']);
            $ca_domain = preg_replace('#^https?://#', '', $ca_domain);
            $ca_domain = rtrim($ca_domain, '/');

            // Extract base domains for comparison
            $ca_base = explode('/', $ca_domain)[0];
            $us_base = explode('/', $domain)[0];

            if ($ca_base === $us_base && $ca_domain === $domain) {
                add_settings_error(
                    'wc_geo_redirect_messages',
                    'wc_geo_redirect_same_domain',
                    __('Warning: Canadian and US domains are the same. This may cause redirect loops.', 'wc-geo-redirect'),
                    'warning'
                );
            }
        }

        return $domain;
    }

    /**
     * Sanitize redirect mode
     *
     * @since 1.0.0
     * @param string $mode
     * @return string
     */
    public function sanitize_mode($mode) {
        return in_array($mode, array('popup', 'redirect')) ? $mode : 'popup';
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
                            <?php esc_html_e('Redirect Mode', 'wc-geo-redirect'); ?>
                        </th>
                        <td>
                            <?php $mode = get_option('wc_geo_redirect_mode', 'popup'); ?>
                            <fieldset>
                                <label>
                                    <input type="radio" name="wc_geo_redirect_mode" value="popup" <?php checked($mode, 'popup'); ?> />
                                    <span><?php esc_html_e('Popup Mode', 'wc-geo-redirect'); ?></span>
                                </label>
                                <p class="description" style="margin-left: 24px; margin-top: 5px;">
                                    <?php esc_html_e('Shows a suggestion popup to visitors (works with page caching)', 'wc-geo-redirect'); ?>
                                </p>
                                <br>
                                <label>
                                    <input type="radio" name="wc_geo_redirect_mode" value="redirect" <?php checked($mode, 'redirect'); ?> />
                                    <span><?php esc_html_e('Redirect Mode', 'wc-geo-redirect'); ?></span>
                                </label>
                                <p class="description" style="margin-left: 24px; margin-top: 5px;">
                                    <?php esc_html_e('Automatically redirects visitors (may not work with page caching)', 'wc-geo-redirect'); ?>
                                </p>
                            </fieldset>
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

                <h2><?php esc_html_e('Popup Settings', 'wc-geo-redirect'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wc_geo_redirect_popup_delay"><?php esc_html_e('Popup Delay', 'wc-geo-redirect'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="wc_geo_redirect_popup_delay" name="wc_geo_redirect_popup_delay" value="<?php echo esc_attr(get_option('wc_geo_redirect_popup_delay', 2)); ?>" min="0" max="30" class="small-text" />
                            <span><?php esc_html_e('seconds', 'wc-geo-redirect'); ?></span>
                            <p class="description"><?php esc_html_e('How long to wait before showing the popup (0-30 seconds)', 'wc-geo-redirect'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wc_geo_redirect_message_us"><?php esc_html_e('US Visitor Message', 'wc-geo-redirect'); ?></label>
                        </th>
                        <td>
                            <textarea id="wc_geo_redirect_message_us" name="wc_geo_redirect_message_us" rows="3" class="large-text"><?php echo esc_textarea(get_option('wc_geo_redirect_message_us', __('It looks like you\'re visiting from the United States. Would you like to switch to our US store for local currency and shipping?', 'wc-geo-redirect'))); ?></textarea>
                            <p class="description"><?php esc_html_e('Message shown to US visitors on the Canadian site', 'wc-geo-redirect'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wc_geo_redirect_message_ca"><?php esc_html_e('Canadian Visitor Message', 'wc-geo-redirect'); ?></label>
                        </th>
                        <td>
                            <textarea id="wc_geo_redirect_message_ca" name="wc_geo_redirect_message_ca" rows="3" class="large-text"><?php echo esc_textarea(get_option('wc_geo_redirect_message_ca', __('It looks like you\'re visiting from Canada. Would you like to switch to our Canadian store for local currency and shipping?', 'wc-geo-redirect'))); ?></textarea>
                            <p class="description"><?php esc_html_e('Message shown to Canadian visitors on the US site', 'wc-geo-redirect'); ?></p>
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

                <h3><?php esc_html_e('Current Configuration', 'wc-geo-redirect'); ?></h3>
                <table class="widefat">
                    <tr>
                        <td><strong><?php esc_html_e('Plugin Status:', 'wc-geo-redirect'); ?></strong></td>
                        <td><?php echo get_option('wc_geo_redirect_enabled', true) ? '<span style="color: green;">✓ Enabled</span>' : '<span style="color: red;">✗ Disabled</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Mode:', 'wc-geo-redirect'); ?></strong></td>
                        <td><?php
                            $mode = get_option('wc_geo_redirect_mode', 'popup');
                            echo $mode === 'popup' ? esc_html__('Popup Mode', 'wc-geo-redirect') : esc_html__('Redirect Mode', 'wc-geo-redirect');
                        ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Canadian Domain:', 'wc-geo-redirect'); ?></strong></td>
                        <td><?php echo esc_html($this->ca_domain); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('US Domain:', 'wc-geo-redirect'); ?></strong></td>
                        <td><?php echo esc_html($this->us_domain); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Current Site:', 'wc-geo-redirect'); ?></strong></td>
                        <td><?php echo esc_html($_SERVER['HTTP_HOST']); ?></td>
                    </tr>
                </table>

                <?php if (WP_DEBUG): ?>
                <h3><?php esc_html_e('Debug Mode', 'wc-geo-redirect'); ?></h3>
                <p><?php esc_html_e('Debug mode is enabled. You can test the plugin using:', 'wc-geo-redirect'); ?></p>

                <?php $mode = get_option('wc_geo_redirect_mode', 'popup'); ?>
                <?php if ($mode === 'redirect'): ?>
                    <h4><?php esc_html_e('Redirect Mode Testing:', 'wc-geo-redirect'); ?></h4>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><code><?php echo esc_html(home_url('/?test_country=US')); ?></code> - <?php esc_html_e('Test as US visitor', 'wc-geo-redirect'); ?></li>
                        <li><code><?php echo esc_html(home_url('/?test_country=CA')); ?></code> - <?php esc_html_e('Test as Canadian visitor', 'wc-geo-redirect'); ?></li>
                    </ul>
                <?php else: ?>
                    <h4><?php esc_html_e('Popup Mode Testing:', 'wc-geo-redirect'); ?></h4>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><code><?php echo esc_html(home_url('/?test_popup=1')); ?></code> - <?php esc_html_e('Force show popup (ignores cookies)', 'wc-geo-redirect'); ?></li>
                        <li><code><?php echo esc_html(home_url('/?test_country=US')); ?></code> - <?php esc_html_e('Test as US visitor', 'wc-geo-redirect'); ?></li>
                        <li><code><?php echo esc_html(home_url('/?test_country=CA')); ?></code> - <?php esc_html_e('Test as Canadian visitor', 'wc-geo-redirect'); ?></li>
                    </ul>
                    <p><?php esc_html_e('Open browser console to see debug logs.', 'wc-geo-redirect'); ?></p>
                <?php endif; ?>
                <?php endif; ?>
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