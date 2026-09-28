<?php
/**
 * Class responsible for scheduling and un-scheduling events (cron jobs).
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Plugin_Name
 * @subpackage Plugin_Name/includes
 */

/**
 * Class responsible for scheduling and un-scheduling events (cron jobs).
 *
 * This class defines all code necessary to schedule and un-schedule cron jobs.
 *
 * @since      1.0.0
 * @package    Plugin_Name
 * @subpackage Plugin_Name/includes
 * @author     Your Name <email@example.com>
 */
class DKExpress_For_Woocommerce_Cron {

	const DKEXPRESS_CHECK_STATUS = 'dkexpress_voucher_check_status';

	/**
	 * Check if already scheduled, and schedule if not.
	 */
	public static function schedule() {
		if ( ! self::next_scheduled_hourly() ) {
			self::hourly_schedule();
		}else {
			self::hourly_schedule(true);
		}
	}

	/**
	 * Unschedule.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::DKEXPRESS_CHECK_STATUS );
	}

	/**
	 * @return false|int Returns false if not scheduled, or timestamp of next run.
	 */
	private static function next_scheduled_hourly() {
		return wp_next_scheduled( self::DKEXPRESS_CHECK_STATUS );
	}

	/**
	 * Create new schedule.
	 */
	private static function hourly_schedule($reschedule=false) {
		if(version_compare(get_bloginfo('version'),'5.3', '>=') )
			$datetime=new DateTime('now',wp_timezone());
		else
			$datetime=new DateTime('now');

		if ( $reschedule ) {
			wp_clear_scheduled_hook( self::DKEXPRESS_CHECK_STATUS );
		}
		wp_schedule_event( $datetime->getTimestamp(), 'twicedaily', self::DKEXPRESS_CHECK_STATUS );
	}
}