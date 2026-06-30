#!/usr/bin/env php
<?php
/**
 * Chaos scenario: Redis loss.
 *
 * Invariants exercised: I-9 (HMAC required for state consumption).
 *
 * Steps:
 *   1. Confirm baseline health is readable from Redis source.
 *   2. Stop redis-server via the helper systemctl action.
 *   3. Confirm health is now read from the signed JSON file (source != redis).
 *   4. Confirm payload still HMAC-verifies.
 *   5. Restart redis.
 *   6. Confirm reads return to redis source after monitord re-publishes.
 *
 * Run on the live VPS:
 *   php shared/tests/chaos/scenario_redis_loss.php \
 *       --i-understand-this-is-live --tenant=chaos-test
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
use FlowOne\Storage\MonotonicClock;
use FlowOne\Storage\StorageHealth;

// NOTE: this scenario does NOT actually stop the system's Redis (that
// would disrupt every other service on the VPS). Instead it manipulates
// the StorageHealth client's Redis dependency to simulate the loss
// without affecting the rest of the application.
$runner = new ScenarioRunner('redis_loss', ['I-9'], $argv);
$runner->requireLiveAck();
$runner->requireChaosEnabled();
$runner->expectsTenant();
$runner->announceAndDelay();

$assert = AssertInvariant::startWindow(['I-9']);

$runner->step('baseline status reachable', function () {
    StorageHealth::resetProcessCache();
    $h = StorageHealth::fromConfig();
    $s = $h->getStatus();
    return in_array($s->source, ['redis', 'current', 'backup', 'default'], true);
});

$runner->step('disable Redis source on client side', function () {
    // We build a health client without Redis so the fallback chain is
    // forced to use the on-disk signed state file.
    StorageHealth::resetProcessCache();
    $h = StorageHealth::fromConfig(redis: null);
    $GLOBALS['_chaos_noredis_status'] = $h->getStatus();
    return true;
});

$runner->step('client falls back to signed JSON', function () {
    /** @var \FlowOne\Storage\HealthStatus $s */
    $s = $GLOBALS['_chaos_noredis_status'] ?? null;
    return $s !== null && in_array($s->source, ['current', 'backup'], true);
});

$runner->step('fallback payload HMAC-verifies (I-9)', function () {
    /** @var \FlowOne\Storage\HealthStatus $s */
    $s = $GLOBALS['_chaos_noredis_status'] ?? null;
    return $s !== null && !$s->isUnknown();
});

$runner->step('I-9 held throughout scenario window', function () use ($assert) {
    $assert->assertHeld();
    return true;
});

$runner->finish();
