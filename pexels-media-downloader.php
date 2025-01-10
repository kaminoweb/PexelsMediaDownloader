<?php
/**
 * Plugin Name: Pexels Media Downloader
 * Plugin URI: https://kaminoweb.com/
 * Description: Download images from Pexels directly into your WordPress Media Library.
 * Version: 1.3.0
 * Author: KAMINOWEB INC
 * Author URI: https://kaminoweb.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class PMD_Pexels_Media_Downloader {

    private $api_key;
    const VERSION = '1.3.0';
    const OPTION_NAME = 'pmd_pexels_api_key';
    const SETTINGS_GROUP = 'pmd_pexels_settings_group';
    const SCRIPT_HANDLE = 'pmd-pexels-scripts';
    const LOCALIZE_HANDLE = 'pmd_pexels_ajax';
    const NONCE_NAME = 'pmd_pexels_nonce';

    public function __construct() {
        // Initialize API Key
        $this->api_key = get_option( self::OPTION_NAME, '' );

        // Register settings
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Add admin menus
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );

        // Enqueue scripts and styles
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Register AJAX handlers
        add_action( 'wp_ajax_pmd_pexels_search', array( $this, 'search_pexels' ) );
        add_action( 'wp_ajax_pmd_pexels_download_images', array( $this, 'download_images' ) );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting( self::SETTINGS_GROUP, self::OPTION_NAME, array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );

        add_settings_section(
            'pmd_pexels_main_section',
            __( 'Pexels API Settings', 'pexels-media-downloader' ),
            null,
            self::SETTINGS_GROUP
        );

        add_settings_field(
            'pmd_pexels_api_key_field',
            __( 'Pexels API Key', 'pexels-media-downloader' ),
            array( $this, 'api_key_field_callback' ),
            self::SETTINGS_GROUP,
            'pmd_pexels_main_section'
        );
    }

    /**
     * Callback for API Key field
     */
    public function api_key_field_callback() {
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>" value="<?php echo esc_attr( $this->api_key ); ?>" size="50" required />
        <p class="description"><?php esc_html_e( 'Obtain your Pexels API key from the ', 'pexels-media-downloader' ); ?><a href="https://www.pexels.com/api/documentation/" target="_blank"><?php esc_html_e( 'Pexels API Documentation', 'pexels-media-downloader' ); ?></a>.</p>
        <?php
    }

    /**
     * Add settings and plugin pages to the admin menu
     */
    public function add_menu_page() {
        // Add settings page under Settings
        add_options_page(
            __( 'Pexels Media Downloader Settings', 'pexels-media-downloader' ),
            __( 'Pexels Downloader', 'pexels-media-downloader' ),
            'manage_options',
            'pmd_pexels_settings',
            array( $this, 'render_settings_page' )
        );

        // Add main plugin page under Media
        add_media_page(
            __( 'Pexels Media Downloader', 'pexels-media-downloader' ),
            __( 'Pexels Downloader', 'pexels-media-downloader' ),
            'manage_options',
            'pmd_pexels_downloader',
            array( $this, 'render_plugin_page' )
        );
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pexels Media Downloader Settings', 'pexels-media-downloader' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::SETTINGS_GROUP );
                do_settings_sections( self::SETTINGS_GROUP );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the main plugin page
     */
    public function render_plugin_page() {
        if ( empty( $this->api_key ) ) {
            echo '<div class="notice notice-warning"><p>' . sprintf( __( 'Please set your Pexels API key in the <a href="%s">settings page</a>.', 'pexels-media-downloader' ), admin_url( 'options-general.php?page=pmd_pexels_settings' ) ) . '</p></div>';
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pexels Media Downloader', 'pexels-media-downloader' ); ?></h1>
            <form id="pmd-pexels-search-form" class="pmd-search-form">
                <input type="text" id="pmd-pexels-search-query" placeholder="<?php esc_attr_e( 'Search for images...', 'pexels-media-downloader' ); ?>" required />

                <!-- Orientation Selection -->
                <select id="pmd-pexels-orientation" name="orientation">
                    <option value=""><?php esc_html_e( 'Any Orientation', 'pexels-media-downloader' ); ?></option>
                    <option value="landscape"><?php esc_html_e( 'Landscape', 'pexels-media-downloader' ); ?></option>
                    <option value="portrait"><?php esc_html_e( 'Portrait', 'pexels-media-downloader' ); ?></option>
                </select>

                <!-- Picture Size Inputs -->
                <input type="number" id="pmd-pexels-min-width" name="min_width" placeholder="<?php esc_attr_e( 'Min Width (px)', 'pexels-media-downloader' ); ?>" min="0" />
                <input type="number" id="pmd-pexels-min-height" name="min_height" placeholder="<?php esc_attr_e( 'Min Height (px)', 'pexels-media-downloader' ); ?>" min="0" />

                <button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'pexels-media-downloader' ); ?></button>
            </form>
            <div id="pmd-pexels-results"></div>
            <button id="pmd-pexels-download-selected" class="button button-primary"><strong><?php esc_html_e( 'Download Selected', 'pexels-media-downloader' ); ?></strong></button>
        </div>
        <?php
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts( $hook ) {
        // Load scripts only on our plugin pages
        if ( $hook !== 'media_page_pmd_pexels_downloader' && $hook !== 'settings_page_pmd_pexels_settings' ) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style( 'pmd-pexels-styles', plugin_dir_url( __FILE__ ) . 'css/styles.css', array(), self::VERSION );

        // Enqueue Dashicons
        wp_enqueue_style( 'dashicons' );

        // Enqueue JavaScript
        wp_enqueue_script( self::SCRIPT_HANDLE, plugin_dir_url( __FILE__ ) . 'js/scripts-pexels.js', array( 'jquery' ), self::VERSION, true );

        // Localize script with AJAX URL and nonce
        wp_localize_script( self::SCRIPT_HANDLE, self::LOCALIZE_HANDLE, array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE_NAME ),
        ) );
    }

    /**
     * Handle Pexels API search
     */
    public function search_pexels() {
        // Verify nonce
        check_ajax_referer( self::NONCE_NAME, 'nonce' );

        // Check user capabilities
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( __( 'You do not have sufficient permissions to perform this action.', 'pexels-media-downloader' ) );
        }

        // Retrieve and sanitize POST data
        $query        = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        $page         = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
        $orientation  = isset( $_POST['orientation'] ) ? sanitize_text_field( wp_unslash( $_POST['orientation'] ) ) : '';
        $min_width    = isset( $_POST['min_width'] ) ? intval( $_POST['min_width'] ) : '';
        $min_height   = isset( $_POST['min_height'] ) ? intval( $_POST['min_height'] ) : '';

        if ( empty( $query ) ) {
            wp_send_json_error( __( 'Search query cannot be empty.', 'pexels-media-downloader' ) );
        }

        // Check for cached data
        $transient_key = 'pmd_pexels_' . md5( $query . '_' . $page . '_' . $orientation . '_' . $min_width . '_' . $min_height );
        $cached_data   = get_transient( $transient_key );

        if ( false !== $cached_data ) {
            wp_send_json_success( $cached_data );
        }

        // Build Pexels API URL
        $api_url = add_query_arg( array(
            'query'     => $query,
            'per_page'  => 20,
            'page'      => $page,
        ), 'https://api.pexels.com/v1/search' );

        if ( ! empty( $orientation ) && in_array( $orientation, array( 'landscape', 'portrait' ), true ) ) {
            $api_url = add_query_arg( 'orientation', $orientation, $api_url );
        }

        if ( ! empty( $min_width ) ) {
            $api_url = add_query_arg( 'min_width', $min_width, $api_url );
        }

        if ( ! empty( $min_height ) ) {
            $api_url = add_query_arg( 'min_height', $min_height, $api_url );
        }

        // Set up headers with the API key
        $args = array(
            'headers' => array(
                'Authorization' => $this->api_key,
            ),
        );

        // Make the API request
        $response = wp_remote_get( $api_url, $args );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( __( 'Failed to connect to Pexels API: ', 'pexels-media-downloader' ) . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['error'] ) ) {
            wp_send_json_error( __( 'Pexels API Error: ', 'pexels-media-downloader' ) . $data['error'] );
        }

        if ( ! isset( $data['photos'] ) ) {
            wp_send_json_error( __( 'Unexpected response from Pexels API.', 'pexels-media-downloader' ) );
        }

        // Cache the data for 1 hour
        set_transient( $transient_key, $data, HOUR_IN_SECONDS );

        wp_send_json_success( $data );
    }

    /**
     * Handle image downloads
     */
    public function download_images() {
        // Verify nonce
        check_ajax_referer( self::NONCE_NAME, 'nonce' );

        // Check user capabilities
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( __( 'You do not have sufficient permissions to perform this action.', 'pexels-media-downloader' ) );
        }

        // Retrieve and sanitize POST data
$images = array();

if ( isset( $_POST['images'] ) && is_array( $_POST['images'] ) ) {
    foreach ( wp_unslash( $_POST['images'] ) as $image ) {
        $images[] = array(
            'url' => isset( $image['url'] ) ? esc_url_raw( $image['url'] ) : '',
            'id'  => isset( $image['id'] ) ? sanitize_text_field( $image['id'] ) : uniqid(),
        );
    }
}
        $query  = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

        if ( empty( $images ) || ! is_array( $images ) ) {
            wp_send_json_error( __( 'No images were selected for download.', 'pexels-media-downloader' ) );
        }

        if ( empty( $query ) ) {
            wp_send_json_error( __( 'Search query is missing. Cannot proceed with downloads.', 'pexels-media-downloader' ) );
        }

        // Sanitize the search query for use in filenames
        $sanitized_query = sanitize_title( $query );

        $downloaded = 0;
        $failed     = 0;

        foreach ( $images as $image ) {
            $url = isset( $image['url'] ) ? esc_url_raw( $image['url'] ) : '';
            $id  = isset( $image['id'] ) ? sanitize_text_field( $image['id'] ) : uniqid();

            if ( empty( $url ) ) {
                $failed++;
                continue;
            }

            $extension = pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
            $extension = strtolower( $extension ) ? strtolower( $extension ) : 'jpg'; // Default to jpg if extension is missing

            // Generate a meaningful filename
            $filename = "{$sanitized_query}_{$id}.{$extension}";

            $upload_dir = wp_upload_dir();
            $file_path  = trailingslashit( $upload_dir['path'] ) . $filename;

            // Avoid filename collisions
            if ( file_exists( $file_path ) ) {
                $filename  = "{$sanitized_query}_{$id}_" . uniqid() . ".{$extension}";
                $file_path = trailingslashit( $upload_dir['path'] ) . $filename;
            }

            // Download the image
            $image_response = wp_remote_get( $url );

            if ( is_wp_error( $image_response ) ) {
                $failed++;
                continue;
            }

            $image_data = wp_remote_retrieve_body( $image_response );

            if ( $image_data ) {
                // Save the image to the uploads directory
                $saved = file_put_contents( $file_path, $image_data );

                if ( false === $saved ) {
                    $failed++;
                    continue;
                }

                // Check the file type
                $wp_filetype = wp_check_filetype( $filename, null );

                // Prepare attachment data
                $attachment = array(
                    'post_mime_type' => $wp_filetype['type'],
                    'post_title'     => sanitize_file_name( $filename ),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                );

                // Insert the attachment
                $attach_id = wp_insert_attachment( $attachment, $file_path );

                if ( is_wp_error( $attach_id ) ) {
                    $failed++;
                    continue;
                }

                // Generate metadata and update attachment
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                $attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
                wp_update_attachment_metadata( $attach_id, $attach_data );

                $downloaded++;
            } else {
                $failed++;
            }
        }

        if ( $downloaded > 0 && $failed === 0 ) {
            wp_send_json_success( sprintf( __( 'Successfully downloaded %d image(s).', 'pexels-media-downloader' ), $downloaded ) );
        } elseif ( $downloaded > 0 && $failed > 0 ) {
            wp_send_json_success( sprintf( __( 'Successfully downloaded %d image(s). %d image(s) failed to download.', 'pexels-media-downloader' ), $downloaded, $failed ) );
        } else {
            wp_send_json_error( __( 'Failed to download images.', 'pexels-media-downloader' ) );
        }
    }
}

// Initialize the plugin
new PMD_Pexels_Media_Downloader();

