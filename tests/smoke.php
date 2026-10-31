<?php
/**
 * WordPress-free smoke tests. Run: php tests/smoke.php
 * End-to-end WordPress / WooCommerce tests are still required before release.
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/fixture/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['swc_options'] = array();
$GLOBALS['swc_posts'] = array();
$GLOBALS['swc_files'] = array();
$GLOBALS['swc_mimes'] = array();
$GLOBALS['swc_meta'] = array();
$GLOBALS['swc_deleted'] = array();

class SWC_Test_DB {
    public $prefix = 'wp_';
    public $rows = array();
    public function get_var( $query ) {
        preg_match( '/attachment_id\s*=\s*(\d+)/', $query, $m );
        foreach ( $this->rows as $row ) {
            if ( isset( $m[1] ) && (int) $row['attachment_id'] === (int) $m[1] ) {
                return 1;
            }
        }
        return null;
    }
    public function prepare( $query, $id ) {
        return str_replace( '%d', (int) $id, $query );
    }
    public function insert( $table, $values, $format = null ) {
        $this->rows[] = $values;
        return 1;
    }
}
$GLOBALS['wpdb'] = new SWC_Test_DB();

function register_activation_hook( $file, $callback ) {}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function plugin_basename( $file ) { return basename( $file ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
    $GLOBALS['swc_last_settings_error'] = array( $setting, $code, $message, $type );
}
function __( $text, $domain = '' ) { return $text; }

function get_option( $key, $default = false ) { return $GLOBALS['swc_options'][$key] ?? $default; }
function wp_normalize_path( $path ) { return str_replace( chr(92), '/', $path ); }
function wp_delete_file( $path ) { $GLOBALS['swc_deleted'][] = $path; if ( file_exists( $path ) ) unlink( $path ); }
function wp_basename( $path ) { return basename( $path ); }
function wp_unique_filename( $dir, $file ) {
    $i = 0;
    $candidate = $file;
    while ( file_exists( $dir . '/' . $candidate ) ) {
        ++$i;
        $candidate = pathinfo( $file, PATHINFO_FILENAME ) . '-' . $i . '.' . pathinfo( $file, PATHINFO_EXTENSION );
    }
    return $candidate;
}
function get_attached_file( $id ) { return $GLOBALS['swc_files'][$id] ?? false; }
function get_post_mime_type( $id ) { return $GLOBALS['swc_mimes'][$id] ?? ''; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['swc_meta'][$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['swc_meta'][$id][$key] = $value; }
function get_post( $id ) { return $GLOBALS['swc_posts'][$id] ?? null; }
function sanitize_title( $str ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $str ) ); }
function sanitize_text_field( $str ) { return strip_tags( $str ); }
function current_time( $format ) { return '2026-10-31 12:00:00'; }
function wp_get_upload_dir() {
    return array(
        'basedir' => $GLOBALS['swc_test_dir'],
        'baseurl' => 'https://example.test/wp-content/uploads',
        'error' => false,
    );
}
function trailingslashit( $str ) { return rtrim( $str, '/' ) . '/'; }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $object ) { return $object instanceof WP_Error; }
class WP_Error {}
class SWC_Test_Editor {
    private $source;
    public function __construct( $source ) { $this->source = $source; }
    public function supports_mime_type( $mime ) { return 'image/webp' === $mime; }
    public function set_quality( $quality ) {}
    public function save( $target, $mime ) {
        $info = getimagesize( $this->source );
        $image = $info['mime'] === 'image/png' ? imagecreatefrompng( $this->source ) : imagecreatefromjpeg( $this->source );
        $saved = imagewebp( $image, $target, 80 );
        imagedestroy( $image );
        return $saved ? array( 'path' => $target ) : new WP_Error();
    }
}
function wp_get_image_editor( $source ) { return new SWC_Test_Editor( $source ); }

function check( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

require dirname( __DIR__ ) . '/smart-webp-converter/smart-webp-converter.php';
$plugin = new FireXCore_WebP_Converter();

check( $plugin->sanitize_quality( '-10' ) === 1, 'quality lower bound' );
check( $plugin->sanitize_quality( '999' ) === 100, 'quality upper bound' );
check( $plugin->sanitize_bool( '0' ) === false, 'checkbox disabled' );
check( $plugin->sanitize_bool( '1' ) === true, 'checkbox enabled' );
check( $plugin->sanitize_destructive_mode( 0 ) === false, 'destructive option can always be disabled' );
unset( $_POST['firexcore_webp_confirm_delete'] );
check( $plugin->sanitize_destructive_mode( 1 ) === false, 'destructive option requires separate confirmation' );
check( isset( $GLOBALS['swc_last_settings_error'] ), 'destructive confirmation error is visible' );
$_POST['firexcore_webp_confirm_delete'] = '1';
check( $plugin->sanitize_destructive_mode( 1 ) === true, 'explicit confirmation permits destructive mode' );
unset( $_POST['firexcore_webp_confirm_delete'] );


$dir = sys_get_temp_dir() . '/swc-' . bin2hex( random_bytes( 6 ) );
mkdir( $dir, 0700, true );
$GLOBALS['swc_test_dir'] = $dir;
register_shutdown_function( function() use ( $dir ) {
    foreach ( glob( $dir . '/*' ) as $path ) {
        if ( is_file( $path ) ) unlink( $path );
    }
    rmdir( $dir );
} );

if ( ! function_exists( 'imagepng' ) || ! function_exists( 'imagewebp' ) ) {
    echo 'SKIP: image conversion tests (GD/WebP unavailable)' . PHP_EOL;
    exit( 0 );
}
function make_png( $path ) {
    $img = imagecreatetruecolor( 20, 20 );
    imagealphablending( $img, false );
    imagesavealpha( $img, true );
    $transparent = imagecolorallocatealpha( $img, 220, 10, 25, 100 );
    imagefilledrectangle( $img, 0, 0, 19, 19, $transparent );
    imagepng( $img, $path );
    imagedestroy( $img );
}
$path = $dir . '/flower.png';
make_png( $path );
$upload = array( 'file' => $path, 'url' => 'https://example.test/wp-content/uploads/flower.png', 'type' => 'image/png' );
$kept = $plugin->handle_upload( $upload );
check( $kept === $upload && is_file( $path ), 'default upload preserves original' );
$GLOBALS['swc_files'][23] = $path;
$GLOBALS['swc_mimes'][23] = 'image/png';
$GLOBALS['swc_posts'][23] = (object) array( 'post_title' => 'Red Flower' );
$thumb = $dir . '/flower-10x10.png';
make_png( $thumb );
$metadata = array( 'sizes' => array( 'thumbnail' => array( 'file' => basename( $thumb ) ) ) );
check( $plugin->attachment_metadata( $metadata, 23 ) === $metadata, 'attachment metadata remains unchanged' );
check( is_file( $dir . '/flower.webp' ) && is_file( $dir . '/flower-10x10.webp' ), 'original and thumbnail sidecars created' );
check( is_file( $path ) && is_file( $thumb ), 'sidecar mode preserves source and thumbnails' );
check( count( $GLOBALS['wpdb']->rows ) === 1, 'one stats row inserted' );
$plugin->attachment_metadata( $metadata, 23 );
check( count( $GLOBALS['wpdb']->rows ) === 1, 'repeated metadata does not inflate stats' );
$original_html = '<img src="https://example.test/wp-content/uploads/flower.png" srcset="https://example.test/wp-content/uploads/flower-10x10.png 10w, https://example.test/wp-content/uploads/flower.png 20w" sizes="(max-width: 20px) 100vw, 20px" alt="Red Flower">';
$result = $plugin->attachment_picture( $original_html, 23 );
check( strpos( $result, '<picture><source type="image/webp"' ) !== false, 'picture provides WebP source' );
check( strpos( $result, 'flower-10x10.webp 10w' ) !== false, 'responsive WebP srcset' );
check( strpos( $result, $original_html ) !== false, 'picture retains original fallback image' );
check( $plugin->attachment_picture( $result, 23 ) === $result, 'picture is not double wrapped' );
check( strpos( $plugin->render_image_block( $original_html, array( 'blockName' => 'core/image', 'attrs' => array( 'id' => 23 ) ) ), '<picture>' ) !== false, 'core/image block served' );
check( get_post_meta( 23, '_wp_attachment_image_alt', true ) === 'Red Flower', 'editorial alt generated only if absent' );
$plugin->attachment_metadata( $metadata, 23 );
check( get_post_meta( 23, '_wp_attachment_image_alt', true ) === 'Red Flower', 'existing alt preserved' );

$another = $dir . '/example.png';
make_png( $another );
$GLOBALS['swc_options']['firexcore_webp_replace_original'] = true;
$replaced = $plugin->handle_upload( array(
    'file' => $another, 'url' => 'https://example.test/wp-content/uploads/example.png', 'type' => 'image/png',
) );
check( $replaced['type'] === 'image/webp' && is_file( $replaced['file'] ), 'replacement returns usable WebP upload' );
check( ! is_file( $another ), 'replacement removes original only after conversion' );
$GLOBALS['swc_files'][24] = $replaced['file'];
$GLOBALS['swc_mimes'][24] = 'image/webp';
$plugin->attachment_metadata( array(), 24 );
check( count( $GLOBALS['wpdb']->rows ) === 2, 'replacement stats linked to attachment' );
check( $plugin->attachment_picture( '<img src="example.webp">', 24 ) === '<img src="example.webp">', 'native WebP not wrapped' );

$fake = $dir . '/invalid.png';
file_put_contents( $fake, 'not-an-image' );
$failed = $plugin->handle_upload( array(
    'file' => $fake, 'url' => 'https://example.test/wp-content/uploads/invalid.png', 'type' => 'image/png',
) );
check( $failed['type'] === 'image/png' && is_file( $fake ), 'invalid image is not deleted' );

// Two different extensions can share a filename stem; never serve someone else's WebP.
$GLOBALS['swc_options']['firexcore_webp_replace_original'] = false;
$collision = $dir . '/duplicate.png';
make_png( $collision );
copy( $dir . '/flower.webp', $dir . '/duplicate.webp' );
$GLOBALS['swc_files'][25] = $collision;
$GLOBALS['swc_mimes'][25] = 'image/png';
$before = count( $GLOBALS['wpdb']->rows );
$plugin->attachment_metadata( array(), 25 );
check( count( $GLOBALS['wpdb']->rows ) === $before, 'colliding sidecar is not claimed or logged' );
$collision_html = '<img src="https://example.test/wp-content/uploads/duplicate.png">';
check( $plugin->attachment_picture( $collision_html, 25 ) === $collision_html, 'unowned WebP is never served' );

// WordPress may generate a -scaled image before the metadata filter.
$GLOBALS['swc_options']['firexcore_webp_replace_original'] = true;
$big = $dir . '/scaled.png';
make_png( $big );
$upload = $plugin->handle_upload( array(
    'file' => $big, 'url' => 'https://example.test/wp-content/uploads/scaled.png', 'type' => 'image/png',
) );
$scaled = $dir . '/scaled-scaled.webp';
copy( $upload['file'], $scaled );
$GLOBALS['swc_files'][26] = $scaled;
$GLOBALS['swc_mimes'][26] = 'image/webp';
$plugin->attachment_metadata( array( 'original_image' => basename( $upload['file'] ) ), 26 );
check( count( $GLOBALS['wpdb']->rows ) === $before + 1, 'scaled WebP upload retains stats' );

echo 'All smoke tests passed.' . PHP_EOL;
