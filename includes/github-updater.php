<?php
/** GitHub updates through WordPress's native plugin updater. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function cfm_github_plugin_data() {
    $cached = get_site_transient( 'cfm_github_update' );
    if ( false !== $cached ) { return is_array( $cached ) ? $cached : false; }

    $response = wp_remote_get(
        'https://raw.githubusercontent.com/themightymo/content-freeze/main/content-freeze-mode.php',
        array( 'timeout' => 15, 'limit_response_size' => 8192 )
    );
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        set_site_transient( 'cfm_github_update', 'failed', 15 * MINUTE_IN_SECONDS );
        return false;
    }
    $headers = get_file_data_from_cfm_github( wp_remote_retrieve_body( $response ) );
    if ( empty( $headers['Version'] ) || ! preg_match( '/^\d+(?:\.\d+)*(?:[-+][a-zA-Z0-9.-]+)?$/', $headers['Version'] ) ) {
        set_site_transient( 'cfm_github_update', 'failed', 15 * MINUTE_IN_SECONDS );
        return false;
    }
    $data = array(
        'new_version' => $headers['Version'],
        'requires_php' => $headers['Requires PHP'],
        'url' => 'https://github.com/themightymo/content-freeze',
        'package' => 'https://github.com/themightymo/content-freeze/archive/refs/heads/main.zip',
    );
    set_site_transient( 'cfm_github_update', $data, 6 * HOUR_IN_SECONDS );
    return $data;
}

function get_file_data_from_cfm_github( $contents ) {
    $headers = array( 'Version' => '', 'Requires PHP' => '' );
    foreach ( $headers as $header => $value ) {
        if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':[ \t]*(.+)$/mi', substr( $contents, 0, 8192 ), $matches ) ) {
            $headers[ $header ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*$/', '', $matches[1] ) );
        }
    }
    return $headers;
}

function cfm_github_update( $transient ) {
    $plugin = plugin_basename( dirname( __DIR__ ) . '/content-freeze-mode.php' );
    if ( ! is_object( $transient ) || empty( $transient->checked[ $plugin ] ) ) { return $transient; }
    $data = cfm_github_plugin_data();
    if ( ! $data ) { return $transient; }
    $update = (object) array_merge( $data, array( 'slug' => 'content-freeze', 'plugin' => $plugin ) );
    if ( version_compare( $data['new_version'], $transient->checked[ $plugin ], '>' ) ) {
        $transient->response[ $plugin ] = $update;
        unset( $transient->no_update[ $plugin ] );
    } else {
        unset( $transient->response[ $plugin ] );
        $transient->no_update[ $plugin ] = $update;
    }
    return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'cfm_github_update' );

function cfm_github_zip_folder( $source, $remote_source, $upgrader, $hook_extra ) {
    $plugin = plugin_basename( dirname( __DIR__ ) . '/content-freeze-mode.php' );
    if ( empty( $hook_extra['plugin'] ) || $plugin !== $hook_extra['plugin'] ) { return $source; }
    $folder = dirname( $plugin );
    if ( '.' === $folder ) { return $source; }
    $destination = trailingslashit( $remote_source ) . $folder . '/';
    if ( trailingslashit( $source ) === $destination ) { return $source; }
    global $wp_filesystem;
    if ( ! $wp_filesystem || ! $wp_filesystem->exists( trailingslashit( $source ) . 'content-freeze-mode.php' ) ) {
        return new WP_Error( 'cfm_invalid_package', 'The Content Freeze update package is missing its plugin file.' );
    }
    if ( ! $wp_filesystem->move( $source, $destination ) ) {
        return new WP_Error( 'cfm_update_folder', 'Could not prepare the Content Freeze update folder.' );
    }
    return $destination;
}
add_filter( 'upgrader_source_selection', 'cfm_github_zip_folder', 10, 4 );

function cfm_clear_github_update_cache() { delete_site_transient( 'cfm_github_update' ); }
add_action( 'upgrader_process_complete', 'cfm_clear_github_update_cache', 10, 0 );

function cfm_github_force_check() {
    if ( current_user_can( 'update_plugins' ) && isset( $_GET['force-check'] ) && '1' === $_GET['force-check'] ) {
        cfm_clear_github_update_cache();
    }
}
add_action( 'load-update-core.php', 'cfm_github_force_check' );
