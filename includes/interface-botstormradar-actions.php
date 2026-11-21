<?php
/**
 * What the storm state machine asks the outside world to do. In v0.1 the
 * only implementation logs and alerts; v0.2 adds the gate, v0.3 the
 * Cloudflare exporter, v0.4 the rest. Extra implementations are added
 * through the `botstormradar_actions` filter.
 *
 * Every method receives the transition context:
 *   from, to, minute, at, score, explanation, metrics (the minute row).
 * When one minute takes calm through warning to storm, the warning context
 * also carries `continues_to` => 'storm' and the storm context
 * `started_from` => 'calm', so an implementation can act once for the pair.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface BotStormRadar_Actions {

	/**
	 * Handle the move to warning.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function on_warning( array $ctx );

	/**
	 * Handle the move to storm.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function on_storm( array $ctx );

	/**
	 * Handle the move to cooling.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function on_cooling( array $ctx );

	/**
	 * Handle the return to calm.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function on_calm( array $ctx );

	/**
	 * A storm persisted with the current rung engaged; engage the next.
	 *
	 * @param int   $rung 1-based.
	 * @param array $ctx  Context of the state change.
	 */
	public function escalate( $rung, array $ctx );

	/**
	 * Stand every rung down.
	 *
	 * @param array $ctx Context of the state change.
	 */
	public function deescalate( array $ctx );
}
