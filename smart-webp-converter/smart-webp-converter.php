<?php
/**
 * Plugin Name: Smart WebP Converter
 * Plugin URI: https://github.com/FireXCore/Smart-Webp-Converter
 * Description: Converts uploads to WebP with non-destructive sidecars by default, optional destructive replacement, and verified media statistics.
 * Version: 1.2.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: FireXCore
 * Author URI: https://firexcore.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-webp-converter
 */

defined( 'ABSPATH' ) || exit;

final class FireXCore_WebP_Converter {
    const VERSION = '1.2.1';
    const TABLE = 'webp_conversion_stats';

    /** @var array<string, int> Original bytes keyed by new WebP upload path. */
    private $replaced_uploads = array();

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'init', array( $this, 'maybe_upgrade' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_link' ) );
        add_filter( 'wp_handle_upload', array( $this, 'handle_upload' ), 20, 2 );
        add_filter( 'wp_handle_sideload', array( $this, 'handle_upload' ), 20, 2 );
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'attachment_metadata' ), 20, 3 );
        add_filter( 'wp_get_attachment_image', array( $this, 'attachment_picture' ), 20, 5 );
        add_filter( 'render_block', array( $this, 'render_image_block' ), 20, 2 );
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'image_attributes' ), 20, 3 );
        add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 20, 2 );
        add_filter( 'manage_media_columns', array( $this, 'media_columns' ) );
        add_action( 'manage_media_custom_column', array( $this, 'media_column_content' ), 10, 2 );
        add_action( 'admin_post_firexcore_webp_generate_one', array( $this, 'generate_existing_attachment' ) );
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

    /**
     * Reports what actually exists on disk; the Media Library MIME remains PNG/JPEG
     * when the safe sidecar mode is selected. No conversion happens on page load.
     */
    private function conversion_status( $attachment_id ) {
        $mime = get_post_mime_type( $attachment_id );
        if ( 'image/webp' === $mime ) {
            return array( 'state' => 'native', 'label' => __( 'WebP original', 'smart-webp-converter' ) );
        }
        if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
            return array( 'state' => 'unsupported', 'label' => __( 'Not a supported source image', 'smart-webp-converter' ) );
        }
        $path = get_attached_file( $attachment_id );
        if ( ! is_string( $path ) || ! is_file( $path ) ) {
            return array( 'state' => 'missing', 'label' => __( 'Original file unavailable', 'smart-webp-converter' ) );
        }
        $target = preg_replace( '/\\.(?:jpe?g|png|gif)$/i', '.webp', $path );
        $sidecars = get_post_meta( $attachment_id, '_firexcore_webp_sidecars', true );
        if ( $target !== $path && is_array( $sidecars ) &&
             in_array( wp_normalize_path( $target ), $sidecars, true ) &&
             $this->valid_webp( $target ) ) {
            $url = $this->webp_url( wp_get_attachment_url( $attachment_id ), $attachment_id );
            return array(
                'state' => 'ready',
                'label' => __( 'WebP sidecar ready; original retained', 'smart-webp-converter' ),
                'url' => $url,
                'bytes' => filesize( $target ),
            );
        }
        return array( 'state' => 'pending', 'label' => __( 'No WebP sidecar detected', 'smart-webp-converter' ) );
    }

    /**
     * Media attachment details use the built-in compatibility fields renderer.
     */
    public function attachment_fields( $fields, $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return $fields;
        }
        $status = $this->conversion_status( $post->ID );
        $text = '<strong>' . esc_html( $status['label'] ) . '</strong>';
        if ( 'ready' === $status['state'] && ! empty( $status['url'] ) ) {
            $text .= ' <a href="' . esc_url( $status['url'] ) . '" target="_blank" rel="noopener noreferrer">' .
                esc_html__( 'View WebP file', 'smart-webp-converter' ) . '</a>';
        }
        if ( in_array( $status['state'], array( 'ready', 'pending' ), true ) &&
             current_user_can( 'manage_options' ) ) {
            $text .= ' <a href="' . esc_url( add_query_arg(
                array( 'page' => 'smart-webp-converter', 'attachment_id' => $post->ID ),
                admin_url( 'admin.php' )
            ) ) . '">' . esc_html__( 'Manage conversion', 'smart-webp-converter' ) . '</a>';
        }
        $fields['firexcore_webp_status'] = array(
            'label' => __( 'WebP conversion', 'smart-webp-converter' ),
            'input' => 'html',
            'html' => '<div class="firexcore-webp-status">' . $text . '</div>',
            'helps' => __( 'In safe mode the Media Library keeps the original PNG/JPEG file; the WebP is separate. Favicons and raw URLs are not automatically rewritten.', 'smart-webp-converter' ),
        );
        return $fields;
    }

    public function media_columns( $columns ) {
        $columns['firexcore_webp'] = __( 'WebP status', 'smart-webp-converter' );
        return $columns;
    }

    public function media_column_content( $column, $attachment_id ) {
        if ( 'firexcore_webp' !== $column ) {
            return;
        }
        $status = $this->conversion_status( $attachment_id );
        echo esc_html( $status['label'] );
    }

    /**
     * Explicit, nonce-protected *non-destructive* regeneration of WebP sidecars.
     * This action never changes the attachment path or MIME type.
     */
    public function generate_existing_attachment() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'smart-webp-converter' ), '', array( 'response' => 403 ) );
        }
        // Authenticate the mutation before accepting any request fields.
        check_admin_referer( 'firexcore_webp_generate_one' );
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ||
             ! current_user_can( 'edit_post', $attachment_id ) ) {
            wp_die( esc_html__( 'Invalid media attachment.', 'smart-webp-converter' ), '', array( 'response' => 400 ) );
        }
        $current = $this->conversion_status( $attachment_id );
        if ( 'pending' === $current['state'] ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );
            $this->attachment_metadata( is_array( $metadata ) ? $metadata : array(), $attachment_id, 'update' );
        }
        $result = $this->conversion_status( $attachment_id );
        $message = in_array( $result['state'], array( 'ready', 'native' ), true ) ? 'converted' : 'unavailable';
        wp_safe_redirect( add_query_arg(
            array( 'page' => 'smart-webp-converter', 'attachment_id' => $attachment_id, 'webp_result' => $message ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    private function dashboard_attachment( $attachment_id ) {
        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ||
             ! current_user_can( 'edit_post', $attachment_id ) ) {
            return;
        }
        $status = $this->conversion_status( $attachment_id );
        echo '<div class="card" style="max-width:800px"><h2>' .
            esc_html__( 'Media conversion status', 'smart-webp-converter' ) . '</h2>';
        echo '<p><strong>' . esc_html( get_the_title( $attachment_id ) ) . '</strong></p>';
        echo '<p>' . esc_html( $status['label'] ) . '</p>';
        if ( 'ready' === $status['state'] && ! empty( $status['url'] ) ) {
            echo '<p><a href="' . esc_url( $status['url'] ) . '" target="_blank" rel="noopener noreferrer">' .
                esc_html__( 'View generated WebP', 'smart-webp-converter' ) . '</a> (' .
                esc_html( size_format( $status['bytes'] ) ) . ')</p>';
        }
        if ( isset( $_GET['webp_result'] ) && 'unavailable' === sanitize_key( wp_unslash( $_GET['webp_result'] ) ) ) {
            echo '<p class="notice notice-warning">' .
                esc_html__( 'Conversion was not completed. Check server WebP support, image size, or file-name conflicts. The original remains unchanged.', 'smart-webp-converter' ) . '</p>';
        }
        if ( 'pending' === $status['state'] ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="firexcore_webp_generate_one">';
            echo '<input type="hidden" name="attachment_id" value="' . esc_attr( $attachment_id ) . '">';
            wp_nonce_field( 'firexcore_webp_generate_one' );
            submit_button( __( 'Generate WebP sidecar now (keep original)', 'smart-webp-converter' ), 'secondary', 'submit', false );
            echo '</form>';
        }
        echo '</div>';
    }

    public function register_settings() {
        register_setting( 'firexcore_webp_options', 'firexcore_webp_quality', array(
            'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_quality' ),
            'default' => 80,
        ) );
        register_setting( 'firexcore_webp_options', 'firexcore_webp_replace_original', array(
            'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_destructive_mode' ),
            'default' => false,
        ) );
        foreach ( array( 'strip_metadata', 'lossless', 'lazy_loading', 'seo_optimization' ) as $key ) {
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

    /**
     * A separate confirmation is required on every save that enables deletion.
     * Prevents accidental activation by the normal settings checkbox alone.
     */
    public function sanitize_destructive_mode( $value ) {
        if ( ! $this->sanitize_bool( $value ) ) {
            return false;
        }
        $confirmed = isset( $_POST['firexcore_webp_confirm_delete'] ) &&
            '1' === sanitize_text_field( wp_unslash( $_POST['firexcore_webp_confirm_delete'] ) );
        if ( ! $confirmed ) {
            add_settings_error( 'firexcore_webp_replace_original', 'confirmation_required',
                __( 'Destructive replacement was NOT enabled. Check the separate deletion-confirmation box to enable it.', 'smart-webp-converter' ), 'error' );
            return false;
        }
        return true;
    }

    public function admin_assets( $hook_suffix ) {
        if ( false === strpos( (string) $hook_suffix, 'smart-webp-converter' ) ) {
            return;
        }
        wp_enqueue_style(
            'firexcore-webp-admin',
            plugins_url( 'assets/admin.css', __FILE__ ),
            array(), self::VERSION
        );
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

    private function stat_cards( $totals ) {
        $original = (int) $totals['original_bytes'];
        $webp = (int) $totals['webp_bytes'];
        $saved = max( 0, $original - $webp );
        $cards = array(
            array( __( 'Converted attachments', 'smart-webp-converter' ), number_format_i18n( (int) $totals['converted'] ), 'dashicons-images-alt2' ),
            array( __( 'Original total', 'smart-webp-converter' ), size_format( $original ), 'dashicons-format-image' ),
            array( __( 'WebP total', 'smart-webp-converter' ), size_format( $webp ), 'dashicons-format-gallery' ),
            array( __( 'Potential bandwidth saving', 'smart-webp-converter' ), size_format( $saved ), 'dashicons-performance' ),
        );
        echo '<div class="swc-stats">';
        foreach ( $cards as $card ) {
            echo '<section class="swc-stat"><span class="dashicons ' . esc_attr( $card[2] ) . '" aria-hidden="true"></span>';
            echo '<span class="swc-stat-number">' . esc_html( $card[1] ) . '</span><span class="swc-stat-label">' . esc_html( $card[0] ) . '</span></section>';
        }
        echo '</div>';
    }

    private function admin_intro( $heading ) {
        echo '<div class="swc-header"><span class="swc-logo dashicons dashicons-format-gallery" aria-hidden="true"></span><div>';
        echo '<h1>' . esc_html( $heading ) . '</h1><p>' .
            esc_html__( 'Real WebP conversions, clear status, and a safe default that retains original images.', 'smart-webp-converter' ) .
            '</p></div></div>';
        echo '<div class="swc-notice swc-notice-info"><span class="dashicons dashicons-info" aria-hidden="true"></span><div><strong>' .
            esc_html__( 'Safe mode preserves the original JPG / PNG.', 'smart-webp-converter' ) .
            '</strong><p>' . esc_html__( 'The media item stays PNG/JPG; a separate .webp file is created and served when compatible. Existing file URLs, site icons and CSS backgrounds are not rewritten.', 'smart-webp-converter' ) . '</p></div></div>';
        echo '<div class="swc-notice swc-notice-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong>' .
            esc_html__( 'Replace Original permanently removes new uploaded source files.', 'smart-webp-converter' ) .
            '</strong><p>' . esc_html__( 'Keep the option off unless you have a verified backup and explicitly confirm deletion. Files previously deleted cannot be recreated from this plugin.', 'smart-webp-converter' ) . '</p></div></div>';
    }

    public function dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $totals = $this->totals();
        echo '<div class="wrap swc-admin">';
        $this->admin_intro( __( 'Smart WebP Converter', 'smart-webp-converter' ) );
        $this->stat_cards( $totals );
        echo '<div class="swc-layout"><section class="swc-panel"><h2><span class="dashicons dashicons-admin-media" aria-hidden="true"></span> ' .
            esc_html__( 'Media conversion status', 'smart-webp-converter' ) . '</h2>';
        $attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;
        if ( $attachment_id ) {
            $this->dashboard_attachment( $attachment_id );
        } else {
            echo '<p>' . esc_html__( 'Most recent image attachments. Values are calculated from actual files, never simulated.', 'smart-webp-converter' ) . '</p>';
            $attachments = get_posts( array(
                'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
                'posts_per_page' => 6, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids',
            ) );
            if ( empty( $attachments ) ) {
                echo '<p>' . esc_html__( 'No uploaded images yet.', 'smart-webp-converter' ) . '</p>';
            } else {
                echo '<ul class="swc-media-list">';
                foreach ( $attachments as $id ) {
                    $status = $this->conversion_status( $id );
                    $url = add_query_arg( array( 'page' => 'smart-webp-converter', 'attachment_id' => $id ), admin_url( 'admin.php' ) );
                    echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $id ) ) .
                        '</a><span class="swc-status swc-status-' . esc_attr( $status['state'] ) . '">' .
                        esc_html( $status['label'] ) . '</span></li>';
                }
                echo '</ul>';
            }
        }
        echo '</section><aside class="swc-panel"><h2><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> ' .
            esc_html__( 'How conversion works', 'smart-webp-converter' ) . '</h2><ol class="swc-how"><li>' .
            esc_html__( 'Upload a JPG or PNG.', 'smart-webp-converter' ) . '</li><li>' .
            esc_html__( 'The plugin generates a separate WebP file.', 'smart-webp-converter' ) . '</li><li>' .
            esc_html__( 'The original stays intact unless destructive replacement is explicitly enabled.', 'smart-webp-converter' ) . '</li><li>' .
            esc_html__( 'Supported image markup uses WebP with original fallback.', 'smart-webp-converter' ) .
            '</li></ol><p><a class="button button-primary" href="' .
            esc_url( admin_url( 'admin.php?page=smart-webp-converter-settings' ) ) . '">' .
            esc_html__( 'Open settings', 'smart-webp-converter' ) . '</a></p></aside></div>';
        echo '<p class="description">' .
            esc_html__( 'Statistics estimate potential transfer savings, not disk savings when originals remain.', 'smart-webp-converter' ) . '</p>';
        echo '</div>';
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $options = array(
            'strip_metadata' => array( __( 'Remove EXIF, IPTC and XMP', 'smart-webp-converter' ), __( 'Remove location/camera metadata while keeping embedded color profiles where supported.', 'smart-webp-converter' ) ),
            'lossless' => array( __( 'True lossless (Imagick required)', 'smart-webp-converter' ), __( 'Use lossless WebP only when ImageMagick supports it.', 'smart-webp-converter' ) ),
            'lazy_loading' => array( __( 'Lazy loading', 'smart-webp-converter' ), __( 'Respect WordPress fetch priority and existing loading attributes.', 'smart-webp-converter' ) ),
            'seo_optimization' => array( __( 'Fill empty alt text from meaningful title', 'smart-webp-converter' ), __( 'Existing alternative text is never overwritten.', 'smart-webp-converter' ) ),
        );
        echo '<div class="wrap swc-admin">';
        $this->admin_intro( __( 'Smart WebP Converter Settings', 'smart-webp-converter' ) );
        settings_errors();
        echo '<div class="swc-layout"><section class="swc-panel swc-settings"><h2><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> ' .
            esc_html__( 'Conversion settings', 'smart-webp-converter' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
        settings_fields( 'firexcore_webp_options' );
        echo '<div class="swc-option"><label for="swc-quality"><strong>' . esc_html__( 'WebP quality', 'smart-webp-converter' ) .
            '</strong></label><div><input id="swc-quality" type="number" min="1" max="100" name="firexcore_webp_quality" value="' .
            esc_attr( $this->setting( 'quality', 80 ) ) . '"><p class="description">' .
            esc_html__( '1–100; higher values typically produce larger files.', 'smart-webp-converter' ) . '</p></div></div>';
        $enabled = (bool) $this->setting( 'replace_original', false );
        echo '<div class="swc-option swc-destructive"><label for="swc-replace"><strong>' .
            esc_html__( 'Replace original on new uploads', 'smart-webp-converter' ) .
            '</strong></label><div><input type="hidden" name="firexcore_webp_replace_original" value="0">' .
            '<label class="swc-toggle"><input id="swc-replace" type="checkbox" name="firexcore_webp_replace_original" value="1" ' .
            checked( $enabled, true, false ) . '><span>' . esc_html__( 'Enable destructive replacement', 'smart-webp-converter' ) .
            '</span></label><p class="description">' .
            esc_html__( 'Deletes the original for future uploads only. Existing originals are not retroactively deleted.', 'smart-webp-converter' ) .
            '</p><label class="swc-confirm"><input type="checkbox" name="firexcore_webp_confirm_delete" value="1"> ' .
            esc_html__( 'I understand that enabling this option permanently deletes newly uploaded source files after successful conversion.', 'smart-webp-converter' ) .
            '</label></div></div>';
        foreach ( $options as $key => $details ) {
            $name = 'firexcore_webp_' . $key;
            $default = in_array( $key, array( 'strip_metadata', 'lazy_loading', 'seo_optimization' ), true );
            echo '<div class="swc-option"><label for="swc-' . esc_attr( $key ) . '"><strong>' . esc_html( $details[0] ) .
                '</strong></label><div><input type="hidden" name="' . esc_attr( $name ) . '" value="0">' .
                '<label class="swc-toggle"><input id="swc-' . esc_attr( $key ) . '" type="checkbox" name="' .
                esc_attr( $name ) . '" value="1" ' . checked( $this->setting( $key, $default ), true, false ) .
                '><span>' . esc_html__( 'Enabled', 'smart-webp-converter' ) . '</span></label><p class="description">' .
                esc_html( $details[1] ) . '</p></div></div>';
        }
        submit_button( __( 'Save settings', 'smart-webp-converter' ) );
        echo '</form></section><aside class="swc-panel swc-aside"><h2><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' .
            esc_html__( 'Media conversion status', 'smart-webp-converter' ) . '</h2><p>' .
            esc_html__( 'Inspect a specific uploaded image to verify its WebP sidecar and size. A PNG still appears as PNG in Media Library when safe mode is enabled.', 'smart-webp-converter' ) .
            '</p><p><a class="button button-secondary" href="' .
            esc_url( admin_url( 'admin.php?page=smart-webp-converter' ) ) . '">' .
            esc_html__( 'View actual conversion results', 'smart-webp-converter' ) .
            '</a></p><h3>' . esc_html__( 'What happens on upload?', 'smart-webp-converter' ) .
            '</h3><ol class="swc-how"><li>' . esc_html__( 'The JPEG/PNG is uploaded.', 'smart-webp-converter' ) .
            '</li><li>' . esc_html__( 'A WebP is generated if supported.', 'smart-webp-converter' ) .
            '</li><li>' . esc_html__( 'With replacement disabled, both files remain.', 'smart-webp-converter' ) .
            '</li><li>' . esc_html__( 'With replacement enabled, the source file is removed after conversion.', 'smart-webp-converter' ) .
            '</li></ol></aside></div></div>';
    }
}

new FireXCore_WebP_Converter();
