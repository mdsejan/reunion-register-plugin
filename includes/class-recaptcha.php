<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Google reCAPTCHA v3 integration.
 *
 * Owns two responsibilities:
 *  1. The "reCAPTCHA Settings" admin sub-page (enable toggle + Site/Secret
 *     keys + minimum score) under the Reunion Registrations menu, saved
 *     through the WordPress Settings API (options.php) so the nonce is
 *     verified with check_admin_referer() automatically.
 *  2. The frontend + backend verification helpers other modules call —
 *     is_enabled(), is_registration_page(), and verify_token() — so the form
 *     renderer and the submission handler never have to know how reCAPTCHA
 *     is configured, only whether it is on and whether a token is valid.
 *
 * The API script is registered only on the registration path so it never
 * loads anywhere else on the site.
 */
class Reunion_Reg_Recaptcha {

    /** Handle for the Google reCAPTCHA v3 API script. */
    const SCRIPT_HANDLE = 'reunion-reg-recaptcha';

    /** Google's token verification endpoint for reCAPTCHA v3. */
    const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_recaptcha_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_recaptcha_settings' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
    }

    // -----------------------------------------------------------------
    // Admin settings page
    // -----------------------------------------------------------------

    public function add_recaptcha_settings_page() {
        add_submenu_page(
            'edit.php?post_type=' . REUNION_REG_CPT_SLUG,
            'reCAPTCHA Settings',
            'reCAPTCHA Settings',
            'manage_options',
            'reunion_reg_recaptcha_settings',
            array( $this, 'render_recaptcha_settings_page' )
        );
    }

    public function register_recaptcha_settings() {
        register_setting( 'reunion_reg_recaptcha_settings_group', REUNION_REG_RECAPTCHA_SETTINGS_OPTION, array(
            'sanitize_callback' => array( $this, 'sanitize_recaptcha_settings' ),
        ) );
    }

    public function sanitize_recaptcha_settings( $input ) {
        $score = isset( $input['min_score'] ) ? (float) $input['min_score'] : 0.5;

        return array(
            'enabled'    => ! empty( $input['enabled'] ) ? 1 : 0,
            'site_key'   => isset( $input['site_key'] ) ? sanitize_text_field( trim( $input['site_key'] ) ) : '',
            'secret_key' => isset( $input['secret_key'] ) ? sanitize_text_field( trim( $input['secret_key'] ) ) : '',
            'min_score'  => max( 0.1, min( 1.0, $score ) ),
        );
    }

    public function render_recaptcha_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        $settings = self::get_settings();
        $option   = REUNION_REG_RECAPTCHA_SETTINGS_OPTION;
        $active   = self::is_enabled();
        ?>
        <div class="wrap">
            <h1>Google reCAPTCHA v3 Settings</h1>
            <p>Protects the registration form on <code>/register/</code>. When enabled, the reCAPTCHA script is loaded <strong>only</strong> on the registration path, a token is generated on submit, and the score is verified server-side before the entry is saved.</p>

            <form method="POST" action="options.php">
                <?php // Emits the nonce field that options.php verifies with check_admin_referer(). ?>
                <?php settings_fields( 'reunion_reg_recaptcha_settings_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="recaptcha_enabled">Enable Google reCAPTCHA v3</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="recaptcha_enabled" name="<?php echo esc_attr( $option ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                                Enable reCAPTCHA v3 on the registration form
                            </label>
                            <p class="description">Off by default. When off, the script never loads and no verification runs.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="recaptcha_site_key">Site Key</label></th>
                        <td>
                            <input type="text" id="recaptcha_site_key" name="<?php echo esc_attr( $option ); ?>[site_key]" value="<?php echo esc_attr( $settings['site_key'] ); ?>" class="regular-text" autocomplete="off">
                            <p class="description">reCAPTCHA v3 Site Key from your Google reCAPTCHA admin console.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="recaptcha_secret_key">Secret Key</label></th>
                        <td>
                            <input type="password" id="recaptcha_secret_key" name="<?php echo esc_attr( $option ); ?>[secret_key]" value="<?php echo esc_attr( $settings['secret_key'] ); ?>" class="regular-text" autocomplete="new-password">
                            <p class="description">reCAPTCHA v3 Secret Key. Never exposed to the frontend.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="recaptcha_min_score">Minimum Score</label></th>
                        <td>
                            <input type="number" step="0.1" min="0.1" max="1.0" id="recaptcha_min_score" name="<?php echo esc_attr( $option ); ?>[min_score]" value="<?php echo esc_attr( $settings['min_score'] ); ?>" class="small-text">
                            <p class="description">Submissions scoring below this value (range 0.1 – 1.0, default 0.5) are rejected as likely bots.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save reCAPTCHA Settings' ); ?>
            </form>

            <p style="color:#666;">
                <strong>Status:</strong>
                <?php if ( $active ) : ?>
                    reCAPTCHA is <strong>active</strong> — the script loads only on the <code>/register/</code> page and the score is verified on submission.
                <?php elseif ( ! empty( $settings['enabled'] ) ) : ?>
                    Enabled, but <strong>incomplete</strong> — add both the Site Key and Secret Key to activate verification.
                <?php else : ?>
                    reCAPTCHA is <strong>disabled</strong> — the registration form runs without it.
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    // -----------------------------------------------------------------
    // Settings access + frontend/backend helpers
    // -----------------------------------------------------------------

    /**
     * Normalized settings (enabled / site_key / secret_key / min_score),
     * always the same shape regardless of what is stored in the option.
     */
    public static function get_settings() {
        $defaults = array(
            'enabled'    => 0,
            'site_key'   => '',
            'secret_key' => '',
            'min_score'  => 0.5,
        );

        $settings = wp_parse_args( get_option( REUNION_REG_RECAPTCHA_SETTINGS_OPTION, array() ), $defaults );

        $settings['enabled']   = ! empty( $settings['enabled'] ) ? 1 : 0;
        $settings['site_key']   = trim( (string) $settings['site_key'] );
        $settings['secret_key'] = trim( (string) $settings['secret_key'] );
        $settings['min_score']  = max( 0.1, min( 1.0, (float) $settings['min_score'] ) );

        return $settings;
    }

    /**
     * True only when the toggle is on AND both keys are saved — otherwise the
     * whole feature (script + verification) is bypassed.
     */
    public static function is_enabled() {
        $settings = self::get_settings();

        return ! empty( $settings['enabled'] ) && '' !== $settings['site_key'] && '' !== $settings['secret_key'];
    }

    /**
     * Whether the current request is the registration page. Matches the
     * 'register' page slug, a page containing the registration shortcode, or
     * a request URI path ending in /register/.
     */
    public static function is_registration_page() {
        if ( function_exists( 'is_page' ) && is_page( 'register' ) ) {
            return true;
        }

        if ( function_exists( 'is_singular' ) && is_singular() ) {
            $post = function_exists( 'get_post' ) ? get_post() : null;
            if ( $post && function_exists( 'has_shortcode' ) && has_shortcode( $post->post_content, 'reunion_registration_form' ) ) {
                return true;
            }
        }

        $path = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        }

        return (bool) preg_match( '#/register/?$#', $path );
    }

    /**
     * The frontend API URL for the configured Site Key.
     */
    public static function get_api_url() {
        $settings = self::get_settings();

        return 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $settings['site_key'] );
    }

    /**
     * Registers (but does not enqueue) the reCAPTCHA v3 API script, only on
     * the registration path and only when the feature is fully configured.
     */
    public function register_assets() {
        if ( ! self::is_enabled() || ! self::is_registration_page() ) {
            return;
        }

        wp_register_script( self::SCRIPT_HANDLE, self::get_api_url(), array(), null, true );
    }

    /**
     * Verifies a reCAPTCHA v3 token against Google's siteverify endpoint.
     *
     * Returns true only when Google reports success AND the score meets the
     * saved minimum — any transport error, malformed response, or low score
     * is treated as a failure.
     *
     * @param string $token The token returned by grecaptcha.execute() on the client.
     * @return bool
     */
    public static function verify_token( $token ) {
        $token = trim( (string) $token );
        if ( '' === $token ) {
            return false;
        }

        $settings = self::get_settings();
        if ( '' === $settings['secret_key'] ) {
            return false;
        }

        $response = wp_remote_post( self::VERIFY_URL, array(
            'timeout' => 10,
            'body'    => array(
                'secret'   => $settings['secret_key'],
                'response' => $token,
                'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['success'] ) ) {
            return false;
        }

        $score = isset( $body['score'] ) ? (float) $body['score'] : 0.0;

        return $score >= (float) $settings['min_score'];
    }
}
