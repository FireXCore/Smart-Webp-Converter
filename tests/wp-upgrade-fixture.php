<?php
/**
 * Validate persistent upgrade data against the published WordPress.org 1.1.0.
 * Run in an isolated WP-CLI WordPress fixture:
 * wp eval-file tests/wp-upgrade-fixture.php seed
 * ... replace activated plugin files with 1.2.0 ...
 * wp eval-file tests/wp-upgrade-fixture.php verify
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit( 1 );
}
$mode = isset( $args[0] ) ? (string) $args[0] : '';
$expect = array(
    'firexcore_webp_quality'          => '63',
    'firexcore_webp_replace_original' => '0',
    'firexcore_webp_strip_metadata'   => '0',
    'firexcore_webp_lossless'         => '1',
    'firexcore_webp_lazy_loading'     => '0',
    'firexcore_webp_seo_optimization' => '1',
);
global $wpdb;
$table = $wpdb->prefix . 'webp_conversion_stats';

function firexcore_upgrade_assert( $valid, $description ) {
    if ( ! $valid ) {
        WP_CLI::error( $description );
    }
    WP_CLI::log( 'PASS: ' . $description );
}
if ( 'seed' === $mode ) {
    $version = get_file_data( WP_PLUGIN_DIR . '/smart-webp-converter/smart-webp-converter.php', array( 'Version' => 'Version' ) );
    firexcore_upgrade_assert( '1.1.0' === $version['Version'], 'fixture runs against actual published version 1.1.0' );
    foreach ( $expect as $key => $value ) {
        update_option( $key, $value );
    }
    $inserted = $wpdb->insert( $table, array(
        'attachment_id' => 987654,
        'original_size' => 234567,
        'webp_size' => 123456,
        'conversion_time' => '2026-01-01 00:00:00',
    ), array( '%d', '%d', '%d', '%s' ) );
    firexcore_upgrade_assert( 1 === $inserted, 'legacy statistics record inserted' );
    WP_CLI::success( 'Legacy settings and statistics seeded' );
} elseif ( 'verify' === $mode ) {
    $version = get_file_data( WP_PLUGIN_DIR . '/smart-webp-converter/smart-webp-converter.php', array( 'Version' => 'Version' ) );
    firexcore_upgrade_assert( '1.2.0' === $version['Version'], 'upgraded plugin version is 1.2.0' );
    foreach ( $expect as $key => $value ) {
        firexcore_upgrade_assert( $value === (string) get_option( $key ), 'option survives upgrade: ' . $key );
    }
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT original_size, webp_size FROM $table WHERE attachment_id = %d", 987654 ), ARRAY_A );
    firexcore_upgrade_assert(
        is_array( $row ) && 234567 === (int) $row['original_size'] && 123456 === (int) $row['webp_size'],
        'published 1.1.0 conversion record survives schema upgrade'
    );
    firexcore_upgrade_assert( '1.2.0' === get_option( 'firexcore_webp_schema_version' ), 'new schema version registered' );
    WP_CLI::success( 'Real 1.1.0 to 1.2.0 persisted-state upgrade passed' );
} else {
    WP_CLI::error( 'Expected seed or verify' );
}
