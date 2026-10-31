<?php
/**
 * Plugin Name: Smart WebP Converter
 * Description: Converts new image uploads to WebP, with optional safe original replacement, WebP sidecars, and conversion statistics.
 * Version: 1.2.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: FireXCore
 * License: GPL-2.0-or-later
 * Text Domain: smart-webp-converter
 */

defined( 'ABSPATH' ) || exit;

final class FireXCore_WebP_Converter {
    const VERSION = '1.2.0';
    const TABLE = 'webp_conversion_stats';

    /** @var array<string, int> Original bytes keyed by new WebP upload path. */
    private $replaced_uploads = array();

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'init', array( $this, 'maybe_upgrade' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_link' ) );
        add_filter( 'wp_handle_upload', array( $this, 'handle_upload' ), 20, 2 );
        add_filter( 'wp_handle_sideload', array( $this, 'handle_upload' ), 20, 2 );
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'attachment_metadata' ), 20, 3 );
        add_filter( 'wp_get_attachment_image', array( $this, 'attachment_picture' ), 20, 5 );
        add_filter( 'render_block', array( $this, 'render_image_block' ), 20, 2 );
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'image_attributes' ), 20, 3 );
    }

    public function activate() {
        $this->install_table();
        update_option( 'firexcore_webp_schema_version', self::VERSION, false );
        wp_clear_scheduled_hook( 'firexcore_webp_cleanup_temp' );
    }

    public function maybe_upgrade() {
        if ( get_option( 'firexcore_webp_schema_version' ) !== self::VERSION ) {
            $this->activate();
        }
    }

    private function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $name = $wpdb->prefix . self::TABLE;
        $sql = "CREATE TABLE $name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            original_size bigint(20) NOT NULL,
            webp_size bigint(20) NOT NULL,
            conversion_time datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY attachment_id (attachment_id)
        ) " . $wpdb->get_charset_collate() . ';';
        dbDelta( $sql );
    }

    private function setting( $name, $default = false ) {
        return get_option( 'firexcore_webp_' . $name, $default );
    }

    private function eligible( $path, $mime ) {
        if ( ! is_string( $path ) || ! is_file( $path ) ) {
            return false;
        }
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
            return false;
        }
        $info = @getimagesize( $path );
        if ( ! is_array( $info ) || empty( $info['mime'] ) || $info['mime'] !== $mime ) {
            return false;
        }
        $limit = 40000000;
        if ( (float) $info[0] * (float) $info[1] > $limit ) {
            return false;
        }
        // GD silently flattens animated GIFs. Require Imagick to inspect frames.
        if ( 'image/gif' === $mime ) {
            if ( ! class_exists( 'Imagick' ) ) {
                return false;
            }
            try {
                $image = new Imagick( $path );
                $frames = $image->getNumberImages();
                $image->clear();
                if ( $frames !== 1 ) {
                    return false;
                }
            } catch ( Exception $e ) {
                return false;
            }
        }
        return true;
    }

    private function valid_webp( $path ) {
        if ( ! is_file( $path ) || filesize( $path ) < 20 ) {
            return false;
        }
        $info = @getimagesize( $path );
        return is_array( $info ) && isset( $info['mime'] ) && 'image/webp' === $info['mime'];
    }

    /**
     * Never overwrites a pre-existing file. Returns true only for a readable WebP.
     */
    private function convert( $source, $target, $mime ) {
        if ( ! $this->eligible( $source, $mime ) || file_exists( $target ) ) {
            return false;
        }
        $lossless = (bool) $this->setting( 'lossless', false );
        $quality = max( 1, min( 100, (int) $this->setting( 'quality', 80 ) ) );
        $success = false;

        if ( class_exists( 'Imagick' ) ) {
            try {
                if ( in_array( 'WEBP', Imagick::queryFormats( 'WEBP' ), true ) ) {
                    $im = new Imagick( $source );
                    if ( $im->getNumberImages() === 1 ) {
                        if ( method_exists( $im, 'autoOrient' ) ) {
                            $im->autoOrient();
                        } elseif ( method_exists( $im, 'autoOrientImage' ) ) {
                            $im->autoOrientImage();
                        }
                        if ( (bool) $this->setting( 'strip_metadata', true ) ) {
                            foreach ( array( 'exif', 'iptc', 'xmp' ) as $profile ) {
                                try {
                                    $im->profileImage( $profile, null );
                                } catch ( Exception $ignored ) {
                                    // Profile absent; keep color profiles intact.
                                }
                            }
                        }
                        $im->setImageFormat( 'WEBP' );
                        $im->setImageCompressionQuality( $quality );
                        if ( $lossless ) {
                            $im->setOption( 'webp:lossless', 'true' );
                        }
                        $success = (bool) $im->writeImage( $target );
                    }
                    $im->clear();
                }
            } catch ( Exception $e ) {
                $success = false;
            }
        }

        if ( ! $success && ! $lossless ) {
            // WordPress selects GD or Imagick according to its supported editor stack.
            $editor = wp_get_image_editor( $source );
            if ( ! is_wp_error( $editor ) && $editor->supports_mime_type( 'image/webp' ) ) {
                $editor->set_quality( $quality );
                $saved = $editor->save( $target, 'image/webp' );
                $success = ! is_wp_error( $saved );
            }
        }

        if ( ! $success || ! $this->valid_webp( $target ) ) {
            if ( is_file( $target ) ) {
                wp_delete_file( $target );
            }
            return false;
        }
        return true;
    }

    private function mime_for_path( $path ) {
        $info = is_file( $path ) ? @getimagesize( $path ) : false;
        return is_array( $info ) && isset( $info['mime'] ) ? $info['mime'] : '';
    }

    public function handle_upload( $upload, $context = 'upload' ) {
        if ( ! is_array( $upload ) || isset( $upload['error'] ) || empty( $upload['file'] ) ||
             empty( $upload['url'] ) || ! $this->setting( 'replace_original', false ) ) {
            return $upload;
        }
        $source = $upload['file'];
        $mime = $this->mime_for_path( $source );
        if ( ! $this->eligible( $source, $mime ) ) {
            return $upload;
        }
        $dir = dirname( $source );
        $name = pathinfo( $source, PATHINFO_FILENAME ) . '.webp';
        $target = $dir . '/' . wp_unique_filename( $dir, $name );
        if ( ! $this->convert( $source, $target, $mime ) ) {
            return $upload;
        }
        $original_size = filesize( $source );
        // Preserve the original if deletion fails; do not leave a conflicting attachment.
        wp_delete_file( $source );
        if ( file_exists( $source ) ) {
            wp_delete_file( $target );
            return $upload;
        }
        $this->replaced_uploads[ wp_normalize_path( $target ) ] = (int) $original_size;
        $upload['file'] = $target;
        $upload['url'] = str_replace( wp_basename( $source ), wp_basename( $target ), $upload['url'] );
        $upload['type'] = 'image/webp';
        return $upload;
    }

    public function attachment_metadata( $metadata, $attachment_id, $context = 'create' ) {
        if ( ! is_array( $metadata ) || ! $attachment_id ) {
            return $metadata;
        }
        $path = get_attached_file( $attachment_id );
        if ( ! is_string( $path ) || ! is_file( $path ) ) {
            return $metadata;
        }
        $mime = get_post_mime_type( $attachment_id );
        $normalized = wp_normalize_path( $path );
        if ( 'image/webp' === $mime ) {
            $pending_path = $normalized;
            if ( ! isset( $this->replaced_uploads[ $pending_path ] ) && ! empty( $metadata['original_image'] ) ) {
                // WordPress may have generated a -scaled file before this filter runs.
                $pending_path = wp_normalize_path( dirname( $path ) . '/' . wp_basename( $metadata['original_image'] ) );
            }
            if ( isset( $this->replaced_uploads[ $pending_path ] ) ) {
                $this->record_stats( $attachment_id, $this->replaced_uploads[ $pending_path ], filesize( $path ) );
                unset( $this->replaced_uploads[ $pending_path ] );
            }
            return $metadata;
        }
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
            return $metadata;
        }
        $target = preg_replace( '/\.(?:jpe?g|png|gif)$/i', '.webp', $path );
        if ( $target === $path ) {
            return $metadata;
        }
        $sidecars = get_post_meta( $attachment_id, '_firexcore_webp_sidecars', true );
        $sidecars = is_array( $sidecars ) ? $sidecars : array();
        $main_key = wp_normalize_path( $target );
        if ( ! $this->valid_webp( $target ) ) {
            // An unrelated file at the predicted path must never be overwritten or served.
            if ( file_exists( $target ) || ! $this->convert( $path, $target, $mime ) ) {
                return $metadata;
            }
            $sidecars[] = $main_key;
        } elseif ( ! in_array( $main_key, $sidecars, true ) ) {
            return $metadata;
        }

        // Core creates thumbnails before this filter; provide matching WebP sidecars.
        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size ) {
                if ( empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
                    continue;
                }
                $original = dirname( $path ) . '/' . wp_basename( $size['file'] );
                $output = preg_replace( '/\.(?:jpe?g|png|gif)$/i', '.webp', $original );
                if ( $output === $original ) {
                    continue;
                }
                $key = wp_normalize_path( $output );
                if ( in_array( $key, $sidecars, true ) && $this->valid_webp( $output ) ) {
                    continue;
                }
                if ( ! file_exists( $output ) &&
                     $this->convert( $original, $output, $this->mime_for_path( $original ) ) ) {
                    $sidecars[] = $key;
                }
            }
        }
        update_post_meta( $attachment_id, '_firexcore_webp_sidecars', array_values( array_unique( $sidecars ) ) );
        $this->record_stats( $attachment_id, filesize( $path ), filesize( $target ) );
        $this->maybe_set_alt( $attachment_id );
        return $metadata;
    }

    private function record_stats( $attachment_id, $original, $webp ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // Regeneration and intermediate metadata writes must not inflate the totals.
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE attachment_id = %d LIMIT 1", $attachment_id ) );
        if ( ! $exists ) {
            $wpdb->insert( $table, array(
                'attachment_id' => (int) $attachment_id,
                'original_size' => (int) $original,
                'webp_size' => (int) $webp,
                'conversion_time' => current_time( 'mysql' ),
            ), array( '%d', '%d', '%d', '%s' ) );
        }
    }

    private function maybe_set_alt( $attachment_id ) {
        if ( ! $this->setting( 'seo_optimization', true ) ||
             get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
            return;
        }
        $post = get_post( $attachment_id );
        if ( ! $post || empty( $post->post_title ) ) {
            return;
        }
        // Filenames are not useful accessible descriptions; don't fabricate them.
        $path = get_attached_file( $attachment_id );
        $stem = $path ? pathinfo( $path, PATHINFO_FILENAME ) : '';
        if ( sanitize_title( $post->post_title ) !== sanitize_title( $stem ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $post->post_title ) );
        }
    }

    private function webp_url( $url, $attachment_id ) {
        $uploads = wp_get_upload_dir();
        if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
            return false;
        }
        $url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
        $plain = explode( '?', $url, 2 )[0];
        $base = rtrim( $uploads['baseurl'], '/' ) . '/';
        if ( strpos( $plain, $base ) !== 0 || ! preg_match( '/\.(?:jpe?g|png|gif)$/i', $plain ) ) {
            return false;
        }
        $relative = rawurldecode( substr( $plain, strlen( $base ) ) );
        if ( strpos( $relative, '..' ) !== false || strpos( $relative, '\\' ) !== false ) {
            return false;
        }
        $target = preg_replace( '/\.(?:jpe?g|png|gif)$/i', '.webp',
            trailingslashit( $uploads['basedir'] ) . $relative );
        $sidecars = get_post_meta( $attachment_id, '_firexcore_webp_sidecars', true );
        if ( ! is_array( $sidecars ) ||
             ! in_array( wp_normalize_path( $target ), $sidecars, true ) ||
             ! $this->valid_webp( $target ) ) {
            return false;
        }
        return preg_replace( '/\.(?:jpe?g|png|gif)(?=$|\?)/i', '.webp', $url );
    }

    private function html_attribute( $html, $name ) {
        if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '="([^"]*)"/i', $html, $match ) ) {
            return html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' );
        }
        return '';
    }

    private function picture_html( $html, $attachment_id ) {
        if ( ! is_string( $html ) || false !== stripos( $html, '<picture' ) ||
             ! preg_match( '/<img\b[^>]*>/i', $html, $match ) ||
             'image/webp' === get_post_mime_type( $attachment_id ) ) {
            return $html;
        }
        $img = $match[0];
        $src = $this->webp_url( $this->html_attribute( $img, 'src' ), $attachment_id );
        if ( ! $src ) {
            return $html;
        }
        $candidates = array();
        $srcset = $this->html_attribute( $img, 'srcset' );
        if ( $srcset ) {
            foreach ( explode( ',', $srcset ) as $candidate ) {
                if ( preg_match( '/^\s*(\S+)\s+([0-9]+(?:w|x))\s*$/', $candidate, $parts ) ) {
                    $mapped = $this->webp_url( $parts[1], $attachment_id );
                    if ( $mapped ) {
                        $candidates[] = esc_url( $mapped ) . ' ' . $parts[2];
                    }
                }
            }
        }
        $source_set = $candidates ? implode( ', ', $candidates ) : esc_url( $src );
        $sizes = $this->html_attribute( $img, 'sizes' );
        $source = '<source type="image/webp" srcset="' . esc_attr( $source_set ) . '"' .
            ( $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
        return str_replace( $img, '<picture>' . $source . $img . '</picture>', $html );
    }

    public function attachment_picture( $html, $attachment_id, $size = 'thumbnail', $icon = false, $attr = array() ) {
        return $this->picture_html( $html, $attachment_id );
    }

    public function render_image_block( $html, $block ) {
        if ( ! isset( $block['blockName'] ) || 'core/image' !== $block['blockName'] ||
             empty( $block['attrs']['id'] ) ) {
            return $html;
        }
        return $this->picture_html( $html, absint( $block['attrs']['id'] ) );
    }

    public function image_attributes( $attr, $attachment, $size ) {
        if ( $this->setting( 'lazy_loading', true ) && ! isset( $attr['loading'] ) &&
             ( ! isset( $attr['fetchpriority'] ) || 'high' !== $attr['fetchpriority'] ) ) {
            $attr['loading'] = 'lazy';
        }
        return $attr;
    }

    public function register_settings() {
        register_setting( 'firexcore_webp_options', 'firexcore_webp_quality', array(
            'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_quality' ),
            'default' => 80,
        ) );
        foreach ( array( 'replace_original', 'strip_metadata', 'lossless', 'lazy_loading', 'seo_optimization' ) as $key ) {
            register_setting( 'firexcore_webp_options', 'firexcore_webp_' . $key, array(
                'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ),
            ) );
        }
    }

    public function sanitize_quality( $quality ) {
        return max( 1, min( 100, (int) $quality ) );
    }

    public function sanitize_bool( $value ) {
        return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    }

    public function admin_menu() {
        add_menu_page( __( 'Smart WebP Converter', 'smart-webp-converter' ),
            __( 'Smart WebP Converter', 'smart-webp-converter' ),
            'manage_options', 'smart-webp-converter',
            array( $this, 'dashboard' ), 'dashicons-format-image' );
        add_submenu_page( 'smart-webp-converter',
            __( 'Settings', 'smart-webp-converter' ), __( 'Settings', 'smart-webp-converter' ),
            'manage_options', 'smart-webp-converter-settings', array( $this, 'settings_page' ) );
    }

    public function settings_link( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=smart-webp-converter-settings' ) ) . '">' .
            esc_html__( 'Settings', 'smart-webp-converter' ) . '</a>' );
        return $links;
    }

    public function admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) &&
             ( ! class_exists( 'Imagick' ) || ! Imagick::queryFormats( 'WEBP' ) ) ) {
            echo '<div class="notice notice-warning"><p>' .
                esc_html__( 'Smart WebP Converter: no WebP-capable image editor is available. Images will be left unchanged.', 'smart-webp-converter' ) .
                '</p></div>';
        } elseif ( $this->setting( 'lossless', false ) &&
            ( ! class_exists( 'Imagick' ) || ! Imagick::queryFormats( 'WEBP' ) ) ) {
            echo '<div class="notice notice-warning"><p>' .
                esc_html__( 'True lossless WebP requires ImageMagick WebP support. Conversion will be skipped until available.', 'smart-webp-converter' ) .
                '</p></div>';
        }
    }

    private function totals() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row( "SELECT COUNT(*) AS converted,
            COALESCE(SUM(original_size), 0) AS original_bytes,
            COALESCE(SUM(webp_size), 0) AS webp_bytes
            FROM $table WHERE id IN (SELECT MAX(id) FROM $table GROUP BY attachment_id)", ARRAY_A );
        return $row ? $row : array( 'converted' => 0, 'original_bytes' => 0, 'webp_bytes' => 0 );
    }

    public function dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $totals = $this->totals();
        $original = (int) $totals['original_bytes'];
        $webp = (int) $totals['webp_bytes'];
        $potential = max( 0, $original - $webp );
        $ratio = $original > 0 ? min( 100, round( 100 * $webp / $original ) ) : 0;
        echo '<div class="wrap"><h1>' . esc_html__( 'Smart WebP Converter', 'smart-webp-converter' ) . '</h1>';
        echo '<p>' . esc_html__( 'Statistics show potential transfer savings, not actual disk savings when originals are kept.', 'smart-webp-converter' ) . '</p>';
        echo '<div class="card" style="max-width:800px"><h2>' . esc_html__( 'Conversion statistics', 'smart-webp-converter' ) . '</h2>';
        echo '<p>' . esc_html__( 'Converted attachments', 'smart-webp-converter' ) . ': <strong>' . esc_html( $totals['converted'] ) . '</strong></p>';
        echo '<p>' . esc_html__( 'Original bytes', 'smart-webp-converter' ) . ': ' . esc_html( size_format( $original ) ) . '</p>';
        echo '<p>' . esc_html__( 'WebP bytes', 'smart-webp-converter' ) . ': ' . esc_html( size_format( $webp ) ) . '</p>';
        echo '<p>' . esc_html__( 'Potential transfer reduction', 'smart-webp-converter' ) . ': ' . esc_html( size_format( $potential ) ) . '</p>';
        echo '<div role="img" aria-label="' . esc_attr__( 'WebP size as percentage of original size', 'smart-webp-converter' ) . '" style="height:18px;background:#dcdcde;border-radius:5px;overflow:hidden"><div style="height:100%;background:#2271b1;width:' . esc_attr( $ratio ) . '%"></div></div>';
        echo '</div></div>';
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $options = array(
            'replace_original' => __( 'Replace original on new uploads (destructive)', 'smart-webp-converter' ),
            'strip_metadata' => __( 'Remove EXIF, IPTC and XMP (keep color profiles)', 'smart-webp-converter' ),
            'lossless' => __( 'True lossless (requires Imagick with WebP support)', 'smart-webp-converter' ),
            'lazy_loading' => __( 'Add lazy loading when WordPress has not set it', 'smart-webp-converter' ),
            'seo_optimization' => __( 'Use meaningful attachment titles for empty alt text', 'smart-webp-converter' ),
        );
        echo '<div class="wrap"><h1>' . esc_html__( 'Smart WebP Converter Settings', 'smart-webp-converter' ) . '</h1>';
        echo '<p>' . esc_html__( 'Existing media is not modified. Test replacement on a staging site and back up uploads first.', 'smart-webp-converter' ) . '</p>';
        echo '<form action="options.php" method="post">';
        settings_fields( 'firexcore_webp_options' );
        echo '<table class="form-table"><tr><th><label for="swc-quality">' .
            esc_html__( 'WebP quality', 'smart-webp-converter' ) .
            '</label></th><td><input id="swc-quality" type="number" min="1" max="100" name="firexcore_webp_quality" value="' .
            esc_attr( $this->setting( 'quality', 80 ) ) . '"></td></tr>';
        foreach ( $options as $key => $label ) {
            $name = 'firexcore_webp_' . $key;
            $default = in_array( $key, array( 'strip_metadata', 'lazy_loading', 'seo_optimization' ), true );
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label>';
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
            echo '<input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' .
                checked( $this->setting( $key, $default ), true, false ) . '> ' .
                esc_html__( 'Enabled', 'smart-webp-converter' ) . '</label></td></tr>';
        }
        echo '</table>';
        submit_button();
        echo '</form></div>';
    }
}

new FireXCore_WebP_Converter();
