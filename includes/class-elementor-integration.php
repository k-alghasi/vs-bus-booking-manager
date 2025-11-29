<?php
/**
 * Class VSBBM_Elementor_Integration
 *
 * Integrates the plugin with Elementor Page Builder.
 *
 * @package VSBBM
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Elementor_Integration {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'elementor/init', array( $this, 'init' ) );
    }

    public function init() {
        // ثبت دسته‌بندی اختصاصی برای ویجت‌ها
        add_action( 'elementor/elements/categories_registered', array( $this, 'register_categories' ) );

        // ثبت ویجت‌ها
        add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
    }

    /**
     * ثبت دسته‌بندی ویجت در پنل المنتور
     */
    public function register_categories( $elements_manager ) {
        $elements_manager->add_category(
            'vsbbm-category',
            array(
                'title' => __( 'Bus Booking Manager', 'vs-bus-booking-manager' ),
                'icon'  => 'fa fa-bus',
            )
        );
    }

    /**
     * ثبت فایل ویجت‌ها
     */
    public function register_widgets( $widgets_manager ) {
        // اطمینان از وجود فایل ویجت
        if ( file_exists( VSBBM_PLUGIN_PATH . 'includes/widgets/class-widget-booking.php' ) ) {
            require_once VSBBM_PLUGIN_PATH . 'includes/widgets/class-widget-booking.php';
            $widgets_manager->register( new VSBBM_Widget_Booking() );
        }
    }
}

VSBBM_Elementor_Integration::get_instance();