<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WC_GSS_Private_Sheet {
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_ROWS = 10000;

    public static function configured() {
        return defined( 'WC_GSS_GOOGLE_KEY_FILE' ) || defined( 'WC_GSS_GOOGLE_SPREADSHEET_ID' ) || defined( 'WC_GSS_GOOGLE_SHEET_TAB' );
    }

    public static function fetch_csv() {
        $id = defined( 'WC_GSS_GOOGLE_SPREADSHEET_ID' ) ? WC_GSS_GOOGLE_SPREADSHEET_ID : '';
        $tab = defined( 'WC_GSS_GOOGLE_SHEET_TAB' ) ? WC_GSS_GOOGLE_SHEET_TAB : 'products';
        if ( ! is_string( $id ) || ! preg_match( '/\A[A-Za-z0-9_-]{1,200}\z/', $id ) ||
            ! is_string( $tab ) || '' === trim( $tab ) || strlen( $tab ) > 400 || preg_match( '/[\x00-\x1f\x7f]/', $tab ) ) {
            return new WP_Error( 'wc_gss_private_config', __( 'Set a valid Google spreadsheet ID and tab in wp-config.php.', 'wc-google-sheet-sync' ) );
        }

        $token = self::access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // The API omits trailing empty rows. The response byte and row limits apply below.
        $range = "'" . str_replace( "'", "''", $tab ) . "'!A:D";
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . $id . '/values/' . rawurlencode( $range ) .
            '?majorDimension=ROWS&valueRenderOption=FORMATTED_VALUE';
        $response = wp_safe_remote_get( $url, array(
            'timeout'             => 10,
            'redirection'         => 0,
            'limit_response_size' => self::MAX_BYTES + 1,
            'decompress'          => false,
            'headers'             => array( 'Authorization' => 'Bearer ' . $token, 'Accept-Encoding' => 'identity' ),
        ) );
        $data = self::google_json( $response, self::MAX_BYTES, __( 'Google Sheets could not read the configured spreadsheet. Check that the Sheets API is enabled, the service account has Viewer access, and the tab name is correct.', 'wc-google-sheet-sync' ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $rows = $data['values'] ?? array();
        if ( ! is_array( $rows ) || count( $rows ) > self::MAX_ROWS + 1 ) {
            return new WP_Error( 'wc_gss_private_rows', __( 'The sheet response is invalid or exceeds 10,000 data rows.', 'wc-google-sheet-sync' ) );
        }

        // Normalize Sheets rows to CSV so both sources use the same import validation.
        $stream = fopen( 'php://temp', 'w+' );
        if ( false === $stream ) {
            return new WP_Error( 'wc_gss_private_stream', __( 'Could not open the temporary sheet stream.', 'wc-google-sheet-sync' ) );
        }
        try {
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) || count( $row ) > 4 || array_values( $row ) !== $row ) {
                    return new WP_Error( 'wc_gss_private_rows', __( 'Google Sheets returned an invalid row.', 'wc-google-sheet-sync' ) );
                }
                if ( array() === array_filter( $row, static function ( $value ) { return '' !== $value; } ) ) {
                    $row = array( '' );
                } else {
                    foreach ( $row as $value ) {
                        if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) && ! is_bool( $value ) ) {
                            return new WP_Error( 'wc_gss_private_rows', __( 'Google Sheets returned an invalid cell.', 'wc-google-sheet-sync' ) );
                        }
                    }
                    $row = array_pad( $row, 4, '' );
                    // Preserve booleans as text so the shared numeric validator rejects them.
                    $row = array_map( static function ( $value ) {
                        return is_bool( $value ) ? ( $value ? 'TRUE' : 'FALSE' ) : $value;
                    }, $row );
                }
                if ( false === fputcsv( $stream, $row, ',', '"', '' ) || ftell( $stream ) > self::MAX_BYTES ) {
                    return new WP_Error( 'wc_gss_private_size', __( 'The sheet exceeds the 2 MiB import limit or could not be read.', 'wc-google-sheet-sync' ) );
                }
            }
            rewind( $stream );
            $body = stream_get_contents( $stream );
            return is_string( $body ) ? $body : new WP_Error( 'wc_gss_private_stream', __( 'Could not read the temporary sheet stream.', 'wc-google-sheet-sync' ) );
        } finally {
            fclose( $stream );
        }
    }

    private static function access_token() {
        $file = defined( 'WC_GSS_GOOGLE_KEY_FILE' ) ? WC_GSS_GOOGLE_KEY_FILE : '';
        $path = is_string( $file ) && 0 === strpos( $file, '/' ) && false === strpos( $file, "\0" ) ? realpath( $file ) : false;
        $web_roots = array_filter( array( realpath( ABSPATH ), ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false ) );
        foreach ( $web_roots as $root ) {
            if ( $path === $root || ( $path && 0 === strpos( $path, rtrim( $root, '/' ) . '/' ) ) ) {
                $path = false;
                break;
            }
        }

        if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) || ! function_exists( 'openssl_sign' ) ) {
            return new WP_Error( 'wc_gss_key', __( 'Private Sheets sync requires PHP OpenSSL and a readable service-account key file outside the web root.', 'wc-google-sheet-sync' ) );
        }
        $contents = file_get_contents( $path, false, null, 0, 32769 );
        $credentials = is_string( $contents ) && strlen( $contents ) <= 32768 ? json_decode( $contents, true, 8 ) : null;
        if ( 'service_account' !== ( $credentials['type'] ?? '' ) || ! is_string( $credentials['client_email'] ?? null ) ||
            ! is_email( $credentials['client_email'] ) || ! is_string( $credentials['private_key'] ?? null ) ) {
            return new WP_Error( 'wc_gss_key', __( 'The configured Google service-account key is invalid.', 'wc-google-sheet-sync' ) );
        }
        $key = openssl_pkey_get_private( $credentials['private_key'] );
        $now = time();
        $claims = wp_json_encode( array(
            'iss'   => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 300,
        ) );
        if ( false === $key || false === $claims ) {
            return new WP_Error( 'wc_gss_key', __( 'Could not use the configured Google service-account key.', 'wc-google-sheet-sync' ) );
        }
        $assertion = self::base64url( '{"alg":"RS256","typ":"JWT"}' ) . '.' . self::base64url( $claims );
        if ( ! openssl_sign( $assertion, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
            return new WP_Error( 'wc_gss_key', __( 'Could not sign the Google service-account request.', 'wc-google-sheet-sync' ) );
        }
        $response = wp_safe_remote_post( self::TOKEN_URL, array(
            'timeout'             => 10,
            'redirection'         => 0,
            'limit_response_size' => 16385,
            'decompress'          => false,
            'headers'             => array( 'Accept-Encoding' => 'identity' ),
            'body'                => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion . '.' . self::base64url( $signature ),
            ),
        ) );
        $data = self::google_json( $response, 16384, __( 'Google refused the service-account token request. Check that the key is active and the server clock is correct.', 'wc-google-sheet-sync' ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $token = $data['access_token'] ?? '';
        if ( ! is_string( $token ) || ! preg_match( '!\A[A-Za-z0-9._~+/-]+=*\z!', $token ) ) {
            return new WP_Error( 'wc_gss_token', __( 'Google did not return a usable access token.', 'wc-google-sheet-sync' ) );
        }
        return $token;
    }

    private static function google_json( $response, $max_bytes, $message ) {
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'wc_gss_google_http', $message );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return new WP_Error( 'wc_gss_google_http', sprintf( '%s (HTTP %d)', $message, $status ) );
        }
        $body = wp_remote_retrieve_body( $response );
        $data = strlen( $body ) <= $max_bytes ? json_decode( $body, true, 16 ) : null;
        return is_array( $data ) ? $data : new WP_Error( 'wc_gss_google_response', __( 'Google returned an invalid or oversized response.', 'wc-google-sheet-sync' ) );
    }

    private static function base64url( $value ) {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }
}
