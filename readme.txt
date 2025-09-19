# WooCommerce Geo-Redirect for Dual Stores

**Contributors:** maikunari  
**Tags:** woocommerce, geolocation, redirect, multi-store, regional  
**Requires at least:** WordPress 5.8  
**Tested up to:** WordPress 6.4  
**Requires PHP:** 7.4  
**WC requires at least:** 6.0  
**WC tested up to:** 9.0  
**Stable tag:** 1.0.0  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Automatically redirect visitors between your regional WooCommerce stores (.ca and .com) based on their geographic location.

## Description

WooCommerce Geo-Redirect for Dual Stores is a lightweight plugin that automatically directs your customers to the appropriate regional store based on their location. Perfect for businesses operating separate stores for different countries with synchronized product catalogs.

### Key Features

* **Automatic Detection** - Uses WooCommerce's built-in geolocation to detect visitor location
* **Smart Redirects** - Redirects US visitors to .com and Canadian visitors to .ca
* **URL Preservation** - Maintains the exact same URL path when redirecting
* **User Choice Respect** - Remembers when users manually switch stores (30-day cookie)
* **SEO Friendly** - Excludes search engine bots from redirection
* **Lightweight** - Minimal performance impact, leverages WooCommerce's existing geolocation
* **No Popups** - Seamless redirection without annoying popups
* **Manual Override** - Footer link allows users to switch stores manually

### Use Cases

* Running separate .ca and .com WooCommerce stores
* Showing correct currency (CAD vs USD) automatically
* Providing accurate local shipping rates
* Improving customer experience with regional pricing
* Managing inventory across multiple regional stores

## Installation

### Automatic Installation

1. Log in to your WordPress admin panel
2. Navigate to **Plugins → Add New**
3. Search for "WooCommerce Geo-Redirect"
4. Click **Install Now** and then **Activate**
5. Go to **WooCommerce → Geo-Redirect** to configure

### Manual Installation

1. Download the plugin zip file
2. Log in to your WordPress admin panel
3. Navigate to **Plugins → Add New → Upload Plugin**
4. Choose the downloaded zip file
5. Click **Install Now** and then **Activate**
6. Go to **WooCommerce → Geo-Redirect** to configure

### FTP Installation

1. Download and unzip the plugin
2. Upload the `wc-geo-redirect` folder to `/wp-content/plugins/`
3. Activate the plugin through the **Plugins** menu in WordPress
4. Go to **WooCommerce → Geo-Redirect** to configure

## Configuration

### Basic Setup

1. **Enable WooCommerce Geolocation**
   - Navigate to **WooCommerce → Settings → General**
   - Set "Default customer location" to **"Geolocate"** or **"Geolocate (with page caching support)"**
   - This is required for the plugin to work

2. **Configure Domains**
   - Go to **WooCommerce → Geo-Redirect**
   - Enter your Canadian domain (e.g., `yourstore.ca`)
   - Enter your US domain (e.g., `yourstore.com`)
   - Check "Enable Geo-Redirect"
   - Click **Save Settings**

### Advanced Configuration (Optional)

For better performance, you can define your domains in `wp-config.php`:

```php
define('WC_GEO_CA_DOMAIN', 'yourstore.ca');
define('WC_GEO_US_DOMAIN', 'yourstore.com');
```

This avoids database queries on every page load.

## Requirements

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher
* WooCommerce geolocation must be enabled
* Both stores must have synchronized URLs (same paths on both domains)
* SSL certificates on both domains (https)

## How It Works

1. **Visitor arrives** at either store
2. **Plugin checks** visitor's location using WooCommerce geolocation
3. **If mismatched** (e.g., US visitor on .ca site), redirects to appropriate store
4. **URL preserved** - `/product/widget` on .ca redirects to `/product/widget` on .com
5. **Cookie set** if user manually switches stores
6. **Bots excluded** - Search engines can index both sites properly

## Testing

### How to Test Your Setup

1. **Use a VPN Service**
   - Connect to a US server and visit your .ca site
   - You should be redirected to the .com site
   - Connect to a Canadian server and visit your .com site
   - You should be redirected to the .ca site

2. **Clear Cookies**
   - Clear cookies between tests to reset preferences
   - Or use incognito/private browsing mode

3. **Test Manual Switching**
   - Click the store switcher link in the footer
   - Verify you stay on the chosen store for subsequent visits

4. **Verify Bot Exclusion**
   - Use a browser extension to change User-Agent to Googlebot
   - Confirm no redirection occurs

## Frequently Asked Questions

### Does this work with page caching?

Yes! The plugin is compatible with page caching when WooCommerce is set to "Geolocate (with page caching support)" mode.

### Will this affect my SEO?

No. The plugin automatically excludes search engine bots from redirection, allowing them to properly index both sites. The plugin uses 302 (temporary) redirects to avoid SEO issues.

### Can visitors still manually choose their store?

Yes. A store switcher link appears in the footer, and the plugin remembers their choice for 30 days.

### What if my URLs don't match between stores?

This plugin requires synchronized URLs between stores. For example, `/product/blue-widget` must exist on both domains. If your URLs differ, this plugin won't work correctly.

### Does this work with CloudFlare?

Yes! The plugin can use CloudFlare's geo-location headers as a fallback if WooCommerce geolocation is unavailable.

### Can I redirect to more than 2 stores?

Currently, this plugin only supports two stores (.ca and .com). For multiple regions, you would need to modify the plugin code.

### What happens if geolocation fails?

If the plugin cannot determine the visitor's location, no redirection occurs. The visitor remains on their current site.

### Can I exclude certain pages from redirection?

Not in the current version. The plugin redirects all pages except admin, AJAX, and cron requests.

### Does this share cart/user data between stores?

No. This plugin only handles redirection. Each store maintains separate carts, user accounts, and orders.

### Is this GDPR compliant?

Yes. The plugin only uses geolocation data for redirection purposes and doesn't store any personal information beyond a preference cookie.

## Troubleshooting

### Redirects Not Working

1. **Check Geolocation is Enabled**
   - Go to **WooCommerce → Settings → General**
   - Ensure "Default customer location" is set to a geolocation option

2. **Verify Domains are Correct**
   - Check your settings in **WooCommerce → Geo-Redirect**
   - Ensure domains don't include `https://` or trailing slashes

3. **Clear Cookies**
   - The plugin remembers manual choices
   - Clear cookies to test automatic redirection

4. **Check MaxMind Database**
   - WooCommerce geolocation requires MaxMind GeoIP database
   - Go to **WooCommerce → Settings → Integration**
   - Ensure MaxMind license key is configured

### Redirect Loops

If you experience redirect loops:
1. Clear all cookies
2. Ensure only ONE instance of the plugin is active
3. Check that domains are configured correctly
4. Verify no other redirect plugins are conflicting

### Bots Being Redirected

If search engines are being redirected:
1. Check your server isn't modifying User-Agent headers
2. Ensure you're running the latest version of the plugin
3. Contact support with your User-Agent string

## Support

For support, please visit [your-support-url] or email support@yourcompany.com

## Privacy Policy

This plugin:
* Uses WooCommerce's built-in geolocation features
* Sets one cookie to remember user store preference
* Does not store any personal data in the database
* Does not send data to external services

## Changelog

### 1.0.0 - 2024-01-15
* Initial release
* Automatic geo-redirection
* Manual store switcher
* Bot exclusion
* Cookie-based preference memory
* Admin settings page
* WooCommerce HPOS compatibility

## Upgrade Notice

### 1.0.0
Initial release of WooCommerce Geo-Redirect for Dual Stores.

## Developer Information

### Hooks and Filters

The plugin provides several hooks for developers:

```php
// Modify redirect behavior
add_filter('wc_geo_redirect_should_redirect', function($should_redirect, $country, $current_domain) {
    // Your custom logic
    return $should_redirect;
}, 10, 3);

// Modify target domain
add_filter('wc_geo_redirect_target_domain', function($domain, $country) {
    // Your custom logic
    return $domain;
}, 10, 2);

// After redirect occurs
do_action('wc_geo_redirect_after_redirect', $target_domain, $country);
```

### Constants

You can define these constants in `wp-config.php`:

* `WC_GEO_CA_DOMAIN` - Canadian domain
* `WC_GEO_US_DOMAIN` - US domain
* `WC_GEO_REDIRECT_COOKIE_DAYS` - Cookie duration (default: 30)

### Contributing

We welcome contributions! Please visit our GitHub repository at [your-github-url].

## Credits

Developed by maikunari  
Special thanks to the WooCommerce team for their geolocation functionality.

## License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```