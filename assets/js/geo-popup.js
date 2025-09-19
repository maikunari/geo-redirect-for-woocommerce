/**
 * WooCommerce Geo-Redirect Popup Script
 *
 * @package WooCommerceGeoRedirect
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Check if we should run
    if (typeof wc_geo_redirect === 'undefined') {
        return;
    }

    /**
     * Check if user has already made a choice
     */
    function getCookie(name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for(var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) === ' ') {
                c = c.substring(1, c.length);
            }
            if (c.indexOf(nameEQ) === 0) {
                return c.substring(nameEQ.length, c.length);
            }
        }
        return null;
    }

    /**
     * Set cookie for user choice
     */
    function setCookie(name, value, days) {
        var expires = "";
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "")  + expires + "; path=/; SameSite=Lax";
    }

    /**
     * Create and show the popup
     */
    function showPopup(response) {
        // Get country flag
        var flag = response.country === 'US' ? '🇺🇸' : '🇨🇦';

        // Get benefits based on country
        var benefits = response.country === 'US' ?
            ['Prices in USD', 'US shipping rates', 'Faster delivery'] :
            ['Prices in CAD', 'Canadian shipping rates', 'Local inventory'];

        // Create benefits HTML
        var benefitsHtml = '';
        benefits.forEach(function(benefit) {
            benefitsHtml += '<li>' + benefit + '</li>';
        });

        // Create overlay for mobile
        var overlayHtml = '';
        if ($(window).width() <= 480) {
            overlayHtml = '<div class="wc-geo-popup-overlay" id="wc-geo-popup-overlay"></div>';
        }

        // Create popup HTML
        var popupHtml = overlayHtml +
            '<div class="wc-geo-popup-container" id="wc-geo-popup">' +
                '<div class="wc-geo-popup-header">' +
                    '<span class="wc-geo-popup-flag">' + flag + '</span>' +
                    '<h3 class="wc-geo-popup-title">Visit ' + response.storeName + '</h3>' +
                    '<button class="wc-geo-popup-close" id="wc-geo-popup-close" aria-label="Close"></button>' +
                '</div>' +
                '<div class="wc-geo-popup-content">' +
                    '<p class="wc-geo-popup-message">' + response.message + '</p>' +
                    '<ul class="wc-geo-popup-benefits">' + benefitsHtml + '</ul>' +
                    '<div class="wc-geo-popup-buttons">' +
                        '<button class="wc-geo-popup-button wc-geo-popup-button-primary" id="wc-geo-popup-accept">' +
                            'Visit ' + response.storeName +
                        '</button>' +
                        '<button class="wc-geo-popup-button wc-geo-popup-button-secondary" id="wc-geo-popup-decline">' +
                            'Stay Here' +
                        '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        // Add popup to body
        $('body').append(popupHtml);

        // Handle accept button
        $('#wc-geo-popup-accept').on('click', function() {
            // Set cookie to remember choice
            setCookie(wc_geo_redirect.cookie_name, 'accepted', wc_geo_redirect.cookie_days);

            // Redirect to the suggested store
            window.location.href = response.redirectUrl;
        });

        // Handle decline button and close button
        $('#wc-geo-popup-decline, #wc-geo-popup-close, #wc-geo-popup-overlay').on('click', function() {
            // Set cookie to remember choice
            setCookie(wc_geo_redirect.cookie_name, 'declined', wc_geo_redirect.cookie_days);

            // Hide popup with animation
            hidePopup();
        });

        // Auto-hide after 30 seconds
        setTimeout(function() {
            if ($('#wc-geo-popup').length) {
                hidePopup();
            }
        }, 30000);
    }

    /**
     * Hide the popup with animation
     */
    function hidePopup() {
        $('#wc-geo-popup').addClass('wc-geo-popup-hiding');
        $('#wc-geo-popup-overlay').addClass('wc-geo-popup-hiding');

        // Remove from DOM after animation
        setTimeout(function() {
            $('#wc-geo-popup').remove();
            $('#wc-geo-popup-overlay').remove();
        }, 300);
    }

    /**
     * Main function to check location and show popup if needed
     */
    function checkLocationAndSuggest() {
        // Check for test mode in URL
        var urlParams = new URLSearchParams(window.location.search);
        var testMode = urlParams.get('test_popup') === '1';
        var testCountry = urlParams.get('test_country');

        // If test_country is present, enable test mode
        if (testCountry) {
            testMode = true;
        }

        // Debug logging
        if (wc_geo_redirect.debug_mode || testMode) {
            console.log('WC Geo Redirect: Starting location check...');
            console.log('Debug mode:', wc_geo_redirect.debug_mode);
            console.log('Test mode:', testMode);
            console.log('Test country:', testCountry);
            console.log('Popup delay:', wc_geo_redirect.popup_delay + 'ms');
        }

        // Check if user has already made a choice (skip in test mode)
        if (!testMode && getCookie(wc_geo_redirect.cookie_name)) {
            if (wc_geo_redirect.debug_mode) {
                console.log('WC Geo Redirect: User has already made a choice, skipping check');
            }
            return;
        }

        // Check if popup already exists
        if ($('#wc-geo-popup').length) {
            return;
        }

        // Make AJAX call to check location
        var ajaxData = {
            action: 'wc_geo_check_location',
            nonce: wc_geo_redirect.nonce,
            test_popup: testMode ? '1' : '0'
        };

        // Add test country if present
        if (testCountry) {
            ajaxData.test_country = testCountry;
        }

        $.ajax({
            url: wc_geo_redirect.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (wc_geo_redirect.debug_mode || testMode) {
                    console.log('WC Geo Redirect Response:', response);
                }

                if (response.shouldSuggest) {
                    showPopup(response);
                } else {
                    if (wc_geo_redirect.debug_mode || testMode) {
                        console.log('No redirect suggestion needed');
                        if (response.reason) {
                            console.log('Reason:', response.reason);
                        }
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('WC Geo Redirect Error:', error);
                if (wc_geo_redirect.debug_mode) {
                    console.error('Full error:', xhr.responseText);
                }
            }
        });
    }

    // Run on document ready
    $(document).ready(function() {
        // Get delay from settings or use default
        var delay = wc_geo_redirect.popup_delay || 2000;

        // Check for immediate test mode
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('test_popup') === '1') {
            delay = 500; // Show faster in test mode
        }

        // Wait specified delay before checking
        setTimeout(checkLocationAndSuggest, delay);
    });

})(jQuery);