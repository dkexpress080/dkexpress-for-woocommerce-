<?php
/**
 * Unit tests: capability checks on every admin AJAX handler.
 *
 * Verifies that each AJAX endpoint refuses non-authorised users with a
 * wp_send_json_error before touching any data.
 *
 * AJAX actions requiring manage_woocommerce:
 *   - dkexpress_courier_create_voucher
 *   - dkexpress_print_voucher
 *   - dkexpress_cancel_voucher
 */

class DKExpressAdminCapabilityTest extends WP_Ajax_UnitTestCase {

    protected function setUp(): void {
        parent::setUp();


        // Seed API credential options to prevent crashes in deeper code paths.
        update_option( 'dkexpress_username', [ '' ] );
        update_option( 'dkexpress_password', [ '' ] );
        update_option( 'dkexpress_api',      [ '' ] );
    }

    /**
     * @dataProvider ajaxActionsProvider
     */
    public function test_ajax_action_requires_manage_woocommerce_capability( string $action ): void {
        // Log in as subscriber (no woocommerce caps)
        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber );

        $_POST['nonce'] = wp_create_nonce( 'dkexpress_admin_nonce' );

        $this->expectException( WPAjaxDieContinueException::class );

        try {
            $this->_handleAjax( $action );
        } catch ( WPAjaxDieContinueException $e ) {
            $response = json_decode( $this->_last_response, true );
            $this->assertFalse( $response['success'], "Action {$action} should return success:false for subscriber" );
            $this->assertNotEmpty( $response['data'], "Action {$action} should include an error message" );
            throw $e;
        }
    }

    public static function ajaxActionsProvider(): array {
        return [
            'print_voucher'  => [ 'dkexpress_print_voucher' ],
            'cancel_voucher' => [ 'dkexpress_cancel_voucher' ],
            'create_voucher' => [ 'dkexpress_courier_create_voucher' ],
        ];
    }

    public function test_ajax_action_allowed_for_shop_manager(): void {
        $manager = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
        wp_set_current_user( $manager );
        get_user_by( 'id', $manager )->add_cap( 'manage_woocommerce' );

        // Create a minimal order so the handler doesn't crash early.
        $order = dkexpress_test_create_order();
        $_POST['nonce']    = wp_create_nonce( 'dkexpress_admin_nonce' );
        $_POST['order_id'] = $order->get_id();

        // We don't reach the API call in unit tests; we just check the response
        // is NOT a capability-denied error.
        try {
            $this->_handleAjax( 'dkexpress_cancel_voucher' );
        } catch ( WPAjaxDieContinueException $e ) {
            $response = json_decode( $this->_last_response, true );
            if ( isset( $response['success'] ) && $response['success'] === false ) {
                $data = $response['data'] ?? '';
                $msg  = is_array( $data ) ? ( $data['message'] ?? '' ) : (string) $data;
                $this->assertStringNotContainsString(
                    'Insufficient permissions',
                    $msg,
                    'Shop manager should not receive permission denied error'
                );
            }
        }

        $this->assertTrue( true, 'No capability error raised for shop_manager' );
    }
}
