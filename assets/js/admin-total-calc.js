/**
 * Auto-fills a "Total Amount" number field from guest-count + donation +
 * the configured registration/guest fees, on either admin entry screen
 * (manual add, or edit). Admin can still type over the result afterward —
 * this only saves them doing the arithmetic by hand.
 *
 * One script, reused by both meta boxes: each marker element
 * (.reunion-admin-total-calc) carries its own field IDs and fee numbers
 * as data-* attributes, so this file needs no per-screen configuration
 * and there's no risk of two localized-script payloads clobbering each
 * other (see class-admin-entry-metabox.php for why that matters).
 */
(function () {
    function initCalculator( el ) {
        var guestEl  = document.getElementById( el.getAttribute( 'data-guest-field' ) );
        var donEl    = document.getElementById( el.getAttribute( 'data-donation-field' ) );
        var totalEl  = document.getElementById( el.getAttribute( 'data-total-field' ) );
        var fee      = parseFloat( el.getAttribute( 'data-fee' ) ) || 0;
        var guestFee = parseFloat( el.getAttribute( 'data-guest-fee' ) ) || 0;

        if ( ! guestEl || ! donEl || ! totalEl ) {
            return;
        }

        function recalc() {
            var guests   = Math.max( 0, parseFloat( guestEl.value ) || 0 );
            var donation = Math.max( 0, parseFloat( donEl.value ) || 0 );
            totalEl.value = fee + ( guests * guestFee ) + donation;
        }

        guestEl.addEventListener( 'input', recalc );
        donEl.addEventListener( 'input', recalc );
    }

    document.addEventListener( 'DOMContentLoaded', function () {
        document.querySelectorAll( '.reunion-admin-total-calc' ).forEach( initCalculator );
    } );
})();
