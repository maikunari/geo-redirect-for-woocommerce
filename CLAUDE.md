# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress/WooCommerce plugin called "Geo-Redirect for WooCommerce " that automatically redirects visitors between regional WooCommerce stores (.ca and .com) based on their geographic location.

## Common Development Commands

### WordPress Plugin Development
- **Test Plugin**: Install on a local WordPress instance with WooCommerce activated
- **Check PHP Syntax**: `php -l geo-redirect-for-woocommerce.php`
- **WordPress Coding Standards**: Use PHPCS with WordPress coding standards if installed

### Plugin Installation
1. Copy plugin folder to `/wp-content/plugins/`
2. Activate via WordPress admin panel
3. Configure at WooCommerce → Geo-Redirect

## Code Architecture

### Main Plugin Structure
The plugin follows WordPress plugin architecture with a single main file `geo-redirect-for-woocommerce.php` containing:

- **WC_Geo_Redirect Class**: Singleton pattern implementation handling all plugin functionality
  - `maybe_redirect()` (line 192): Core redirect logic that checks visitor location and performs redirects
  - `get_visitor_country()` (line 321): Multi-method country detection (CDN headers, WooCommerce geolocation, cookies)
  - `render_store_switcher()` (line 390): Frontend UI for manual store switching
  - Admin settings management via WordPress Settings API

### Key Implementation Details

**Redirect Flow**:
1. Visitor arrives → Plugin hooks into `template_redirect` action
2. Country detection via multiple fallback methods (CloudFlare headers → WooCommerce cookies → WC_Geolocation API)
3. Domain matching logic checks if visitor is on correct regional store
4. 302 redirect preserves URL path when switching domains
5. Cookie (`wc_geo_store_choice`) remembers manual store selection for 30 days

**Bot Protection**: Search engines excluded from redirects via user agent detection

**Configuration Storage**:
- Plugin options stored in WordPress database
- Can override via `wp-config.php` constants: `WC_GEO_CA_DOMAIN`, `WC_GEO_US_DOMAIN`

### Critical Functions

- `is_bot()` (line 363): Prevents search engine redirect loops
- `perform_redirect()` (line 273): Validates and executes safe redirects with loop prevention
- `check_dependencies()` (line 140): Ensures WooCommerce is active and geolocation enabled

## Plugin Hooks and Filters

Available for customization:
- `wc_geo_redirect_should_redirect` - Control redirect logic
- `wc_geo_redirect_target_domain` - Modify target domain
- `wc_geo_redirect_after_redirect` - Post-redirect actions

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- WooCommerce geolocation must be enabled
- SSL certificates required on both domains

## Important Notes

- HPOS (High Performance Order Storage) compatible
- Uses WordPress nonces for security
- Follows WordPress coding standards with proper sanitization and escaping