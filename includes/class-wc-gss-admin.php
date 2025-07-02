<?php
/**
 * WC_Google_Sheet_Sync_Admin class.
 */
class WC_Google_Sheet_Sync_Admin {

    public function __construct() {}

    public function init() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_wc_gss_sync_data', array( $this, 'handle_sync_ajax' ) );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Sheet Sync', 'wc-google-sheet-sync' ),
            __( 'Sheet Sync', 'wc-google-sheet-sync' ),
            'manage_options',
            'wc-google-sheet-sync',
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        ?>
        <div id="wc-gss-admin-root" class="wrap">
            <h1><?php esc_html_e( 'WooCommerce Google Sheet Sync', 'wc-google-sheet-sync' ); ?></h1>
            <p><?php esc_html_e( 'Configure your Google Sheet URL and synchronize product data.', 'wc-google-sheet-sync' ); ?></p>
            <div id="wc-gss-react-app"></div>
        </div>
        <?php
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( 'woocommerce_page_wc-google-sheet-sync' !== $hook ) {
            return;
        }

        wp_enqueue_script( 'react' );
        wp_enqueue_script( 'react-dom' );

        wp_enqueue_script(
            'wc-gss-admin-app',
            WC_GSS_PLUGIN_URL . 'assets/js/wc-gss-admin-app.js',
            array( 'react', 'react-dom', 'wp-element' ),
            WC_GSS_VERSION,
            true
        );

        wp_localize_script( 'wc-gss-admin-app', 'wcGssData', array(
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'sync_nonce'      => wp_create_nonce( 'wc-gss-sync-nonce' ),
            'sheet_url'       => get_option( 'wc_gss_google_sheet_url', '' ),
            'last_sync_time'  => get_option( 'wc_gss_last_sync_time', '' ),
        ));

        wp_enqueue_style(
            'wc-gss-admin-style',
            WC_GSS_PLUGIN_URL . 'assets/css/wc-gss-admin.css',
            array(),
            WC_GSS_VERSION
        );
    }

    public function handle_sync_ajax() {
        check_ajax_referer( 'wc-gss-sync-nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'wc-google-sheet-sync' ) ) );
        }

        $sheet_url = sanitize_url( $_POST['sheet_url'] ?? '' );
        if ( ! filter_var( $sheet_url, FILTER_VALIDATE_URL ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Google Sheet URL.', 'wc-google-sheet-sync' ) ) );
        }

        update_option( 'wc_gss_google_sheet_url', $sheet_url );

        $result = $this->fetch_and_sync_sheet_data( $sheet_url );
        update_option( 'wc_gss_last_sync_time', current_time( 'mysql' ) );

        wp_send_json_success( $result );
    }

    private function fetch_and_sync_sheet_data( $sheet_url ) {
        $updated     = 0;
        $not_found   = 0;
        $errors      = array();
        $total_rows  = 0;

        $response = wp_remote_get( $sheet_url, array( 'timeout' => 60 ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'updated'   => 0,
                'not_found'=> 0,
                'errors'   => array( 'Fetch error: ' . $response->get_error_message() ),
            );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return array(
                'updated' => 0,
                'not_found' => 0,
                'errors' => array( __( 'Fetched sheet is empty.', 'wc-google-sheet-sync' ) ),
            );
        }

        $handle = fopen( 'php://temp', 'r+' );
        fwrite( $handle, $body );
        rewind( $handle );

        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            return array(
                'updated' => 0,
                'not_found' => 0,
                'errors' => array( __( 'Could not read CSV header.', 'wc-google-sheet-sync' ) ),
            );
        }

        $normalized_header = array_map( 'strtolower', $header );
        $required_fields = array( 'sku', 'stock', 'regular price', 'sale price' );
        $field_map = [];

        foreach ( $required_fields as $field ) {
            $index = array_search( strtolower( $field ), $normalized_header );
            if ( $index === false ) {
                fclose( $handle );
                return array(
                    'updated' => 0,
                    'not_found' => 0,
                    'errors' => array( sprintf( __( 'Missing column: %s', 'wc-google-sheet-sync' ), $field ) ),
                );
            }
            $field_map[ $field ] = $index;
        }

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $total_rows++;
            if ( empty( $row ) || ! is_array( $row ) || count( $row ) <= max( $field_map ) ) {
                $errors[] = "Row {$total_rows}: invalid or incomplete row.";
                continue;
            }

            $sku           = sanitize_text_field( $row[ $field_map['sku'] ] );
            $stock         = (int) $row[ $field_map['stock'] ];
            $regular_price = (float) str_replace( ',', '', $row[ $field_map['regular price'] ] );
            $sale_price    = (float) str_replace( ',', '', $row[ $field_map['sale price'] ] );

            if ( empty( $sku ) ) {
                $errors[] = "Row {$total_rows}: SKU is missing.";
                continue;
            }

            $product_id = wc_get_product_id_by_sku( $sku );
            if ( ! $product_id ) {
                $not_found++;
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $errors[] = "Row {$total_rows} (SKU: {$sku}): product could not be loaded.";
                $not_found++;
                continue;
            }

            wc_update_product_stock( $product_id, $stock );
            $product->set_manage_stock( true );

            $product->set_regular_price( $regular_price );
            if ( $sale_price > 0 && $sale_price < $regular_price ) {
                $product->set_sale_price( $sale_price );
            } else {
                $product->set_sale_price( '' );
            }

            $product->save();
            $updated++;
        }

        fclose( $handle );

        return array(
            'updated'     => $updated,
            'not_found'   => $not_found,
            'total_rows'  => $total_rows,
            'errors'      => $errors,
            'message'     => __( 'Sync completed.', 'wc-google-sheet-sync' ),
        );
    }
}
