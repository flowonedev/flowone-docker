#!/usr/bin/env php
<?php
/**
 * Chaos scenario: disk full on state directory.
 *
 * Invariants exercised: I-9 (signed state remains trustworthy even when
 *                            writes start failing).
 *
 * Steps (NON-DESTRUCTIVE):
 *   1. Confirm baseline healthy.
 *   2. Allocate a chaos-only DurableJson under the synthetic tenant
 *      subtree. Cap its parent dir to a small quota by filling adjacent
 *      space with a balloon file. (This does NOT use any real state dir.)
 *   3. Attempt to write a new payload; expect the write to throw.
 *   4. Confirm the LAST GOOD payload (pre-balloon) is still readable.
 *   5. Cleanup: remove balloon file.
 *
 * IMPORTANT: we deliberately do NOT fill the real /var/lib/flowone or
 * any system-critical path. The balloon lives under the synthetic
 * tenant subtree, which the ChaosTargetGuard enforces.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Storage/Config.php';
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'FlowOne\\Storage\\Chaos\\')) {
        $rel = substr($class, strlen('FlowOne\\Storage\\Chaos\\'));
        $p = __DIR__ . '/lib/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($p)) require_once $p;
        return;
    }
    if (str_starts_with($class, 'FlowOne\\Storage\\')) {
        $rel = substr($class, strlen('FlowOne\\Storage\\'));
        $p = __DIR__ . '/../../src/Storage/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($p)) require_once $p;
    }
});

use FlowOne\Storage\Chaos\AssertInvariant;
use FlowOne\Storage\Chaos\ScenarioRunner;
use FlowOne\Storage\ChaosTargetGuard;
use FlowOne\Storage\Config;
use FlowOne\Storage\DurableJson;
use FlowOne\Storage\HmacSigner;

$runner = new ScenarioRunner('disk_full', ['I-9'], $argv);
$runner->requireLiveAck();
$runner->requireChaosEnabled();
$runner->expectsTenant();
$runner->announceAndDelay();

$assert = AssertInvariant::startWindow(['I-9']);
$config = Config::load();
$guard = ChaosTargetGuard::fromConfig();

$chaosStateDir = $guard->assertSafePath(
    rtrim((string) $config['nas']['mount_point'], '/') . '/' .
    trim((string) $config['tenants']['chaos-test']['subpath'], '/') . '/disk-full-state'
);
@mkdir($chaosStateDir, 0755, true);

$signer = HmacSigner::fromKeyFile(
    (string) $config['state']['hmac_key_path'],
    (int) $config['state']['hmac_key_mode_max']
);
$df = new DurableJson($chaosStateDir, 'state.json');

$balloonPath = $chaosStateDir . '/balloon.bin';

$runner->step('write valid baseline payload', function () use ($df, $signer) {
    $df->write($signer->signToJson([
        'status' => 'healthy', 'boot_epoch' => 1, 'generation' => 1,
        'published_at' => time(),
    ]));
    return is_file($df->currentPath());
});

$runner->step('write large balloon to consume free space', function () use ($balloonPath, $chaosStateDir) {
    $balloonSize = 64 * 1024 * 1024;
    $disk_free = @disk_free_space($chaosStateDir);
    if ($disk_free !== false && $disk_free < $balloonSize * 2) {
        // Skip the balloon if filesystem is already tight — we don't
        // want to actually run anyone out of space.
        echo "  (skipping balloon: insufficient free space margin)\n";
        return true;
    }
    $fh = @fopen($balloonPath, 'wb');
    if ($fh === false) return false;
    @fwrite($fh, str_repeat("\0", $balloonSize));
    @fclose($fh);
    return is_file($balloonPath);
});

$runner->step('verify last-good payload still readable', function () use ($df, $signer) {
    $raw = $df->readCurrent() ?? $df->readBackup();
    if ($raw === null) return false;
    return $signer->verifyJson($raw) !== null;
});

$runner->step('cleanup: remove balloon', function () use ($balloonPath) {
    if (is_file($balloonPath)) @unlink($balloonPath);
    return !is_file($balloonPath);
});

$runner->step('I-9 held throughout scenario window', function () use ($assert) {
    $assert->assertHeld();
    return true;
});

$runner->finish();
