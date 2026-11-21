<?php
/**
 * The v0.1 actions: log the transition and send the alert. Nothing is enforced.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default handler of storm state changes: logs each one and sends the alert mails.
 */
class BotStormRadar_Actions_Log implements BotStormRadar_Actions {

	/**
	 * A log source (a wiki read from the web-server log) mails once its baseline holds a full day; before that it only logs (BotStormRadar_Sources::alerts_ready).
	 *
	 * @param array $ctx Context of the state change.
	 * @return bool
	 */
	private function mails( array $ctx ) {
		return BotStormRadar_Sources::alerts_ready( (string) ( $ctx['source'] ?? BotStormRadar_Sources::SITE ) );
	}

	/**
	 * Log the move to warning and send the warning alert, unless the storm alert covers it.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function on_warning( array $ctx ) {
		$this->log( $ctx );
		// A warning that turns into a storm in the same minute is reported by
		// the storm alert alone, unless storm alerts are switched off.
		$merged = 'storm' === ( $ctx['continues_to'] ?? '' ) && BotStormRadar_Helpers::opt( 'alert_on_storm', 1 );
		if ( $this->mails( $ctx ) && ! $merged && BotStormRadar_Helpers::opt( 'alert_on_warning', 1 ) ) {
			BotStormRadar_Email_Alerts::send_transition( $ctx );
		}
	}

	/**
	 * Log the move to storm and send the storm alert.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function on_storm( array $ctx ) {
		$this->log( $ctx );
		if ( $this->mails( $ctx ) && BotStormRadar_Helpers::opt( 'alert_on_storm', 1 ) ) {
			BotStormRadar_Email_Alerts::send_transition( $ctx );
		}
	}

	/**
	 * Log the move to cooling.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function on_cooling( array $ctx ) {
		$this->log( $ctx );
	}

	/**
	 * Log the return to calm and send the all-clear alert.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function on_calm( array $ctx ) {
		$this->log( $ctx );
		if ( $this->mails( $ctx ) && 'calm' === $ctx['to'] && in_array( $ctx['from'], [ 'cooling', 'storm' ], true ) && BotStormRadar_Helpers::opt( 'alert_on_calm', 1 ) ) {
			BotStormRadar_Email_Alerts::send_transition( $ctx );
		}
	}

	/**
	 * Log an escalation step. Nothing is enforced at this version.
	 *
	 * @param int   $rung Number of the escalation step.
	 * @param array $ctx  Context of the state change.
	 */
	public function escalate( $rung, array $ctx ) {
		$this->log( $ctx + [ 'note' => 'escalate rung ' . (int) $rung . ' (stubbed: nothing enforced in v0.1)' ] );
	}

	/**
	 * Log a de-escalation step. Nothing is enforced at this version.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function deescalate( array $ctx ) {
		$this->log( $ctx + [ 'note' => 'de-escalate (stubbed: nothing enforced in v0.1)' ] );
	}

	/**
	 * Write a state change to the PHP error log when WP_DEBUG is on.
	 *
	 * @param array $ctx Context of the state change.
	 */
	private function log( array $ctx ) {
		// github-build-only:start.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[Bot Storm Radar] %s -> %s at minute %d, score %s: %s%s', $ctx['from'], $ctx['to'], $ctx['minute'], $ctx['score'], $ctx['explanation'], isset( $ctx['note'] ) ? ' [' . $ctx['note'] . ']' : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		// github-build-only:end.
	}
}
