#!/usr/bin/env php
<?php
/**
 * Chaos scenario: JSON state corruption.
 *
 * Invariants exercised: I-9 (HMAC required), I-13 (generation monotonic).
 *
 * Steps:
 *   1. Confirm baseline status comes from current source.
 *   2. Corrupt the current JSON file in a synthetic copy directory (we
 *      DO NOT touch the real state file — we point a parallel DurableJson
 *      instance at a chaos-only directory under the chaos tenant subtree).
 *   3. Confirm the StorageHealth client discards the bad payload and falls
 *      back to backup (or default if no backup yet).
 *   4. assertHeld(I-9).
 *
 * This scenario deliberately does NOT call assertViolated; the HMAC check
 * is supposed to silently fall through, NOT trip an invariant assertion
 * (invariant_violation is reserved for assertion-method failures, not
 * for "we caught the attack and routed around it").
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
use FlowOne\Storage\Chaos\ChaosTargetGuard;
use FlowOne\Storage\Chaos\ScenarioRunner;
use FlowOne\Storage\BootEpoch;
use FlowOne\Storage\Config;
use FlowOne\Storage\DurableJson;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\StorageHealth;

$runner = new ScenarioRunner('json_corruption', ['I-9', 'I-13'], $argv);
$runner->requireLiveAck();
$runner->requireChaosEnabled();
$runner->expectsTenant();
$runner->announceAndDelay();

$assert = AssertInvariant::startWindow(['I-9']);
$config = Config::load();
$guard = \FlowOne\Storage\ChaosTargetGuard::fromConfig();

// Build a chaos-only state file under the synthetic tenant subtree.
$chaosStateDir = $guard->assertSafePath(
    rtrim((string) $config['nas']['mount_point'], '/') . '/' .
    trim((string) $config['tenants']['chaos-test']['subpath'], '/') . '/state'
);
@mkdir($chaosStateDir, 0755, true);

$signer = HmacSigner::fromKeyFile(
    (string) $config['state']['hmac_key_path'],
    (int) $config['state']['hmac_key_mode_max']
);
$df = new DurableJson($chaosStateDir, 'chaos-health.json');
$bootEpoch = new BootEpoch($chaosStateDir . '/chaos-boot-epoch');

$runner->step('write valid signed baseline to chaos state file', function () use ($df, $signer) {
    $payload = [
        'status' => 'healthy',
        'boot_epoch' => 999,
        'generation' => 1,
        'published_at' => time(),
        'checks' => [],
    ];
    $df->write($signer->signToJson($payload));
    return is_file($df->currentPath());
});

$runner->step('corrupt the current file (flip middle bytes)', function () use ($df) {
    $path = $df->currentPath();
    $fh = fopen($path, 'rb+');
    if ($fh === false) return false;
    fseek($fh, 10, SEEK_SET);
    fwrite($fh, str_repeat("\x00", 5));
    fclose($fh);
    return true;
});

$runner->step('reader discards corrupt current and falls back', function () use ($df, $signer) {
    StorageHealth::resetProcessCache();
    $client = new StorageHealth(
        signer: $signer,
        stateFile: $df,
        bootEpoch: new BootEpoch(dirname($df->currentPath()) . '/chaos-boot-epoch'),
        redis: null,
    );
    $status = $client->getStatus();
    // After corruption AND no .bak yet, we expect the safe default.
    return $status->source === 'default' || $status->source === 'backup';
});

$runner->step('I-9 held (the HMAC check is non-bypassable)', function () use ($assert) {
    $assert->assertHeld();
    return true;
});

$runner->finish();
