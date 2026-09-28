<?php
/**
 * Unit tests: nonce verification on every admin AJAX handler.
 *
 * Every handler must reject requests that carry no nonce or an invalid one.
 * Nonce action: 'dkexpress_admin_nonce'
 */

class DKExpressAdminNonceTest extends WP_Ajax_UnitTestCase {

    protected function setUp(): void {
        parent::setUp();

        // Log in as administrator so capability checks pass — we are testing
        // the nonce layer specifically.
        $admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin_id );
        // Grant manage_woocommerce so capability check passes.
        $user = get_user_by( 'id', $admin_id );
        $user->add_cap( 'manage_woocommerce' );

    }

    /**
     * @dataProvider ajaxActionsProvider
     */
    public function test_missing_nonce_is_rejected( string $action ): void {
        $_POST = [];   // no nonce at all

        $caught = false;
        try {
            $this->_handleAjax( $action );
        } catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) {
            $caught = true;
        }

        $this->assertTrue( $caught, "Action {$action} must die when nonce is missing" );
    }

    /**
     * @dataProvider ajaxActionsProvider
     */
    public function test_invalid_nonce_is_rejected( string $action ): void {
        $_POST['nonce'] = 'totally_invalid_nonce_value';

        $caught = false;
        try {
            $this->_handleAjax( $action );
        } catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) {
            $caught = true;
        }

        $this->assertTrue( $caught, "Action {$action} must die when nonce is invalid" );
    }

    /**
     * @dataProvider ajaxActionsProvider
     */
    public function test_valid_nonce_passes_nonce_layer( string $action ): void {
        $_POST['nonce']    = wp_create_nonce( 'dkexpress_admin_nonce' );
        $_POST['order_id'] = 0;  // likely invalid order, but nonce should pass

        try {
            $this->_handleAjax( $action );
        } catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) {
            // any die with a valid nonce is acceptable
        }

        // Make sure response is not -1 (nonce fail)
        $this->assertStringNotContainsString(
            '-1',
            $this->_last_response,
            "Action {$action} must not return -1 (nonce failure) with a valid nonce"
        );
    }

    public static function ajaxActionsProvider(): array {
        return [
            [ 'dkexpress_courier_create_voucher' ],
            [ 'dkexpress_print_voucher' ],
            [ 'dkexpress_cancel_voucher' ],
        ];
    }
}
