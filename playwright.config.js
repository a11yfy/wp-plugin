// Playwright e2e a wp-env "tests" környezete ellen (§13.9).
// Alap: http://localhost:8889 (wp-env tests site, admin/password).
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 60_000,
	// Kis smoke-suite egyetlen wp-env ellen — a párhuzamos loginok flaky-k.
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
		screenshot: 'only-on-failure',
	},
} );
