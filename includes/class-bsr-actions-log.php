<?php
/**
 * The v0.1 actions: log the transition and send the alert. Nothing is enforced.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Actions_Log implements BSR_Actions {

	/**
	 * A log source (a wiki read from the web-server log) mails once its
	 * baseline holds a full day; before that it only logs (BSR_Sources::alerts_ready).
	 *
	 * @param array $ctx
	 * @return bool
	 */
	private function mails( array $ctx ) {
		return BSR_Sources::alerts_ready( (string) ( $ctx['source'] ?? BSR_Sources::SITE ) );
	}

	public function on_warning( array $ctx ) {
		$this->log( $ctx );
		// A warning that turns into a storm in the same minute is reported by
		// the storm alert alone, unless storm alerts are switched off.
		$merged = 'storm' === ( $ctx['continues_to'] ?? '' ) && BSR_Helpers::opt( 'alert_on_storm', 1 );
		if ( $this->mails( $ctx ) && ! $merged && BSR_Helpers::opt( 'alert_on_warning', 1 ) ) {
			BSR_Email_Alerts::send_transition( $ctx );
		}
	}

	public function on_storm( array $ctx ) {
		$this->log( $ctx );
		if ( $this->mails( $ctx ) && BSR_Helpers::opt( 'alert_on_storm', 1 ) ) {
			BSR_Email_Alerts::send_transition( $ctx );
		}
	}

	public function on_cooling( array $ctx ) {
		$this->log( $ctx );
	}

	public function on_calm( array $ctx ) {
		$this->log( $ctx );
		if ( $this->mails( $ctx ) && 'calm' === $ctx['to'] && in_array( $ctx['from'], [ 'cooling', 'storm' ], true ) && BSR_Helpers::opt( 'alert_on_calm', 1 ) ) {
			BSR_Email_Alerts::send_transition( $ctx );
		}
	}

	public function escalate( $rung, array $ctx ) {
		$this->log( $ctx + [ 'note' => 'escalate rung ' . (int) $rung . ' (stubbed: nothing enforced in v0.1)' ] );
	}

	public function deescalate( array $ctx ) {
		$this->log( $ctx + [ 'note' => 'de-escalate (stubbed: nothing enforced in v0.1)' ] );
	}

	/**
	 * @param array $ctx
	 */
	private function log( array $ctx ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[Bot Storm Radar] %s -> %s at minute %d, score %s: %s%s', $ctx['from'], $ctx['to'], $ctx['minute'], $ctx['score'], $ctx['explanation'], isset( $ctx['note'] ) ? ' [' . $ctx['note'] . ']' : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
