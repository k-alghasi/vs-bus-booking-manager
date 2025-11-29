<?php
/**
 * VSBBM Elementor Widget: Booking Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class VSBBM_Widget_Booking extends \Elementor\Widget_Base {

    public function get_name() {
        return 'vsbbm_booking_form';
    }

    public function get_title() {
        return __( 'Bus Seat Selection', 'vs-bus-booking-manager' );
    }

    public function get_icon() {
        return 'eicon-seat';
    }

    public function get_categories() {
        return array( 'vsbbm-category' );
    }

    public function get_keywords() {
        return array( 'bus', 'booking', 'seat', 'ticket', 'رزرو' );
    }

    /**
     * تنظیمات ویجت
     */
    protected function register_controls() {

        $this->start_controls_section(
            'content_section',
            array(
                'label' => __( 'Settings', 'vs-bus-booking-manager' ),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            )
        );

        // دریافت لیست محصولات اتوبوس برای انتخاب در لیست
        $options = array();
        $products = get_posts( array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_vsbbm_enable_seat_booking',
                    'value'   => 'yes',
                    'compare' => '='
                )
            )
        ));

        if ( $products ) {
            foreach ( $products as $product ) {
                $options[ $product->ID ] = $product->post_title;
            }
        } else {
            $options[0] = __( 'No Bus Products Found', 'vs-bus-booking-manager' );
        }

        $this->add_control(
            'product_id',
            array(
                'label'   => __( 'Select Bus Product', 'vs-bus-booking-manager' ),
                'type'    => \Elementor\Controls_Manager::SELECT2,
                'options' => $options,
                'default' => key($options), // اولین محصول به عنوان پیش‌فرض
                'label_block' => true,
            )
        );

        $this->end_controls_section();

        // استایل‌دهی
        $this->start_controls_section(
            'style_section',
            array(
                'label' => __( 'Style', 'vs-bus-booking-manager' ),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'container_bg',
            array(
                'label'     => __( 'Background Color', 'vs-bus-booking-manager' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .vsbbm-seat-selector-container' => 'background-color: {{VALUE}}',
                ),
            )
        );

        $this->add_responsive_control(
            'container_padding',
            array(
                'label'      => __( 'Padding', 'vs-bus-booking-manager' ),
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array( 'px', '%', 'em' ),
                'selectors'  => array(
                    '{{WRAPPER}} .vsbbm-seat-selector-container' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ),
            )
        );

        $this->end_controls_section();
    }

    /**
     * رندر خروجی ویجت
     */
    protected function render() {
        $settings = $this->get_settings_for_display();
        $product_id = $settings['product_id'];

        if ( ! $product_id ) {
            echo '<div class="vsbbm-error">' . __( 'Please select a product.', 'vs-bus-booking-manager' ) . '</div>';
            return;
        }

        // اطمینان از لود شدن اسکریپت‌ها
        // چون این متد در وسط صفحه اجرا می‌شود، باید مطمئن شویم اسکریپت‌ها انکیو شده‌اند
        // یا از قبل در هوک wp_enqueue_scripts لود شده‌اند.
        // متد enqueue_frontend_scripts در class-seat-manager معمولا چک می‌کند is_product() باشد.
        // بنابراین باید اینجا دستی اسکریپت‌ها را فراخوانی کنیم اگر در صفحه محصول نیستیم.
        
        if ( class_exists( 'VSBBM_Seat_Manager' ) ) {
            // شبیه‌سازی متغیر سراسری product برای توابع ووکامرس
            global $product, $post;
            $original_product = $product;
            $original_post = $post;

            $product = wc_get_product( $product_id );
            $post = get_post( $product_id );

            if ( $product && VSBBM_Seat_Manager::is_seat_booking_enabled( $product_id ) ) {
                
                // رندر کردن فرم انتخاب صندلی
                VSBBM_Seat_Manager::display_seat_selection();
                
                // اضافه کردن اسکریپت‌ها به فوتر (اگر قبلا لود نشده باشند)
                add_action('wp_footer', function() use ($product_id) {
                    // فراخوانی دستی انکیو اسکریپت‌ها با ID محصول خاص
                    // نکته: ما باید متد enqueue را کمی تغییر دهیم تا ID بگیرد یا اینجا دستی لود کنیم.
                    // ساده‌ترین راه:
                    wp_enqueue_style( 'vsbbm-frontend-css', VSBBM_PLUGIN_URL . 'assets/css/frontend.css', array(), VSBBM_VERSION );
                    wp_enqueue_script( 'vsbbm-frontend-js', VSBBM_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery', 'jquery-ui-datepicker' ), VSBBM_VERSION, true );
                    wp_enqueue_style( 'jquery-ui-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );
                    
                    // لوکالایز کردن مجدد (چون ممکن است تنظیمات این محصول با محصول جاری صفحه فرق کند)
                    // چالش: اگر چند ویجت در صفحه باشد، متغیر vsbbm_ajax_object بازنویسی می‌شود.
                    // راه حل استاندارد: استفاده از data attribute در HTML و خواندن آن در JS.
                    // اما چون JS فعلی ما به vsbbm_ajax_object وابسته است، فعلا برای تک ویجت کار می‌کند.
                    
                    wp_localize_script(
                        'vsbbm-frontend-js',
                        'vsbbm_ajax_object',
                        array(
                            'ajax_url'          => admin_url( 'admin-ajax.php' ),
                            'product_id'        => $product_id, // آی‌دی محصول ویجت
                            'nonce'             => wp_create_nonce( 'vsbbm_seat_nonce' ),
                            'schedule_settings' => VSBBM_Seat_Manager::get_schedule_settings( $product_id ),
                            'current_time'      => current_time( 'timestamp' ),
                            'currency_symbol'   => get_woocommerce_currency_symbol(),
                            'i18n'              => array(
                                'select_departure_date' => __( 'Please select a departure date.', 'vs-bus-booking-manager' ),
                                'seat_reserved'         => __( 'Seat Reserved', 'vs-bus-booking-manager' ),
                                'seat_selected'         => __( 'Selected', 'vs-bus-booking-manager' ),
                                'seat_available'        => __( 'Available', 'vs-bus-booking-manager' ),
                                'fill_passenger_data'   => __( 'Please fill in all passenger details.', 'vs-bus-booking-manager' ),
                                'max_seats_reached'     => __( 'Maximum seat limit reached.', 'vs-bus-booking-manager' ),
                            ),
                        )
                    );
                });

            } else {
                echo '<div class="vsbbm-warning">' . __( 'Product not valid or seat booking disabled.', 'vs-bus-booking-manager' ) . '</div>';
            }

            // بازگردانی متغیرهای سراسری
            $product = $original_product;
            $post = $original_post;
        }
    }
}