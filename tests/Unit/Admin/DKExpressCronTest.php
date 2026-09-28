<?php
/**
 * Unit tests: cron scheduling helpers.
 *
 * DK Express cron class: DKExpress_For_Woocommerce_Cron
 * Hook constant:       DKEXPRESS_CHECK_STATUS = 'dkexpress_voucher_check_status'
 * Schedule:            twicedaily
 */

class DKExpressCronTest extends WP_UnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        // Clean cron table before each test.
        DKExpress_For_Woocommerce_Cron::unschedule();
    }

    protected function tearDown(): void {
        DKExpress_For_Woocommerce_Cron::unschedule();
        parent::tearDown();
    }

    public function test_schedule_registers_check_status_hook(): void {
        DKExpress_For_Woocommerce_Cron::schedule();

        $this->assertNotFalse(
            wp_next_scheduled( DKExpress_For_Woocommerce_Cron::DKEXPRESS_CHECK_STATUS ),
            'Check-status cron hook should be scheduled after schedule()'
        );
    }

    public function test_unschedule_removes_check_status_hook(): void {
        DKExpress_For_Woocommerce_Cron::schedule();
        DKExpress_For_Woocommerce_Cron::unschedule();

        $this->assertFalse(
            wp_next_scheduled( DKExpress_For_Woocommerce_Cron::DKEXPRESS_CHECK_STATUS ),
            'Check-status cron hook should be removed after unschedule()'
        );
    }

    public function test_schedule_is_idempotent(): void {
        DKExpress_For_Woocommerce_Cron::schedule();
        $ts1 = wp_next_scheduled( DKExpress_For_Woocommerce_Cron::DKEXPRESS_CHECK_STATUS );

        DKExpress_For_Woocommerce_Cron::schedule();
        $ts2 = wp_next_scheduled( DKExpress_For_Woocommerce_Cron::DKEXPRESS_CHECK_STATUS );

        // After double call the hook must still be present (reschedule behaviour).
        $this->assertNotFalse( $ts2, 'Cron should still be scheduled after double-call to schedule()' );
    }

    public function test_cron_hook_constant_is_a_string(): void {
        $this->assertIsString(
            DKExpress_For_Woocommerce_Cron::DKEXPRESS_CHECK_STATUS
        );
    }

    public function test_cron_hook_constant_value(): void {
        $this->assertSame(
            'dkexpress_voucher_check_status',
            DKExpress_For_Woocommerce_Cron::DKEXPRESS_CHECK_STATUS
        );
    }

    public function test_scheduled_event_uses_twicedaily_recurrence(): void {
        DKExpress_For_Woocommerce_Cron::schedule();

        $hook = DKExpress_For_Woocommerce_Cron::DKEXPRESS_CHECK_STATUS;
        $ts   = wp_next_scheduled( $hook );
        $this->assertNotFalse( $ts, 'Cron must be scheduled' );

        $events = _get_cron_array();
        $found  = false;
        foreach ( $events as $timestamp => $hooks ) {
            if ( isset( $hooks[ $hook ] ) ) {
                foreach ( $hooks[ $hook ] as $event ) {
                    if ( isset( $event['schedule'] ) && $event['schedule'] === 'twicedaily' ) {
                        $found = true;
                    }
                }
            }
        }
        $this->assertTrue( $found, 'Scheduled event should use twicedaily recurrence' );
    }
}
