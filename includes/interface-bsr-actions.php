<?php
/**
 * What the storm state machine asks the outside world to do. In v0.1 the
 * only implementation logs and alerts; v0.2 adds the gate, v0.3 the
 * Cloudflare exporter, v0.4 the rest. Extra implementations are added
 * through the `bsr_actions` filter.
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

interface BSR_Actions {

	/**
	 * @param array $ctx
	 */
	public function on_warning( array $ctx );

	/**
	 * @param array $ctx
	 */
	public function on_storm( array $ctx );

	/**
	 * @param array $ctx
	 */
	public function on_cooling( array $ctx );

	/**
	 * @param array $ctx
	 */
	public function on_calm( array $ctx );

	/**
	 * A storm persisted with the current rung engaged; engage the next.
	 *
	 * @param int   $rung 1-based.
	 * @param array $ctx
	 */
	public function escalate( $rung, array $ctx );

	/**
	 * Stand every rung down.
	 *
	 * @param array $ctx
	 */
	public function deescalate( array $ctx );
}
