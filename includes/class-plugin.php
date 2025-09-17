<?php
namespace Lieska_MLAT;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        load_plugin_textdomain( 'lieska-mlat', false, dirname( plugin_basename( LIESKA_MLAT_FILE ) ) . '/languages' );
        if ( is_admin() ) {
            require_once LIESKA_MLAT_DIR . 'admin/class-admin.php';
            Admin::init();
        }
        // Tuleva: julkiset hookit, generaattorin logiikka (Free vs Pro)
    }
}
