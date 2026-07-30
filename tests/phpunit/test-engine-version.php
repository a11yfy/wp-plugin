<?php
/**
 * PHP ↔ JS engine version sync gate.
 *
 * A drift between the two makes scan_batch() treat every stored verdict as
 * stale forever: the whole library is re-downloaded and re-scanned in the
 * browser on every admin page load (the 0.5.0/0.8.0 incident, 2026-07-30).
 *
 * @package a11yfy
 */

class Test_A11yfy_Engine_Version extends WP_UnitTestCase {

	public function test_php_engine_version_matches_js_engine_version() {
		$version_js = dirname( __DIR__, 2 ) . '/js/src/version.js';
		$this->assertFileExists( $version_js );

		$source = file_get_contents( $version_js );
		$this->assertSame(
			1,
			preg_match( "/ENGINE_VERSION\s*=\s*'([^']+)'/", $source, $matches ),
			'js/src/version.js must export ENGINE_VERSION as a single-quoted literal.'
		);

		$this->assertSame(
			$matches[1],
			A11yfy_Admin::ENGINE_VERSION,
			'A11yfy_Admin::ENGINE_VERSION must match js/src/version.js — a drift re-scans the whole library on every admin page load.'
		);
	}
}
