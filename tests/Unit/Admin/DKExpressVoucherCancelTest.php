<?php
/**
 * Unit tests: dkexpress_cancel_voucher AJAX handler.
 *
 * The cancel handler:
 *  1. Verifies nonce and manage_woocommerce capability.
 *  2. Calls the DK Express API (Void endpoint) — we don't test the HTTP call itself.
 *  3. Clears these order meta keys:
 *       _webexpert_order_tracking_carrier
 *       we_voucher_job_id
 *       dkexpress_parcels
 *       dkexpress_cod
 *       dkexpress_weight
 *       dkexpress_comments
 *       dkexpress_special_cases
 *       _shipping_tracking_number
 *  4. Sets job post_meta 'webexpert_voucher_job_status' = 'we-voucher-cancelled'.
 */

class DKExpressVoucherCancelTest extends WP_Ajax_UnitTestCase {

    private WC_Order $order;
    private int $job_id;

    protected function setUp(): void {
        parent::setUp();

        // Log in as shop manager with manage_woocommerce.
        $manager = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
        wp_set_current_user( $manager );
        get_user_by( 'id', $manager )->add_cap( 'manage_woocommerce' );


        // Seed API credential options so array access inside the handler doesn't fatal.
        update_option( 'dkexpress_username', [ 'testuser' ] );
        update_option( 'dkexpress_password', [ 'testpass' ] );
        update_option( 'dkexpress_api',      [ 'testapikey' ] );

        // Create an order with dkexpress voucher meta.
        $this->order = dkexpress_test_create_order();

        $this->job_id = wp_insert_post( [
            'post_title'  => 'Test DK Express Job',
            'post_status' => 'publish',
            'post_type'   => 'we_voucher_job',
        ] );

        // Seed the post meta that the cancel handler reads.
        update_post_meta( $this->job_id, 'dkexpress_job_id',              'DKE-TEST-001' );
        update_post_meta( $this->job_id, 'dkexpress_account',          0 );
        update_post_meta( $this->job_id, 'dkexpress_username',            'testuser' );
        update_post_meta( $this->job_id, 'dkexpress_password',            'testpass' );
        update_post_meta( $this->job_id, 'dkexpress_api',                 'testapikey' );
        update_post_meta( $this->job_id, 'voucher_id',                   'VOUCHERDKE001' );
        update_post_meta( $this->job_id, 'webexpert_voucher_job_status', 'we-voucher-open' );

        // Seed order meta that should be cleared after cancel.
        $this->order->update_meta_data( 'we_voucher_job_id',                  $this->job_id );
        $this->order->update_meta_data( '_shipping_tracking_number',          'VOUCHERDKE001' );
        $this->order->update_meta_data( '_webexpert_order_tracking_carrier',  'dkexpress' );
        $this->order->update_meta_data( 'dkexpress_parcels',                   1 );
        $this->order->update_meta_data( 'dkexpress_weight',                    '2.5' );
        $this->order->update_meta_data( 'dkexpress_cod',                       '0' );
        $this->order->update_meta_data( 'dkexpress_comments',                  'Leave at door' );
        $this->order->update_meta_data( 'dkexpress_special_cases',             '' );
        $this->order->save();
    }

    /**
     * After a successful cancel, all dkexpress order meta must be cleared.
     *
     * Note: the handler makes an HTTP call to the DK Express API but then always
     * proceeds to clear meta regardless of API response. We intercept by
     * filtering wp_remote_post to return a stub 200 response.
     */
    public function test_cancel_clears_all_voucher_meta_from_order(): void {
        // Stub the outbound HTTP call so no real network request is made.
        add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) {
            if ( strpos( $url, 'dkexpress' ) !== false ) {
                return [
                    'headers'  => [],
                    'body'     => '{"Result":"Success"}',
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
            return $preempt;
        }, 10, 3 );

        $_POST['nonce']    = wp_create_nonce( 'dkexpress_admin_nonce' );
        $_POST['order_id'] = $this->order->get_id();

        try {
            $this->_handleAjax( 'dkexpress_cancel_voucher' );
        } catch ( WPAjaxDieContinueException $e ) {
            // expected
        }

        remove_all_filters( 'pre_http_request' );

        $response = json_decode( $this->_last_response, true );
        $this->assertArrayHasKey( 'success', $response );
        $this->assertTrue( (bool) $response['success'], 'Cancel should return success:true' );

        // Reload order and confirm meta is cleared.
        $order = wc_get_order( $this->order->get_id() );
        $this->assertEmpty( $order->get_meta( 'we_voucher_job_id' ),                 'we_voucher_job_id should be cleared' );
        $this->assertEmpty( $order->get_meta( '_shipping_tracking_number' ),         '_shipping_tracking_number should be cleared' );
        $this->assertEmpty( $order->get_meta( '_webexpert_order_tracking_carrier' ), '_webexpert_order_tracking_carrier should be cleared' );
        $this->assertEmpty( $order->get_meta( 'dkexpress_parcels' ),                  'dkexpress_parcels should be cleared' );
        $this->assertEmpty( $order->get_meta( 'dkexpress_weight' ),                   'dkexpress_weight should be cleared' );
        $this->assertEmpty( $order->get_meta( 'dkexpress_cod' ),                      'dkexpress_cod should be cleared' );
        $this->assertEmpty( $order->get_meta( 'dkexpress_comments' ),                 'dkexpress_comments should be cleared' );
    }

    /**
     * After cancel, the job post should be marked as cancelled.
     */
    public function test_cancel_marks_job_as_cancelled(): void {
        add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) {
            if ( strpos( $url, 'dkexpress' ) !== false ) {
                return [
                    'headers'  => [],
                    'body'     => '{"Result":"Success"}',
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
            return $preempt;
        }, 10, 3 );

        $_POST['nonce']    = wp_create_nonce( 'dkexpress_admin_nonce' );
        $_POST['order_id'] = $this->order->get_id();

        try {
            $this->_handleAjax( 'dkexpress_cancel_voucher' );
        } catch ( WPAjaxDieContinueException $e ) {
            // expected
        }

        remove_all_filters( 'pre_http_request' );

        $status = get_post_meta( $this->job_id, 'webexpert_voucher_job_status', true );
        $this->assertSame( 'we-voucher-cancelled', $status, 'Job status should be we-voucher-cancelled' );
    }

    /**
     * When the order does not exist, the handler should not return success:true
     * or should simply not respond (no crash).
     */
    public function test_cancel_with_invalid_order_does_not_crash(): void {
        $_POST['nonce']    = wp_create_nonce( 'dkexpress_admin_nonce' );
        $_POST['order_id'] = 999999; // non-existent

        try {
            $this->_handleAjax( 'dkexpress_cancel_voucher' );
        } catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) {
            // acceptable
        }

        $this->assertTrue( true, 'No fatal error on invalid order ID' );
    }

    /**
     * Capability check: subscriber must not be able to cancel.
     */
    public function test_cancel_requires_manage_woocommerce(): void {
        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber );

        $_POST['nonce']    = wp_create_nonce( 'dkexpress_admin_nonce' );
        $_POST['order_id'] = $this->order->get_id();

        $this->expectException( WPAjaxDieContinueException::class );

        try {
            $this->_handleAjax( 'dkexpress_cancel_voucher' );
        } catch ( WPAjaxDieContinueException $e ) {
            $response = json_decode( $this->_last_response, true );
            $this->assertFalse( $response['success'], 'Subscriber should receive success:false' );
            throw $e;
        }
    }

    /**
     * Nonce check: missing nonce must die.
     */
    public function test_cancel_requires_valid_nonce(): void {
        $_POST = [ 'order_id' => $this->order->get_id() ]; // no nonce

        $caught = false;
        try {
            $this->_handleAjax( 'dkexpress_cancel_voucher' );
        } catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) {
            $caught = true;
        }

        $this->assertTrue( $caught, 'Handler must die when nonce is missing' );
    }
}
