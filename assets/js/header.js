/**
 * Alumnus Header - Mobile Hamburger Menu
 * Handles toggle functionality for the mobile sidebar menu
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        const $hamburger = $('.ahb-hamburger');
        const $sidebar = $('.ahb-sidebar');
        const $overlay = $('.ahb-sidebar-overlay');
        const $closeBtn = $('.ahb-sidebar-close');

        // Toggle sidebar when hamburger is clicked
        $hamburger.on('click', function() {
            $sidebar.toggleClass('active');
            $overlay.toggleClass('active');
        });

        // Close sidebar when close button is clicked
        $closeBtn.on('click', function() {
            $sidebar.removeClass('active');
            $overlay.removeClass('active');
        });

        // Close sidebar when overlay is clicked
        $overlay.on('click', function() {
            $sidebar.removeClass('active');
            $overlay.removeClass('active');
        });

        // Close sidebar when a menu item is clicked (optional, for better UX)
        $('.ahb-sidebar-item').on('click', function() {
            $sidebar.removeClass('active');
            $overlay.removeClass('active');
        });
    });

})(jQuery);

