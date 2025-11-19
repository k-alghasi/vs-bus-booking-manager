<?php
/**
 * Test VSBBM_Blacklist class
 */

class Test_Blacklist extends PHPUnit\Framework\TestCase {

    public function test_class_exists() {
        $this->assertTrue(class_exists('VSBBM_Blacklist'));
    }

    public function test_is_blacklisted_method_exists() {
        $this->assertTrue(method_exists('VSBBM_Blacklist', 'is_blacklisted'));
    }

    public function test_is_blacklisted_returns_boolean() {
        $result = VSBBM_Blacklist::is_blacklisted('1234567890');
        $this->assertIsBool($result);
    }

    public function test_init_method_exists() {
        $this->assertTrue(method_exists('VSBBM_Blacklist', 'init'));
    }

    public function test_create_table_method_exists() {
        $this->assertTrue(method_exists('VSBBM_Blacklist', 'create_table'));
    }
}