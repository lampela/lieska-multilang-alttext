<?php
namespace Lieska_MLAT;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'settings' ] );
    }

    public static function menu() {
        add_options_page(
            __( 'Multilang Alt Text', 'lieska-mlat' ),
            __( 'ML Alt Text', 'lieska-mlat' ),
            'manage_options',
            'lieska-mlat',
            [ __CLASS__, 'render_settings' ]
        );
    }

    public static function settings() {
        register_setting( 'lieska_mlat', 'lieska_mlat_settings' );

        add_settings_section(
            'lieska_mlat_main',
            __( 'General', 'lieska-mlat' ),
            '__return_false',
            'lieska_mlat'
        );

        add_settings_field(
            'enabled_locales',
            __( 'Enabled locales (comma-separated, e.g. fi_FI,en_US)', 'lieska-mlat' ),
            [ __CLASS__, 'field_text' ],
            'lieska_mlat',
            'lieska_mlat_main',
            [ 'key' => 'enabled_locales', 'placeholder' => 'fi_FI,en_US' ]
        );
    }

    public static function field_text( $args ) {
        $opts = get_option( 'lieska_mlat_settings', [] );
        $val  = isset( $opts[ $args['key'] ] ) ? esc_attr( $opts[ $args['key'] ] ) : '';
        printf(
            '<input type="text" name="lieska_mlat_settings[%1$s]" value="%2$s" placeholder="%3$s" class="regular-text" />',
            esc_attr( $args['key'] ), $val, esc_attr( $args['placeholder'] )
        );
    }

    public static function render_settings() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Multilang Alt Text (Free)', 'lieska-mlat' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'lieska_mlat' );
        do_settings_sections( 'lieska_mlat' );
        submit_button();
        echo '</form></div>';
    }
}
