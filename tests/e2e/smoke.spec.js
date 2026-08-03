/**
 * Admin smoke e2e: a plugin oldalai kulcs nélkül is működnek (free scan),
 * a connect-teszt csak A11YFY_TEST_API_KEY jelenlétében fut (sandbox, nem billed).
 */
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'node:child_process' );
const path = require( 'node:path' );

// A "disconnected" tesztek alapállapotot feltételeznek — a visitor e2e
// fixture (vagy egy korábbi futás) bekötött állapotot hagyhat maga után.
test.beforeAll( () => {
	execSync( 'npx wp-env run tests-cli wp option delete a11yfy_api_key || true', {
		cwd: path.resolve( __dirname, '..', '..' ),
		stdio: 'ignore',
	} );
} );

async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', process.env.WP_ADMIN_USER || 'admin' );
	await page.fill( '#user_pass', process.env.WP_ADMIN_PASS || 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test.beforeEach( async ( { page } ) => {
	await login( page );
} );

test( 'dashboard renders with the free scan CTA and status filters', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=a11yfy' );
	await expect( page ).toHaveTitle( /^a11yfy — / );
	await expect( page.locator( 'h1' ) ).toContainText( 'a11yfy' );
	await expect( page.locator( '#a11yfy-scan-btn' ) ).toBeVisible();
	await expect( page.locator( '#a11yfy-filters .a11yfy-chip' ) ).toHaveCount( 5 );
} );

test( 'media library shows the accessibility column', async ( { page } ) => {
	await page.goto( '/wp-admin/upload.php?mode=list' );
	await expect( page.locator( 'th#a11yfy' ) ).toBeVisible();
} );

test( 'settings offers connect + manual key when disconnected', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=a11yfy-settings' );
	await expect( page.locator( '#a11yfy_api_key' ) ).toBeVisible();
	await expect( page.getByRole( 'link', { name: /Connect to a11yfy/i } ) ).toBeVisible();
} );

test( 'manual API key connects against the sandbox and shows the wizard-completed settings', async ( { page } ) => {
	test.skip( ! process.env.A11YFY_TEST_API_KEY, 'A11YFY_TEST_API_KEY not set — sandbox connect skipped' );

	await page.goto( '/wp-admin/admin.php?page=a11yfy-settings' );
	await page.fill( '#a11yfy_api_key', process.env.A11YFY_TEST_API_KEY );
	await page.click( '#submit' );

	await expect( page.locator( '.notice-success' ) ).toContainText( /Connected to a11yfy/i );
} );
