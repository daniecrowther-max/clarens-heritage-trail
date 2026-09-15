<?php
/**
 * tools/build-zip.sh — the ONLY supported way to build the plugin release
 * zip — actually runs, and the archive it produces has exactly the
 * structure production needs.
 *
 * Run with:  php tests/test-build-zip.php
 *
 * The repo directory is `wordpress-plugin/`; the live install is
 * `wp-content/plugins/cha-heritage-trail/`. A zip with any other single
 * top-level folder installs a duplicate plugin, and a zip with no top-level
 * folder lands loose in wp-content/plugins/. Neither is visible to a test
 * that only reads the source tree, so this file invokes the build script and
 * asserts on the bytes of the zip it writes.
 *
 * @package cha
 */

$GLOBALS['cha_tests'] = array( 'pass' => 0, 'fail' => 0 );

function t_group( $name ) {
	echo "\n\033[1m" . $name . "\033[0m\n";
}

function t_ok( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['cha_tests']['pass']++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
	} else {
		$GLOBALS['cha_tests']['fail']++;
		echo "  \033[31m✗ " . $label . "\033[0m\n";
	}
}

function t_eq( $expected, $actual, $label ) {
	$ok = ( $expected === $actual );
	t_ok( $ok, $label );
	if ( ! $ok ) {
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}

function t_summary() {
	$p = $GLOBALS['cha_tests']['pass'];
	$f = $GLOBALS['cha_tests']['fail'];
	echo "\n" . ( $f ? "\033[31m" : "\033[32m" ) . $p . " passed, " . $f . " failed\033[0m\n";
	exit( $f ? 1 : 0 );
}

$repo_root    = dirname( __DIR__ );
$build_script = $repo_root . '/tools/build-zip.sh';
$zip_path     = $repo_root . '/dist/cha-heritage-trail.zip';

/* ── 1. The build script exists, is executable, and runs clean ──────────── */

t_group( '1. tools/build-zip.sh exists, is executable, and succeeds' );

t_ok( is_file( $build_script ), 'tools/build-zip.sh exists' );
t_ok( is_executable( $build_script ), 'tools/build-zip.sh is executable' );

exec( escapeshellarg( $build_script ) . ' 2>&1', $output, $exit_code );
t_eq( 0, $exit_code, 'the build script exits 0' );
t_ok( is_file( $zip_path ), 'dist/cha-heritage-trail.zip was written' );

/* ── 2. Exactly one top-level entry: the bare, un-stamped folder name ───── */

t_group( '2. The archive has exactly one top-level entry: cha-heritage-trail/' );

$zip         = new ZipArchive();
$open_result = $zip->open( $zip_path );
t_eq( true, $open_result, 'the zip opens cleanly' );

$top_levels  = array();
$first_entry = null;
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = $zip->getNameIndex( $i );
	if ( null === $first_entry ) {
		$first_entry = $name;
	}
	$slash = strpos( $name, '/' );
	$top_levels[ false === $slash ? $name : substr( $name, 0, $slash ) ] = true;
}

t_eq( 1, count( $top_levels ), 'exactly one distinct top-level entry' );
t_ok( isset( $top_levels['cha-heritage-trail'] ), 'the top-level entry is named "cha-heritage-trail" — not stamped, not renamed' );
t_eq( 'cha-heritage-trail/', $first_entry, 'the first entry in the archive is the bare top-level directory itself' );

/* ── 3. Nothing sits outside that one folder ─────────────────────────────── */

t_group( '3. Every single entry lives under cha-heritage-trail/ — nothing loose at the zip root' );

$outside = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = $zip->getNameIndex( $i );
	if ( 0 !== strpos( $name, 'cha-heritage-trail/' ) ) {
		$outside[] = $name;
	}
}
t_eq( array(), $outside, 'no entry exists outside cha-heritage-trail/' );

/* ── 4. The plugin's own bootstrap file is present at the expected path ──── */

t_group( "4. The plugin's main file is exactly where WordPress expects it" );

t_ok( false !== $zip->locateName( 'cha-heritage-trail/cha-heritage-trail.php' ), 'cha-heritage-trail/cha-heritage-trail.php is present' );

/* ── 5. The classes a broken archive would silently drop ─────────────────── */

t_group( '5. The payment and env classes are present' );

$named_classes = array(
	'class-cha-paystack.php',      // Checkout, verify-token and the webhook — the live payment rail.
	'class-cha-env.php',           // Without it no secret loads and every payment call fails.
	'class-cha-settings.php',      // The unlock price.
	'class-cha-redeem.php',
);
foreach ( $named_classes as $class_file ) {
	t_ok(
		false !== $zip->locateName( 'cha-heritage-trail/includes/' . $class_file ),
		"includes/$class_file is present in the archive"
	);
}

/* ── 6. Every tracked file is in the archive, and nothing extra is ──────── */

t_group( '6. The archive contains exactly the files git tracks under wordpress-plugin/ — no more, no fewer' );

exec( 'git -C ' . escapeshellarg( $repo_root ) . ' ls-tree -r --name-only HEAD -- wordpress-plugin', $tracked_output, $tracked_exit );
t_eq( 0, $tracked_exit, 'git ls-tree ran cleanly' );

$tracked = array();
foreach ( $tracked_output as $path ) {
	// Paths come back rooted at "wordpress-plugin/…" — the archive roots
	// the same tree at "cha-heritage-trail/…" instead.
	$tracked[] = 'cha-heritage-trail/' . substr( $path, strlen( 'wordpress-plugin/' ) );
}
sort( $tracked );

$archived_files = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = $zip->getNameIndex( $i );
	if ( '/' !== substr( $name, -1 ) ) { // Skip directory entries — git ls-tree lists only blobs.
		$archived_files[] = $name;
	}
}
sort( $archived_files );

t_eq( $tracked, $archived_files, 'the archive\'s file set is identical to git\'s tracked file set for wordpress-plugin/' );

$zip->close();

t_summary();
