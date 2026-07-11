<?php

declare(strict_types=1);

function fail(string $message): never
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}

function write_json(string $path, array $data): void
{
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
	if (file_put_contents($path, $json) === false) {
		fail('Unable to write fixture: ' . $path);
	}
}

function fpm_result(string $backend, float $median): array
{
	return [
		'case' => 'cycle_assignment_object',
		'backend' => $backend,
		'median_server_us_per_op' => $median,
		'mean_server_us_per_op' => $median,
		'p25_server_us_per_op' => $median,
		'p75_server_us_per_op' => $median,
		'worker_count' => 1,
	];
}

function fpm_fixture(array $backends, array $results): array
{
	return [
		'options' => [
			'cases' => ['cycle_assignment_object'],
			'backends' => $backends,
		],
		'environment' => [
			'php_version' => PHP_VERSION,
			'php_sapi' => PHP_SAPI,
			'php_binary' => PHP_BINARY,
			'uname' => php_uname(),
			'loaded_extensions' => [],
			'ini' => [],
		],
		'results' => $results,
		'failures' => [],
	];
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/uc-report-fpm-split-' . getmypid();
if (!mkdir($tmp, 0777, true) && !is_dir($tmp)) {
	fail('Unable to create temp directory: ' . $tmp);
}

try {
	$phpJson = $tmp . '/fpm-read-once-php.json';
	$igbinaryJson = $tmp . '/fpm-read-once-igbinary.json';
	$output = $tmp . '/report.html';

	write_json($phpJson, fpm_fixture(
		['user_cache', 'apcu'],
		[
			fpm_result('user_cache', 3.0),
			fpm_result('apcu', 4.0),
		]
	));
	write_json($igbinaryJson, fpm_fixture(
		['user_cache', 'apcu_igbinary'],
		[
			fpm_result('user_cache', 5.0),
			fpm_result('apcu_igbinary', 6.0),
		]
	));

	$cmd = escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($root . '/scripts/render_user_cache_performance_report.php')
		. ' --fpm-once ' . escapeshellarg($phpJson)
		. ' --fpm-once ' . escapeshellarg($igbinaryJson)
		. ' --output ' . escapeshellarg($output);
	exec($cmd . ' 2>&1', $lines, $status);
	if ($status !== 0) {
		fail("Renderer failed:\n" . implode("\n", $lines));
	}

	$html = file_get_contents($output);
	if ($html === false) {
		fail('Unable to read report: ' . $output);
	}

	foreach ([
		'FPM One Fetch Per Request (APCu serializer=php)',
		'FPM One Fetch Per Request (APCu serializer=igbinary)',
		'FPM one fetch/request (APCu serializer=php)',
		'FPM one fetch/request (APCu serializer=igbinary)',
		'APCu/php',
		'APCu/igbinary',
	] as $needle) {
		if (!str_contains($html, $needle)) {
			fail('Missing expected report text: ' . $needle);
		}
	}

	if (substr_count($html, '1/1 UserCache wins') !== 2) {
		fail('Expected each FPM serializer section to report a UserCache win.');
	}
	if (str_contains($html, '0/1 UserCache wins')) {
		fail('Renderer incorrectly compared UserCache from one serializer run with APCu from another.');
	}
} finally {
	foreach (glob($tmp . '/*') ?: [] as $path) {
		unlink($path);
	}
	rmdir($tmp);
}

echo "PASS\n";
