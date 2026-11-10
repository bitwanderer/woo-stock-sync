<?php
/**
 * WC_Google_Sheet_Sync_Admin class.
 */
class WC_Google_Sheet_Sync_Admin {

    public function __construct() {}

    public function init() {
        WC_GSS_Google_OAuth::register();
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_wc_gss_sync_data', array( $this, 'handle_sync_ajax' ) );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Sheet Sync', 'wc-google-sheet-sync' ),
            __( 'Sheet Sync', 'wc-google-sheet-sync' ),
            'manage_woocommerce',
            'wc-google-sheet-sync',
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        ?>
        <div id="wc-gss-admin-root" class="wrap">
            <h1><?php esc_html_e( 'WooCommerce Google Sheet Sync', 'wc-google-sheet-sync' ); ?></h1>
            <div id="wc-gss-react-app"></div>
            <?php WC_GSS_Google_OAuth::render(); ?>
        </div>
        <?php
    }

public function enqueue_admin_scripts( $hook ) {
    // Only load scripts/styles for our plugin page
    if ( strpos( $hook, 'wc-google-sheet-sync' ) === false ) {
        return;
    }

    // Enqueue WordPress-provided React and ReactDOM
    wp_enqueue_script( 'react' );
    wp_enqueue_script( 'react-dom' );

    // Our admin React app
    wp_enqueue_script(
        'wc-gss-admin-app',
        WC_GSS_PLUGIN_URL . 'assets/js/wc-gss-admin-app.js',
        array( 'react', 'react-dom', 'wp-element' ),
        WC_GSS_VERSION,
        true
    );

    // Localized data for AJAX calls
    wp_localize_script( 'wc-gss-admin-app', 'wcGssData', array(
        'ajax_url'        => admin_url( 'admin-ajax.php' ),
        'sync_nonce'      => wp_create_nonce( 'wc-gss-sync-nonce' ),
        'private_sheet'   => WC_GSS_Private_Sheet::configured(),
        'sheet_url'       => WC_GSS_Private_Sheet::configured() ? '' : get_option( 'wc_gss_google_sheet_url', '' ),
        'last_sync_time'  => get_option( 'wc_gss_last_sync_time', '' ),
    ));

    // Load our CSS
    wp_enqueue_style(
        'wc-gss-admin-style',
        WC_GSS_PLUGIN_URL . 'assets/css/wc-gss-admin.css',
        array(),
        WC_GSS_VERSION
    );
}


    public function handle_sync_ajax() {
        check_ajax_referer( 'wc-gss-sync-nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'wc-google-sheet-sync' ) ), 403 );
        }

        $private_sheet = WC_GSS_Private_Sheet::configured();
        $sheet_url = ! $private_sheet && isset( $_POST['sheet_url'] ) && is_string( $_POST['sheet_url'] )
            ? trim( wp_unslash( $_POST['sheet_url'] ) )
            : '';
        if ( ! $private_sheet && ! $this->is_google_csv_url( $sheet_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Enter an HTTPS Google Sheets CSV export URL.', 'wc-google-sheet-sync' ) ), 400 );
        }

        global $wpdb;
        // A database advisory lock is atomic and is released if the connection closes.
        $lock = hash( 'sha256', DB_NAME . ':' . $wpdb->options . ':wc-gss-sync' );
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
        if ( '1' !== (string) $acquired ) {
            $message = '0' === (string) $acquired
                ? __( 'A sheet sync is already running.', 'wc-google-sheet-sync' )
                : __( 'Could not acquire the sheet sync lock.', 'wc-google-sheet-sync' );
            wp_send_json_error( array( 'message' => $message ), 409 );
        }

        try {
            $body = $private_sheet ? WC_GSS_Private_Sheet::fetch_csv() : $this->fetch_csv( $sheet_url );
            $result = is_wp_error( $body ) ? $body : $this->sync_sheet_data( $body );
            if ( ! is_wp_error( $result ) ) {
                if ( ! $private_sheet ) {
                    update_option( 'wc_gss_google_sheet_url', $sheet_url );
                }
                $result['last_sync_time'] = gmdate( 'c' );
                update_option( 'wc_gss_last_sync_time', $result['last_sync_time'] );
            }
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( $result );
    }

    private function is_google_csv_url( $url, $is_redirect = false ) {
        if ( strlen( $url ) > 8192 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
            return false;
        }

        $parts = wp_parse_url( $url );
        if ( ! $parts || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) ||
            isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( $parts['port'] ?? 443 ) !== 443 ) {
            return false;
        }

        $host = strtolower( $parts['host'] ?? '' );
        if ( $is_redirect && preg_match( '/\Adoc-[a-z0-9-]+-sheets\.googleusercontent\.com\z/', $host ) ) {
            return true;
        }
        if ( 'docs.google.com' !== $host ) {
            return false;
        }

        $path = $parts['path'] ?? '';
        parse_str( $parts['query'] ?? '', $query );
        return ( preg_match( '~\A/spreadsheets/d/(?:e/)?[A-Za-z0-9_-]+/pub\z~', $path ) && 'csv' === ( $query['output'] ?? '' ) )
            || ( preg_match( '~\A/spreadsheets/d/[A-Za-z0-9_-]+/export\z~', $path ) && 'csv' === ( $query['format'] ?? '' ) );
    }

    private function fetch_csv( $url ) {
        $max_bytes = 2 * 1024 * 1024;
        for ( $redirects = 0; $redirects <= 3; $redirects++ ) {
            $response = wp_safe_remote_get( $url, array(
                'timeout'             => 10,
                'redirection'         => 0,
                'limit_response_size' => $max_bytes + 1,
                'decompress'          => false,
                'headers'             => array( 'Accept-Encoding' => 'identity' ),
            ) );
            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'wc_gss_fetch', __( 'Could not download the Google Sheets CSV.', 'wc-google-sheet-sync' ) );
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
                $location = wp_remote_retrieve_header( $response, 'location' );
                if ( 3 === $redirects || ! is_string( $location ) || '' === $location ) {
                    break;
                }
                $url = WP_Http::make_absolute_url( $location, $url );
                if ( ! $this->is_google_csv_url( $url, true ) ) {
                    break;
                }
                continue;
            }

            if ( 200 !== $status ) {
                return new WP_Error( 'wc_gss_http_status', __( 'Google Sheets did not return a successful CSV download. Check the publication settings.', 'wc-google-sheet-sync' ) );
            }

            $body = wp_remote_retrieve_body( $response );
            if ( strlen( $body ) > $max_bytes ) {
                return new WP_Error( 'wc_gss_size', __( 'The CSV exceeds the 2 MiB download limit.', 'wc-google-sheet-sync' ) );
            }
            return $body;
        }

        return new WP_Error( 'wc_gss_redirect', __( 'Google Sheets redirected outside the allowed CSV download locations or exceeded the redirect limit.', 'wc-google-sheet-sync' ) );
    }

    private function parse_price( $value ) {
        $value = trim( $value );
        // Accept plain decimals and correctly grouped thousands, without coercion.
        if ( ! preg_match( '/\A(?:[0-9]{1,12}|[0-9]{1,3}(?:,[0-9]{3}){1,3})(?:\.[0-9]{1,6})?\z/', $value ) ) {
            return null;
        }
        return str_replace( ',', '', $value );
    }

    private function is_csv_record( $record ) {
        $field = '(?:"(?:[^"]++|"")*+"|[^",\r\n]*+)';
        return 1 === preg_match( '~\A' . $field . '(?:,' . $field . ')*+(?:\r\n|\r|\n)?\z~', $record );
    }

    public static function import_columns( array $header ) {
        $fields = array( 'sku', 'stock', 'regular price', 'sale price' );
        $columns = array();
        $header = array_map( static function ( $name ) {
            return is_scalar( $name ) ? strtolower( trim( (string) $name ) ) : '';
        }, $header );
        foreach ( $fields as $field ) {
            $matches = array_keys( $header, $field, true );
            if ( 1 !== count( $matches ) ) {
                return new WP_Error( 'wc_gss_header', sprintf( __( 'The CSV must contain exactly one "%s" column.', 'wc-google-sheet-sync' ), $field ) );
            }
            $columns[ $field ] = $matches[0];
        }
        return $columns;
    }

    private function parse_csv( $body ) {
        if ( ! preg_match( '//u', $body ) || false !== strpos( $body, "\0" ) ) {
            return new WP_Error( 'wc_gss_encoding', __( 'The CSV must be UTF-8 text.', 'wc-google-sheet-sync' ) );
        }
        $handle = fopen( 'php://temp', 'w+' );
        if ( false === $handle ) {
            return new WP_Error( 'wc_gss_stream', __( 'Could not open a temporary CSV stream.', 'wc-google-sheet-sync' ) );
        }

        try {
            if ( 0 === strpos( $body, "\xEF\xBB\xBF" ) ) {
                $body = substr( $body, 3 );
            }
            if ( strlen( $body ) !== fwrite( $handle, $body ) || ! rewind( $handle ) ) {
                return new WP_Error( 'wc_gss_stream', __( 'Could not read the downloaded CSV.', 'wc-google-sheet-sync' ) );
            }

            $header = fgetcsv( $handle, 0, ',', '"', '' );
            $offset = ftell( $handle );
            if ( ! $header || ! $this->is_csv_record( substr( $body, 0, $offset ) ) ) {
                return new WP_Error( 'wc_gss_header', __( 'The CSV header is missing or invalid.', 'wc-google-sheet-sync' ) );
            }
            $columns = self::import_columns( $header );
            if ( is_wp_error( $columns ) ) {
                return $columns;
            }

            $rows = array();
            $row_number = 1;
            while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
                $row_number++;
                if ( $row_number > 10001 ) {
                    return new WP_Error( 'wc_gss_rows', __( 'The CSV exceeds the 10,000-row limit.', 'wc-google-sheet-sync' ) );
                }
                $end = ftell( $handle );
                if ( ! $this->is_csv_record( substr( $body, $offset, $end - $offset ) ) ) {
                    return new WP_Error( 'wc_gss_row', sprintf( __( 'CSV row %d is malformed.', 'wc-google-sheet-sync' ), $row_number ) );
                }
                $offset = $end;
                $sku = trim( $row[ $columns['sku'] ] ?? '' );
                if ( '' === $sku || preg_match( '/\A[\s\p{Z}]+\z/u', $sku ) ) {
                    continue;
                }
                if ( strlen( $sku ) > 255 || preg_match( '/[\x00-\x1f\x7f]/', $sku ) ) {
                    return new WP_Error( 'wc_gss_row', sprintf( __( 'CSV row %d has an invalid SKU.', 'wc-google-sheet-sync' ), $row_number ) );
                }
                $rows[] = array(
                    $row_number,
                    $sku,
                    count( $row ) === count( $header ),
                    $row[ $columns['stock'] ] ?? '',
                    $row[ $columns['regular price'] ] ?? '',
                    $row[ $columns['sale price'] ] ?? '',
                );
            }

            return $rows;
        } finally {
            fclose( $handle );
        }
    }

    private function sync_sheet_data( $body ) {
        $rows = $this->parse_csv( $body );
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }

        $updates = array();
        $not_found = 0;
        $updated = 0;
        try {
            foreach ( $rows as list( $row_number, $sku, $complete, $stock, $regular, $sale_input ) ) {
                $product_id = wc_get_product_id_by_sku( $sku );
                if ( ! $product_id ) {
                    $not_found++;
                    continue;
                }
                if ( ! $complete ) {
                    return new WP_Error( 'wc_gss_row', sprintf( __( 'CSV row %d has the wrong number of columns.', 'wc-google-sheet-sync' ), $row_number ) );
                }
                $stock = trim( $stock );
                $regular = $this->parse_price( $regular );
                $sale_input = trim( $sale_input );
                $sale = '' === $sale_input ? '' : $this->parse_price( $sale_input );
                if ( ! preg_match( '/\A[0-9]{1,10}\z/', $stock ) || (float) $stock > 2147483647 ||
                    null === $regular || null === $sale || ( '' !== $sale && (float) $sale > 0 && (float) $sale >= (float) $regular ) ) {
                    return new WP_Error( 'wc_gss_row', sprintf( __( 'CSV row %d has an invalid stock quantity or price.', 'wc-google-sheet-sync' ), $row_number ) );
                }
                if ( isset( $updates[ $product_id ] ) ) {
                    return new WP_Error( 'wc_gss_duplicate', __( 'Multiple CSV rows resolve to the same WooCommerce product.', 'wc-google-sheet-sync' ) );
                }
                if ( ! current_user_can( 'edit_post', $product_id ) ) {
                    return new WP_Error( 'wc_gss_product_permission', __( 'You do not have permission to edit every product in this import.', 'wc-google-sheet-sync' ) );
                }
                $updates[ $product_id ] = array( (int) $stock, $regular, '' !== $sale && (float) $sale > 0 ? $sale : '' );
            }

            foreach ( $updates as $product_id => list( $stock, $regular, $sale ) ) {
                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    throw new RuntimeException( 'Could not load product.' );
                }
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock );
                $product->set_regular_price( $regular );
                $product->set_sale_price( $sale );
                if ( ! $product->save() ) {
                    throw new RuntimeException( 'Could not save product.' );
                }
                $updated++;
            }
        } catch ( Throwable $error ) {
            return new WP_Error( 'wc_gss_write', sprintf( __( 'The import stopped after %d confirmed product saves. Check product data before retrying.', 'wc-google-sheet-sync' ), $updated ) );
        }

        return array(
            'updated'    => $updated,
            'not_found'  => $not_found,
            'total_rows' => count( $rows ),
        );
    }
}
