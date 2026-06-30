#!/usr/bin/env php
<?php
/**
 * Chaos scenario: VPN drop.
 *
 * Invariants exercised: I-9 (HMAC required for state consumption),
 *                       I-14 (bounded recovery storms).
 *
 * Steps:
 *   1. Confirm baseline status is healthy.
 *   2. Stop openvpn-client@synology via the helper (so MountLock is respected).
 *   3. Wait up to 60 s for monitord to publish status != healthy.
 *   4. Confirm the published payload still verifies (I-9 — daemon must
 *      keep signing even during incidents).
 *   5. Restart VPN via the helper.
 *   6. Wait up to 90 s for status to return to healthy.
 *   7. assertHeld() on the tracked invariants.
 *
 * Run on the live VPS:
 *   php shared/tests/chaos/scenario_vpn_drop.php \
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
use FlowOne\Storage\HelperClient;
use FlowOne\Storage\MonotonicClock;
use FlowOne\Storage\StorageHealth;

$runner = new ScenarioRunner('vpn_drop', ['I-9', 'I-14'], $argv);
$runner->requireLiveAck();
$runner->requireChaosEnabled();
$runner->expectsTenant();
$runner->announceAndDelay();

$assert = AssertInvariant::startWindow(['I-9']);
$health = StorageHealth::fromConfig();
$helper = HelperClient::fromConfig();

$runner->step('baseline status is healthy', function () use ($health) {
    StorageHealth::resetProcessCache();
    return $health->getStatus()->isHealthy();
});

$runner->step('stop openvpn via helper', function () use ($helper) {
    $r = $helper->call('systemctl', ['action' => 'stop', 'unit' => 'openvpn-client@synology']);
    return $r['ok'];
});

$runner->step('observe status flips to non-healthy within 60s', function () use ($health) {
    $deadline = MonotonicClock::nowNs() + 60_000_000_000;
    while (MonotonicClock::nowNs() < $deadline) {
        StorageHealth::resetProcessCache();
        $status = $health->getStatus();
        if (!$status->isHealthy()) {
            return true;
        }
        MonotonicClock::sleep(2.0);
    }
    return false;
});

$runner->step('published payload still HMAC-verifies (I-9)', function () use ($health) {
    StorageHealth::resetProcessCache();
    $status = $health->getStatus();
    return in_array($status->source, ['current', 'redis', 'backup'], true);
});

$runner->step('restart openvpn via helper', function () use ($helper) {
    $r = $helper->call('systemctl', ['action' => 'restart', 'unit' => 'openvpn-client@synology']);
    return $r['ok'];
});

$runner->step('observe status returns to healthy within 90s', function () use ($health) {
    $deadline = MonotonicClock::nowNs() + 90_000_000_000;
    while (MonotonicClock::nowNs() < $deadline) {
        StorageHealth::resetProcessCache();
        if ($health->getStatus()->isHealthy()) {
            return true;
        }
        MonotonicClock::sleep(3.0);
    }
    return false;
});

$runner->step('I-9 held throughout scenario window', function () use ($assert) {
    $assert->assertHeld();
    return true;
});

$runner->finish();
