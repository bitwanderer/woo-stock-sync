<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WC_GSS_Google_OAuth {
    private const OPTION = 'wc_gss_google_oauth_refresh';
    private const CIPHER = 'aes-256-gcm';

    public static function configured() {
        return defined( 'WC_GSS_GOOGLE_CLIENT_ID' ) || defined( 'WC_GSS_GOOGLE_CLIENT_SECRET' );
    }

    private static function ready() {
        return defined( 'WC_GSS_GOOGLE_CLIENT_ID' ) && is_string( WC_GSS_GOOGLE_CLIENT_ID ) && '' !== WC_GSS_GOOGLE_CLIENT_ID &&
            defined( 'WC_GSS_GOOGLE_CLIENT_SECRET' ) && is_string( WC_GSS_GOOGLE_CLIENT_SECRET ) && '' !== WC_GSS_GOOGLE_CLIENT_SECRET &&
            function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true ) &&
            'https' === wp_parse_url( self::callback_url(), PHP_URL_SCHEME );
    }

    public static function callback_url() {
        return admin_url( 'admin-post.php?action=wc_gss_google_callback', 'https' );
    }

    public static function register() {
        add_action( 'admin_post_wc_gss_google_connect', array( self::class, 'connect' ) );
        add_action( 'admin_post_wc_gss_google_callback', array( self::class, 'callback' ) );
        add_action( 'admin_post_wc_gss_google_disconnect', array( self::class, 'disconnect' ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<p>' . esc_html__( 'Google OAuth callback URL:', 'wc-google-sheet-sync' ) . ' <code>' . esc_html( self::callback_url() ) . '</code></p>';
        if ( ! self::configured() ) {
            return;
        }

        $messages = array(
            'connected'    => __( 'Google account connected.', 'wc-google-sheet-sync' ),
            'disconnected' => __( 'Google account disconnected from WordPress.', 'wc-google-sheet-sync' ),
            'expired'      => __( 'The connection request expired or could not be verified. Click Connect Google to start again.', 'wc-google-sheet-sync' ),
            'denied'       => __( 'Google did not authorize the connection. Check the Workspace app settings and try again.', 'wc-google-sheet-sync' ),
            'token'        => __( 'Could not save a read-only Google connection. Check the OAuth client settings and try again.', 'wc-google-sheet-sync' ),
        );
        $status = self::query( 'wc_gss_google', 30 );
        if ( isset( $messages[ $status ] ) ) {
            echo '<p role="status">' . esc_html( $messages[ $status ] ) . '</p>';
        }

        if ( ! self::ready() ) {
            echo '<p>' . esc_html__( 'Google OAuth requires the client ID and secret in wp-config.php, an HTTPS admin URL, and PHP OpenSSL.', 'wc-google-sheet-sync' ) . '</p>';
            return;
        }
        $connected = null !== self::refresh_token();
        self::form( 'connect', $connected ? __( 'Reconnect Google', 'wc-google-sheet-sync' ) : __( 'Connect Google', 'wc-google-sheet-sync' ) );
        if ( $connected ) {
            self::form( 'disconnect', __( 'Disconnect Google', 'wc-google-sheet-sync' ) );
        }
    }

    private static function form( $action, $label ) {
        $name = 'wc_gss_google_' . $action;
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php', 'https' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( $name ) . '">';
        wp_nonce_field( $name );
        echo '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
    }

    public static function connect() {
        self::require_admin( 'POST' );
        check_admin_referer( 'wc_gss_google_connect' );
        if ( ! self::ready() ) {
            self::finish( 'token' );
        }
        $state = bin2hex( random_bytes( 32 ) );
        $verifier = WC_GSS_Private_Sheet::base64url( random_bytes( 32 ) );
        $saved = set_transient( self::state_key( $state ), $verifier, 10 * MINUTE_IN_SECONDS );
        if ( ! $saved ) {
            self::finish( 'token' );
        }
        $query = array(
            'client_id'             => WC_GSS_GOOGLE_CLIENT_ID,
            'redirect_uri'          => self::callback_url(),
            'response_type'         => 'code',
            'scope'                 => WC_GSS_Private_Sheet::SCOPE,
            'state'                 => $state,
            'code_challenge'        => WC_GSS_Private_Sheet::base64url( hash( 'sha256', $verifier, true ) ),
            'code_challenge_method' => 'S256',
            'access_type'           => 'offline',
            'prompt'                => 'consent select_account',
        );
        wp_redirect( 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
        exit;
    }

    public static function callback() {
        self::require_admin( 'GET' );
        header( 'Referrer-Policy: no-referrer' );
        nocache_headers();
        $state = self::query( 'state', 64 );
        if ( ! self::ready() || ! preg_match( '/\A[a-f0-9]{64}\z/', $state ) ) {
            self::finish( 'expired' );
        }
        $key = self::state_key( $state );
        $verifier = get_transient( $key );
        if ( ! is_string( $verifier ) || ! delete_transient( $key ) ) {
            self::finish( 'expired' );
        }
        $code = self::query( 'code', 4096 );
        if ( '' === $code || '' !== self::query( 'error', 100 ) ) {
            self::finish( 'denied' );
        }

        $data = self::request( array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::callback_url(),
            'code_verifier' => $verifier,
        ) );
        $refresh = is_wp_error( $data ) ? '' : ( $data['refresh_token'] ?? '' );
        if ( is_wp_error( $data ) || ( $data['scope'] ?? '' ) !== WC_GSS_Private_Sheet::SCOPE || ! self::valid_refresh_token( $refresh ) ) {
            self::finish( 'token' );
        }

        $iv = random_bytes( 12 );
        $encrypted = openssl_encrypt( $refresh, self::CIPHER, self::storage_key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $encrypted || ! update_option( self::OPTION, base64_encode( $iv . $tag . $encrypted ), false ) ) {
            self::finish( 'token' );
        }
        self::finish( 'connected' );
    }

    public static function disconnect() {
        self::require_admin( 'POST' );
        check_admin_referer( 'wc_gss_google_disconnect' );
        delete_option( self::OPTION );
        self::finish( 'disconnected' );
    }

    public static function access_token() {
        $refresh = self::ready() ? self::refresh_token() : null;
        if ( null === $refresh ) {
            return new WP_Error( 'wc_gss_oauth_connect', __( 'Ask a WordPress administrator to connect Google from the Sheet Sync page.', 'wc-google-sheet-sync' ) );
        }
        $data = self::request( array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
        ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( isset( $data['scope'] ) && $data['scope'] !== WC_GSS_Private_Sheet::SCOPE ) {
            return new WP_Error( 'wc_gss_oauth_scope', __( 'Google returned an unexpected permission. Reconnect Google and grant read-only Sheets access.', 'wc-google-sheet-sync' ) );
        }
        return WC_GSS_Private_Sheet::extract_access_token( $data );
    }

    private static function request( $body ) {
        return WC_GSS_Private_Sheet::request_token( $body + array(
            'client_id'     => WC_GSS_GOOGLE_CLIENT_ID,
            'client_secret' => WC_GSS_GOOGLE_CLIENT_SECRET,
        ), __( 'Google refused the OAuth token request. Ask a WordPress administrator to reconnect Google.', 'wc-google-sheet-sync' ) );
    }

    private static function refresh_token() {
        $stored = get_option( self::OPTION, '' );
        $data = is_string( $stored ) && strlen( $stored ) <= 5600 ? base64_decode( $stored, true ) : false;
        if ( false === $data || strlen( $data ) <= 28 ) {
            return null;
        }
        $refresh = openssl_decrypt( substr( $data, 28 ), self::CIPHER, self::storage_key(), OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ) );
        return self::valid_refresh_token( $refresh ) ? $refresh : null;
    }

    private static function valid_refresh_token( $token ) {
        return is_string( $token ) && strlen( $token ) <= 4096 && 1 === preg_match( '!\A[A-Za-z0-9._~+/-]+=*\z!', $token );
    }

    private static function storage_key() {
        return hash_hmac( 'sha256', 'wc-gss-oauth:' . get_current_blog_id() . ':' . WC_GSS_GOOGLE_CLIENT_ID, wp_salt( 'auth' ), true );
    }

    private static function state_key( $state ) {
        return 'wc_gss_oauth_' . hash( 'sha256', get_current_user_id() . ':' . wp_get_session_token() . ':' . $state );
    }

    private static function query( $name, $max_length ) {
        $value = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? wp_unslash( $_GET[ $name ] ) : '';
        return strlen( $value ) <= $max_length ? $value : '';
    }

    private static function require_admin( $method ) {
        if ( ! current_user_can( 'manage_options' ) || $method !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-google-sheet-sync' ), '', array( 'response' => 403 ) );
        }
    }

    private static function finish( $status ) {
        wp_safe_redirect( add_query_arg( array( 'page' => 'wc-google-sheet-sync', 'wc_gss_google' => $status ), admin_url( 'admin.php', 'https' ) ) );
        exit;
    }
}
