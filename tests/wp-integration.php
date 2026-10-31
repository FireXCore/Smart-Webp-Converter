<?php
/**
 * Runs inside a real WP-CLI WordPress installation:
 * wp eval-file tests/wp-integration.php
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function swc_integration_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function swc_integration_upload( $name ) {
    $tmp = wp_tempnam( $name );
    swc_integration_assert( $tmp !== false, 'temporary file created' );
    $img = imagecreatetruecolor( 800, 600 );
    imagealphablending( $img, false );
    imagesavealpha( $img, true );
    $color = imagecolorallocatealpha( $img, 50, 130, 200, 75 );
    imagefilledrectangle( $img, 0, 0, 799, 599, $color );
    imagepng( $img, $tmp );
    imagedestroy( $img );

    $id = media_handle_sideload( array(
        'name' => $name,
        'tmp_name' => $tmp,
        'size' => filesize( $tmp ),
        'error' => 0,
        'type' => 'image/png',
    ), 0, 'Integration Test Image' );
    if ( is_wp_error( $id ) ) {
        fwrite( STDERR, 'FAIL: ' . $id->get_error_message() . PHP_EOL );
        exit( 1 );
    }
    return $id;
}

update_option( 'firexcore_webp_replace_original', false );
update_option( 'firexcore_webp_lossless', false );
$id = swc_integration_upload( 'test-keep-original.png' );
$original = get_attached_file( $id );
$sidecar = preg_replace( '/\.png$/', '.webp', $original );
swc_integration_assert( 'image/png' === get_post_mime_type( $id ), 'non-destructive upload MIME unchanged' );
swc_integration_assert( is_file( $original ) && is_file( $sidecar ), 'original and WebP sidecar present' );
$meta = wp_get_attachment_metadata( $id );
swc_integration_assert( is_array( $meta ), 'media metadata generated' );
if ( isset( $meta['sizes'] ) ) {
    foreach ( $meta['sizes'] as $size ) {
        $webp = preg_replace( '/\.png$/', '.webp', dirname( $original ) . '/' . $size['file'] );
        swc_integration_assert( is_file( $webp ), 'size sidecar present: ' . $size['file'] );
    }
}
$html = wp_get_attachment_image( $id, 'medium' );
swc_integration_assert( strpos( $html, '<picture>' ) !== false, 'WordPress attachment renders picture' );
swc_integration_assert( strpos( $html, 'image/webp' ) !== false, 'WebP source included' );
swc_integration_assert( strpos( $html, '.png' ) !== false, 'original PNG fallback included' );

update_option( 'firexcore_webp_replace_original', true );
$replacement_id = swc_integration_upload( 'test-replace-original.png' );
$newpath = get_attached_file( $replacement_id );
swc_integration_assert( 'image/webp' === get_post_mime_type( $replacement_id ), 'replacement attachment MIME is WebP' );
swc_integration_assert( is_file( $newpath ) && preg_match( '/\.webp$/', $newpath ), 'replacement file is existing WebP' );
swc_integration_assert( ! is_file( preg_replace( '/\.webp$/', '.png', $newpath ) ), 'replaced original was removed after valid conversion' );
$replaced_meta = wp_get_attachment_metadata( $replacement_id );
swc_integration_assert( is_array( $replaced_meta ), 'replacement has valid WordPress metadata' );
if ( isset( $replaced_meta['sizes'] ) ) {
    foreach ( $replaced_meta['sizes'] as $size ) {
        swc_integration_assert( 'image/webp' === $size['mime-type'], 'replacement thumbnail MIME is WebP' );
        swc_integration_assert( is_file( dirname( $newpath ) . '/' . $size['file'] ), 'replacement thumbnail file exists' );
    }
}
$html_webp = wp_get_attachment_image( $replacement_id, 'medium' );
swc_integration_assert( false !== strpos( $html_webp, '.webp' ), 'replacement image URL is WebP' );
echo 'Real WordPress integration checks passed.' . PHP_EOL;
