<?php
/**
 * Plugin Name: Content Freeze Mode
 * Description: Selectively freeze editorial changes while preserving front-end operations.
 * Version: 0.3.3
 * Update URI: https://github.com/themightymo/content-freeze
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: content-freeze-mode
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/includes/github-updater.php';

final class CFM_Content_Freeze {
    const OPTION = 'cfm_options';
    const VERSION = '0.3.3';
    private static $instance;
    public static function boot() { return self::$instance ?: self::$instance = new self(); }
    private function __construct() {
        register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
        add_action( 'init', array( $this, 'maybe_expire' ), 1 );
        add_action( 'cfm_expire_freeze', array( $this, 'maybe_expire' ) );
        add_filter( 'wp_insert_post_data', array( $this, 'post_data_guard' ), 1, 2 );
        add_filter( 'pre_insert_term', array( $this, 'term_insert_guard' ), 1, 2 );
        add_action( 'edit_terms', array( $this, 'term_update_guard' ), 1, 2 );
        add_action( 'pre_delete_term', array( $this, 'term_delete_guard' ), 1, 2 );
        add_filter( 'add_post_metadata', array( $this, 'metadata_add_guard' ), 1, 5 );
        add_filter( 'update_post_metadata', array( $this, 'metadata_update_guard' ), 1, 5 );
        add_filter( 'delete_post_metadata', array( $this, 'metadata_delete_guard' ), 1, 5 );
        add_filter( 'pre_trash_post', array( $this, 'trash_guard' ), 1, 2 );
        add_filter( 'pre_delete_post', array( $this, 'delete_guard' ), 1, 2 );
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_menu', array( $this, 'hide_frozen_add_new' ), 999 );
        add_action( 'admin_init', array( $this, 'save' ), 1 );
        add_action( 'admin_notices', array( $this, 'notice' ) );
        add_action( 'admin_bar_menu', array( $this, 'bar' ), 100 );
        add_action( 'admin_head', array( $this, 'styles' ) );
        add_filter( 'map_meta_cap', array( $this, 'capabilities' ), 999, 4 );
        add_filter( 'rest_pre_dispatch', array( $this, 'rest_guard' ), 9, 3 );
        add_action( 'admin_init', array( $this, 'admin_write_guard' ), 9 );
        add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
        add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
        add_filter( 'bulk_actions-edit-post', array( $this, 'bulk_actions_posts' ) );
        add_filter( 'bulk_actions-edit-page', array( $this, 'bulk_actions_pages' ) );
    }
    public static function activate() {
        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, array( 'enabled' => 1, 'expires' => 0, 'types' => array( 'post', 'page' ), 'settings' => 0, 'woo_settings' => 0, 'gravity_settings' => 0, 'contact' => get_option( 'admin_email' ), 'message' => 'Changes are paused while this site is being staged. Please contact the site team before editing.' ), '', false );
        }
    }
    private function opts() {
        $v = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $v ) ? $v : array(), array( 'enabled' => 1, 'expires' => 0, 'types' => array( 'post', 'page' ), 'settings' => 0, 'woo_settings' => 0, 'gravity_settings' => 0, 'contact' => '', 'message' => '' ) );
    }
    private function frozen( $type ) {
        $o = $this->opts();
        if ( empty( $o['enabled'] ) || ( ! empty( $o['expires'] ) && time() >= (int) $o['expires'] ) ) { return false; }
        if ( in_array( (string) $type, $o['types'], true ) ) { return true; }
        return in_array( (string) $type, array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ), true )
            && ( in_array( 'post', $o['types'], true ) || in_array( 'page', $o['types'], true ) );
    }
    private function any_frozen() { $o = $this->opts(); return ! empty( $o['enabled'] ) && ( empty( $o['expires'] ) || time() < (int) $o['expires'] ) && ! empty( $o['types'] ); }
    public function maybe_expire() {
        $o = $this->opts();
        if ( ! empty( $o['enabled'] ) && ! empty( $o['expires'] ) && time() >= (int) $o['expires'] ) {
            $o['enabled'] = 0; $o['expires'] = 0; update_option( self::OPTION, $o, false );
        }
    }
    private function frozen_taxonomy( $taxonomy ) {
        if ( ! $this->any_frozen() ) { return false; }
        $tax = get_taxonomy( $taxonomy );
        if ( ! $tax ) { return false; }
        foreach ( (array) $tax->object_type as $type ) { if ( $this->frozen( $type ) ) { return true; } }
        return false;
    }
    private function refuse( $what = 'This content is frozen.' ) { wp_die( esc_html( '❄ ' . $what ), 'Content frozen', array( 'response' => 403 ) ); }
    public function post_data_guard( $data, $postarr ) {
        $type = $data['post_type'] ?? ( $postarr['post_type'] ?? 'post' );
        if ( $this->frozen( $type ) ) { $this->refuse( 'Content changes are paused while staging is in progress.' ); }
        return $data;
    }
    public function term_insert_guard( $term, $taxonomy ) {
        return $this->frozen_taxonomy( $taxonomy ) ? new WP_Error( 'cfm_frozen', 'Content Freeze is active. This category, tag, or taxonomy item cannot be added until the freeze is disabled or expires.' ) : $term;
    }
    public function term_update_guard( $term_id, $taxonomy ) {
        // edit_terms is an action: returning WP_Error does not stop the write.
        if ( $this->frozen_taxonomy( $taxonomy ) ) {
            $this->refuse( 'Content Freeze is active. Categories, tags, and taxonomy items cannot be changed until the freeze is disabled or expires. No changes were saved.' );
        }
    }
    public function term_delete_guard( $term_id, $taxonomy ) {
        // pre_delete_term runs before child terms and relationships are changed.
        if ( $this->frozen_taxonomy( $taxonomy ) ) {
            $this->refuse( 'Content Freeze is active. Categories, tags, and taxonomy items cannot be deleted until the freeze is disabled or expires.' );
        }
    }
    public function metadata_add_guard( $check, $id ) { return $this->frozen( get_post_type( $id ) ) ? false : $check; }
    public function metadata_update_guard( $check, $id ) { return $this->frozen( get_post_type( $id ) ) ? false : $check; }
    public function metadata_delete_guard( $check, $id ) { return $this->frozen( get_post_type( $id ) ) ? false : $check; }
    public function trash_guard( $check, $post ) { return $this->frozen( $post->post_type ) ? false : $check; }
    public function delete_guard( $check, $post ) { return $this->frozen( $post->post_type ) ? false : $check; }

    private function can_configure() { return current_user_can( 'manage_options' ); }
    public function menu() { add_options_page( 'Content Freeze Mode', '❄ Content Freeze', 'manage_options', 'content-freeze-mode', array( $this, 'screen' ) ); }
    public function save() {
        if ( empty( $_POST['cfm_save'] ) ) { return; }
        if ( ! $this->can_configure() ) { wp_die( esc_html__( 'You do not have permission to manage the freeze.', 'content-freeze-mode' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( 'cfm_save' );
        $available = array_keys( get_post_types( array( 'show_ui' => true ), 'names' ) );
        $posted = isset( $_POST['cfm_types'] ) && is_array( $_POST['cfm_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['cfm_types'] ) ) : array();
        $types = array_values( array_intersect( $available, $posted ) );
        $old = $this->opts();
        $enabled = ! empty( $_POST['cfm_enabled'] ) ? 1 : 0;
        $hours = isset( $_POST['cfm_hours'] ) ? min( 8760, max( 0, absint( wp_unslash( $_POST['cfm_hours'] ) ) ) ) : 0;
        $expires = $enabled && $hours ? time() + ( $hours * HOUR_IN_SECONDS ) : 0;
        wp_clear_scheduled_hook( 'cfm_expire_freeze' );
        if ( $expires ) { wp_schedule_single_event( $expires, 'cfm_expire_freeze' ); }
        update_option( self::OPTION, array(
            'enabled' => $enabled, 'expires' => $expires,
            'types' => $types,
            'settings' => ! empty( $_POST['cfm_settings'] ) ? 1 : 0,
            'woo_settings' => ! empty( $_POST['cfm_woo_settings'] ) ? 1 : 0,
            'gravity_settings' => ! empty( $_POST['cfm_gravity_settings'] ) ? 1 : 0,
            'contact' => isset( $_POST['cfm_contact'] ) ? sanitize_text_field( wp_unslash( $_POST['cfm_contact'] ) ) : $old['contact'],
            'message' => isset( $_POST['cfm_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cfm_message'] ) ) : $old['message'],
        ), false );
        wp_safe_redirect( add_query_arg( array( 'page' => 'content-freeze-mode', 'saved' => '1' ), admin_url( 'options-general.php' ) ) ); exit;
    }
    public function screen() {
        if ( ! $this->can_configure() ) { return; }
        $o = $this->opts(); $types = get_post_types( array( 'show_ui' => true ), 'objects' );
        echo '<div class="wrap cfm-panel"><div class="cfm-hero"><span class="cfm-snow">❄</span><h1>Content Freeze Mode</h1><p>Keep your staging work undisturbed. Look around all you want—just no snowball fights with the content.</p></div>';
        if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>Freeze settings saved.</p></div>'; }
        echo '<form method="post">'; wp_nonce_field( 'cfm_save' );
        echo '<h2>❄ Freeze status</h2><p><label><input type="checkbox" name="cfm_enabled" value="1" ' . checked( ! empty( $o['enabled'] ), true, false ) . '> Freeze enabled</label></p>';
        echo '<p><label for="cfm_hours">Automatically thaw after this many hours (0 = never)</label><br><input type="number" min="0" max="8760" id="cfm_hours" name="cfm_hours" value="' . esc_attr( ! empty( $o['expires'] ) && $o['expires'] > time() ? (string) ceil( ( $o['expires'] - time() ) / HOUR_IN_SECONDS ) : '0' ) . '"></p>';
        if ( ! empty( $o['expires'] ) ) { echo '<p><strong>Scheduled thaw:</strong> ' . esc_html( wp_date( 'Y-m-d H:i T', $o['expires'] ) ) . '</p>'; }

        echo '<h2>🧊 Frozen content types</h2><p>Checked content types cannot be created, edited, published, trashed, or deleted by normal WordPress editor workflows.</p><div class="cfm-checks">';
        foreach ( $types as $name => $type ) {
            if ( 'attachment' === $name ) { continue; }
            echo '<label><input type="checkbox" name="cfm_types[]" value="' . esc_attr( $name ) . '" ' . checked( in_array( $name, $o['types'], true ), true, false ) . '> ' . esc_html( $type->labels->name ) . ' <code>' . esc_html( $name ) . '</code></label>';
        }
        echo '</div><h2>🧭 Settings screens (limited protection)</h2><p><strong>Important:</strong> These options block writes through recognized admin settings screens. They do not make all plugin APIs, AJAX endpoints, background jobs or database writes read-only.</p>';
        $choices = array( 'settings' => 'Core WordPress options screens', 'woo_settings' => 'WooCommerce settings screens (not checkout/orders)', 'gravity_settings' => 'Gravity Forms admin settings and form configuration screens (not submissions)' );
        foreach ( $choices as $key => $label ) { echo '<p><label><input type="checkbox" name="cfm_' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $o[$key] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
        echo '<h2>📨 The thaw hotline</h2><p><label for="cfm_contact">Who to contact (email or team name)</label><br><input class="regular-text" id="cfm_contact" name="cfm_contact" value="' . esc_attr( $o['contact'] ) . '"></p>';
        echo '<p><label for="cfm_message">Banner message</label><br><textarea class="large-text" rows="3" id="cfm_message" name="cfm_message">' . esc_textarea( $o['message'] ) . '</textarea></p>';
        echo '<p><input type="hidden" name="cfm_save" value="1">'; submit_button( 'Save freeze settings', 'primary', 'submit', false ); echo '</p></form>';
        echo '<p class="description">Emergency release: deactivate this plugin under Plugins or rename its folder via SFTP/SSH. This plugin does not prevent direct database writes.</p></div>';
    }
    public function notice() {
        if ( ! is_admin() || ! $this->any_frozen() ) { return; }
        $o = $this->opts();
        echo '<div class="notice cfm-notice"><span class="cfm-notice-icon">❄</span><div><strong>Content Freeze Mode is active.</strong> ' . esc_html( $o['message'] );
        if ( $o['contact'] ) { echo ' <em>Contact: ' . esc_html( $o['contact'] ) . '.</em>'; }
        if ( $this->can_configure() ) { echo ' <a href="' . esc_url( admin_url( 'options-general.php?page=content-freeze-mode' ) ) . '">Adjust the freeze</a>'; }
        echo '</div></div>';
    }
    public function styles() {
        $screen = get_current_screen();
        if ( $screen && 'edit' === $screen->base && $this->frozen( $screen->post_type ) ) {
            echo '<style>.wrap .page-title-action{display:none}</style>';
        }
        echo '<style>#wpadminbar #wp-admin-bar-cfm-freeze > .ab-item{color:#c7f0ff!important}.cfm-notice{display:flex;gap:12px;align-items:center;border-left:4px solid #56b9e9!important;background:linear-gradient(110deg,#e6f7ff,#f8fdff);padding:12px!important}.cfm-notice-icon{font-size:30px}.cfm-panel{max-width:900px}.cfm-hero{background:linear-gradient(125deg,#086c9b,#88d4ee);color:#fff;border-radius:12px;padding:24px 30px;margin:20px 0;position:relative;overflow:hidden}.cfm-hero h1,.cfm-hero p{color:#fff}.cfm-snow{float:right;font-size:70px;line-height:1}.cfm-checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;max-width:850px}.cfm-checks label{background:#fff;padding:12px;border:1px solid #bedbe8;border-radius:8px}</style>';
    }
    public function bar( $bar ) { if ( $this->any_frozen() && is_admin_bar_showing() ) { $bar->add_node( array( 'id' => 'cfm-freeze', 'title' => '❄ Content frozen', 'href' => $this->can_configure() ? admin_url( 'options-general.php?page=content-freeze-mode' ) : false ) ); } }
    public function capabilities( $caps, $cap, $user_id, $args ) {
        if ( ! $this->any_frozen() ) { return $caps; }
        // Object-level permissions cover classic editor, Gutenberg, REST and many integrations.
        if ( in_array( $cap, array( 'edit_post', 'delete_post', 'read_post', 'publish_post', 'edit_page', 'delete_page' ), true ) && ! empty( $args[0] ) ) {
            $post = get_post( (int) $args[0] );
            if ( $post && $this->frozen( $post->post_type ) && 'read_post' !== $cap ) { return array( 'do_not_allow' ); }
        }
        // Preserve editing primitives needed for menus, lists and private-content reads.
        // Core aliases create_posts to edit_posts; write guards still block creation.
        foreach ( array_unique( array_merge( $this->opts()['types'], array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ) ) ) as $type_name ) {
            if ( ! $this->frozen( $type_name ) ) { continue; }
            $type = get_post_type_object( $type_name );
            if ( ! $type ) { continue; }
            if ( isset( $type->cap->create_posts, $type->cap->edit_posts ) && $type->cap->create_posts !== $type->cap->edit_posts && $cap === $type->cap->create_posts ) {
                return array( 'do_not_allow' );
            }
            foreach ( array( 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts' ) as $field ) {
                if ( isset( $type->cap->$field ) && $cap === $type->cap->$field ) {
                    // Shared primitives (e.g. 'edit_posts') also protect other types by design.
                    return array( 'do_not_allow' );
                }
            }
        }
        return $caps;
    }
    public function rest_guard( $result, $server, $request ) {
        if ( ! $this->any_frozen() || ! is_null( $result ) || 'GET' === $request->get_method() || 'HEAD' === $request->get_method() || 'OPTIONS' === $request->get_method() ) { return $result; }
        $route = $request->get_route();
        foreach ( array_unique( array_merge( $this->opts()['types'], array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ) ) ) as $type_name ) {
            if ( ! $this->frozen( $type_name ) ) { continue; }
            $type = get_post_type_object( $type_name );
            if ( ! $type || ! $type->show_in_rest ) { continue; }
            $base = $type->rest_base ?: $type_name;
            $ns = $type->rest_namespace ?: 'wp/v2';
            if ( preg_match( '#^/' . preg_quote( $ns, '#' ) . '/' . preg_quote( $base, '#' ) . '(?:/|$)#', $route ) ) {
                return new WP_Error( 'cfm_frozen', 'This content type is frozen while staging is in progress.', array( 'status' => 403 ) );
            }
        }
        // Term endpoints, including custom taxonomies attached to frozen post types.
        foreach ( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ) as $taxonomy ) {
            if ( ! $this->frozen_taxonomy( $taxonomy->name ) ) { continue; }
            $ns = $taxonomy->rest_namespace ?: 'wp/v2'; $base = $taxonomy->rest_base ?: $taxonomy->name;
            if ( preg_match( '#^/' . preg_quote( $ns, '#' ) . '/' . preg_quote( $base, '#' ) . '(?:/|$)#', $route ) ) {
                return new WP_Error( 'cfm_frozen', 'Taxonomy changes are frozen.', array( 'status' => 403 ) );
            }
        }
        return $result;
    }
    public function admin_write_guard() {
        if ( ! $this->any_frozen() || ! is_admin() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || isset( $_POST['cfm_save'] ) ) { return; }
        $o = $this->opts(); $script = basename( $_SERVER['SCRIPT_NAME'] ?? '' );
        $page = isset( $_REQUEST['page'] ) && is_string( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
        $core_pages = array( 'options.php', 'options-general.php', 'options-writing.php', 'options-reading.php', 'options-discussion.php', 'options-media.php', 'options-permalink.php', 'options-privacy.php' );
        // Block common AJAX mutations targeting frozen posts and their attached terms.
        if ( 'admin-ajax.php' === $script ) {
            $action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
            $target = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : ( isset( $_REQUEST['post_ID'] ) ? absint( $_REQUEST['post_ID'] ) : 0 );
            if ( $target && $this->frozen( get_post_type( $target ) ) ) { $this->refuse( 'This item cannot be changed through AJAX.' ); }
            // Core's add-tag handler turns term_insert_guard's WP_Error into the XML
            // response tags.js displays beside the form. A 403 here fails silently.
            if ( in_array( $action, array( 'delete-tag', 'inline-save-tax' ), true ) && isset( $_REQUEST['taxonomy'] ) && $this->frozen_taxonomy( sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) ) ) ) { $this->refuse( 'This taxonomy is frozen.' ); }
        }
        $block = ( ! empty( $o['settings'] ) && in_array( $script, $core_pages, true ) )
            || ( ! empty( $o['woo_settings'] ) && 'admin.php' === $script && in_array( $page, array( 'wc-settings', 'wc-admin' ), true ) && ( 'wc-settings' === $page || ( isset( $_GET['path'] ) && false !== strpos( (string) wp_unslash( $_GET['path'] ), '/settings' ) ) ) )
            || ( ! empty( $o['gravity_settings'] ) && 'admin.php' === $script && ( 0 === strpos( $page, 'gf_' ) || in_array( $page, array( 'gf_settings', 'gf_edit_forms', 'gf_form_settings' ), true ) ) );
        if ( $block ) { wp_die( esc_html__( '❄ This settings screen is frozen. No changes were saved.', 'content-freeze-mode' ), 'Content frozen', array( 'response' => 403, 'back_link' => true ) ); }
    }
    public function hide_frozen_add_new() {
        foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
            if ( ! $this->frozen( $type->name ) ) { continue; }
            $parent = 'post' === $type->name ? 'edit.php' : 'edit.php?post_type=' . $type->name;
            $new = 'post' === $type->name ? 'post-new.php' : 'post-new.php?post_type=' . $type->name;
            remove_submenu_page( $parent, $new );
        }
    }
    public function row_actions( $actions, $post ) {
        if ( $this->frozen( $post->post_type ) ) { foreach ( array( 'edit', 'inline hide-if-no-js', 'trash', 'untrash', 'delete' ) as $key ) { unset( $actions[$key] ); } }
        return $actions;
    }
    public function bulk_actions_posts( $actions ) { return $this->frozen( 'post' ) ? array() : $actions; }
    public function bulk_actions_pages( $actions ) { return $this->frozen( 'page' ) ? array() : $actions; }
}
CFM_Content_Freeze::boot();
