/**
 * Public registration form behaviour: toggles conditional fields (payment
 * method sub-fields) and live-recalculates the displayed total.
 *
 * Reads fee configuration from the ReunionRegConfig object, which PHP
 * injects via wp_localize_script() (see class-frontend-form.php) — never
 * hardcode fee numbers here, they must always come from Payment & Fee
 * Settings so the client can change them without touching code.
 */
(function () {
    function syncConditionalFields() {
        document.querySelectorAll( '.reunion-conditional' ).forEach( function ( el ) {
            var depField = el.getAttribute( 'data-depends-field' );
            var depValue = el.getAttribute( 'data-depends-value' );
            var checkedInput = document.querySelector( 'input[name="' + depField + '"]:checked' );
            var matches = !! ( checkedInput && checkedInput.value === depValue );
            el.style.display = matches ? '' : 'none';

            // Toggle native 'required' live so a hidden field is never both
            // required AND not-focusable — that combination silently blocks
            // HTML5 form submission with no visible error to the visitor.
            var input = el.querySelector( '[data-conditional-required="1"]' );
            if ( input ) {
                input.required = matches;
            }
        } );
    }

    function calcTotal() {
        if ( typeof window.ReunionRegConfig === 'undefined' ) {
            return;
        }

        var fee      = parseFloat( window.ReunionRegConfig.registrationFee ) || 0;
        var guestFee = parseFloat( window.ReunionRegConfig.guestFee ) || 0;
        var guestEl  = document.getElementById( 'reunion_guest_count' );
        var donEl    = document.getElementById( 'reunion_donation' );
        var out      = document.getElementById( 'reunion_total_number' );

        if ( ! out ) {
            return;
        }

        var guests   = guestEl ? Math.max( 0, Math.min( 5, parseFloat( guestEl.value ) || 0 ) ) : 0;
        var donation = donEl ? Math.max( 0, parseFloat( donEl.value ) || 0 ) : 0;
        var total    = fee + ( guests * guestFee ) + donation;

        out.textContent = total.toLocaleString( 'en-US' );
    }

    document.addEventListener( 'DOMContentLoaded', function () {
        syncConditionalFields();
        calcTotal();

        document.querySelectorAll( 'input[name="payment_channel"]' ).forEach( function ( radio ) {
            radio.addEventListener( 'change', syncConditionalFields );
        } );

        [ 'reunion_guest_count', 'reunion_donation' ].forEach( function ( id ) {
            var el = document.getElementById( id );
            if ( el ) {
                el.addEventListener( 'input', calcTotal );
            }
        } );
    } );
})();
