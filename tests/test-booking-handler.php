<?php
/**
 * Test VSBBM_Booking_Handler class
 */

class Test_Booking_Handler extends PHPUnit\Framework\TestCase {

    public function test_class_exists() {
        $this->assertTrue(class_exists('VSBBM_Booking_Handler'));
    }

    public function test_init_method_exists() {
        $this->assertTrue(method_exists('VSBBM_Booking_Handler', 'init'));
    }

    public function test_change_add_to_cart_text_method_exists() {
        $this->assertTrue(method_exists('VSBBM_Booking_Handler', 'change_add_to_cart_text'));
    }

    public function test_change_cart_item_quantity_method_exists() {
        $this->assertTrue(method_exists('VSBBM_Booking_Handler', 'change_cart_item_quantity'));
    }

    public function test_add_cart_item_data_method_exists() {
        $this->assertTrue(method_exists('VSBBM_Booking_Handler', 'add_cart_item_data'));
    }

    public function test_display_cart_item_data_method_exists() {
        $this->assertTrue(method_exists('VSBBM_Booking_Handler', 'display_cart_item_data'));
    }
}