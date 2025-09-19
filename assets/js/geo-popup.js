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
     * Main function to check location and show popup if needed
     */
    function checkLocationAndSuggest() {
        // Check if user has already made a choice
        if (getCookie(wc_geo_redirect.cookie_name)) {
            console.log('WC Geo Redirect: User has already made a choice, skipping check');
            return;
        }

        // Make AJAX call to check location
        $.ajax({
            url: wc_geo_redirect.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_geo_check_location',
                nonce: wc_geo_redirect.nonce
            },
            success: function(response) {
                console.log('WC Geo Redirect Response:', response);

                if (response.shouldSuggest) {
                    console.log('Should suggest redirect to:', response.redirectUrl);
                    console.log('Message:', response.message);
                    console.log('Detected country:', response.country);
                    console.log('Store name:', response.storeName);

                    // TODO: Show popup UI here (will be implemented in next iteration)
                    // For now, just log the information

                    // Example of how to set the cookie when user makes a choice:
                    // setCookie(wc_geo_redirect.cookie_name, 'manual', wc_geo_redirect.cookie_days);
                } else {
                    console.log('No redirect suggestion needed');
                    if (response.reason) {
                        console.log('Reason:', response.reason);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('WC Geo Redirect Error:', error);
            }
        });
    }

    // Run on document ready
    $(document).ready(function() {
        // Wait a small delay to ensure page is fully loaded
        setTimeout(checkLocationAndSuggest, 1000);
    });

})(jQuery);