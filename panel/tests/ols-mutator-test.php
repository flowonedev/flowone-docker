#!/usr/bin/env php
<?php
/**
 * OlsConfigMutator Test Suite
 *
 * Verifies the four idempotent operations exposed by the mutator:
 *
 *   - upsertVirtualHost   creates if missing, no-op if present, applies
 *                         overrides without disturbing unrelated children
 *   - removeVirtualHost   removes the block + adjacent blank line we
 *                         inserted, no-op if not present
 *   - upsertListenerMaps  adds map line to Default + SSL, skips IPv6,
 *                         no-op if mapping already exists, optional
 *                         mail.<domain> mapping
 *   - removeListenerMaps  removes every map matching vhost name
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/ols-mutator-test.php --verbose
 *
 * Options: same as other foundation tests.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\Ols\OlsConfigMutator;
use VpsAdmin\Agent\Provisioner\Ols\OlsConfigParser;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('OlsConfigMutator', $opts);

$baseConfig = <<<'CFG'
serverName flowone

listener Default {
  address                 *:80
  secure                  0
  map                     existing.com existing.com
}

listener SSL {
  address                 *:443
  secure                  1
  map                     existing.com existing.com
}

listener "SSL IPv6" {
  address                 [::]:443
  secure                  1
}

virtualhost existing.com {
  vhRoot                  /home/$VH_NAME
  configFile              $SERVER_ROOT/conf/vhosts/$VH_NAME/vhost.conf
  allowSymbolLink         1
  enableScript            1
  restrained              1
}

CFG;

// ── upsert virtualhost ────────────────────────────────────────
$harness->test('upsert_vhost', 'creating a fresh vhost adds the block',
    function () use ($baseConfig) {
        $parser = new OlsConfigParser();
        $doc = $parser->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $changed = $mut->upsertVirtualHost($doc, 'example.com');
        if (!$changed) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected changed=true on new vhost'];
        }
        if ($mut->findVirtualHostBlock($doc, 'example.com') === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhost not found after insert'];
        }
        // Existing vhost must still be present.
        if ($mut->findVirtualHostBlock($doc, 'existing.com') === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'existing vhost was destroyed'];
        }
    });

$harness->test('upsert_vhost', 'second upsert with identical values is a no-op',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $mut->upsertVirtualHost($doc, 'example.com');
        $changed = $mut->upsertVirtualHost($doc, 'example.com');
        if ($changed) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected changed=false on repeat'];
        }
    });

$harness->test('upsert_vhost', 'override changes a directive value',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $mut->upsertVirtualHost($doc, 'example.com');
        $changed = $mut->upsertVirtualHost($doc, 'example.com', ['allowSymbolLink' => '0']);
        if (!$changed) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected changed=true on override'];
        }
        $vh = $mut->findVirtualHostBlock($doc, 'example.com');
        $directive = $vh->findChildDirective('allowSymbolLink');
        if ($directive->value !== '0') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'override not applied'];
        }
    });

$harness->test('upsert_vhost', 'override adds an unknown directive without removing knowns',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $mut->upsertVirtualHost($doc, 'example.com');
        $mut->upsertVirtualHost($doc, 'example.com', ['errorlog' => '/var/log/example.com.err']);
        $vh = $mut->findVirtualHostBlock($doc, 'example.com');
        if ($vh->findChildDirective('errorlog') === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'extra directive not added'];
        }
        if ($vh->findChildDirective('vhRoot') === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'default directive was deleted'];
        }
    });

// ── remove virtualhost ────────────────────────────────────────
$harness->test('remove_vhost', 'removeVirtualHost on a present block returns true',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $mut->upsertVirtualHost($doc, 'example.com');
        $changed = $mut->removeVirtualHost($doc, 'example.com');
        if (!$changed) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected changed=true on remove'];
        }
        if ($mut->findVirtualHostBlock($doc, 'example.com') !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhost still present after remove'];
        }
    });

$harness->test('remove_vhost', 'removeVirtualHost on a missing block returns false',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $changed = $mut->removeVirtualHost($doc, 'nope.com');
        if ($changed) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected changed=false on missing'];
        }
    });

// ── upsert listener maps ──────────────────────────────────────
$harness->test('upsert_maps', 'inserts maps into Default and SSL only',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $changed = $mut->upsertListenerMaps($doc, 'example.com', ['example.com']);
        if (!$changed) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected changed=true'];
        }
        $default = $doc->findBlock('listener', 'Default');
        $ssl = $doc->findBlock('listener', 'SSL');
        $ipv6 = $doc->findBlock('listener', '"SSL IPv6"');
        $hasExample = static function ($listener): bool {
            foreach ($listener->findAllChildDirectives('map') as $m) {
                if ($m->value === 'example.com example.com') return true;
            }
            return false;
        };
        if (!$hasExample($default)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'Default missing map'];
        }
        if (!$hasExample($ssl)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'SSL missing map'];
        }
        if ($ipv6 !== null && $hasExample($ipv6)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'IPv6 listener should not get the map'];
        }
    });

$harness->test('upsert_maps', 'repeat call is a no-op',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $mut->upsertListenerMaps($doc, 'example.com', ['example.com']);
        $changed = $mut->upsertListenerMaps($doc, 'example.com', ['example.com']);
        if ($changed) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected changed=false on repeat'];
        }
    });

$harness->test('upsert_maps', 'includeMail adds mail.<domain> mapping',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $mut->upsertListenerMaps($doc, 'example.com', ['example.com'], includeMail: true);
        $default = $doc->findBlock('listener', 'Default');
        $found = false;
        foreach ($default->findAllChildDirectives('map') as $m) {
            if ($m->value === 'example.com mail.example.com') $found = true;
        }
        if (!$found) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'mail map missing'];
        }
    });

// ── remove listener maps ──────────────────────────────────────
$harness->test('remove_maps', 'removes both regular and mail mappings',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $mut->upsertListenerMaps($doc, 'example.com', ['example.com'], includeMail: true);
        $changed = $mut->removeListenerMaps($doc, 'example.com');
        if (!$changed) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected changed=true'];
        }
        foreach (['Default', 'SSL'] as $name) {
            $listener = $doc->findBlock('listener', $name);
            foreach ($listener->findAllChildDirectives('map') as $m) {
                if (strpos($m->value, 'example.com') === 0) {
                    return ['outcome' => TestHarness::FAIL, 'message' => "stale map in {$name}: " . $m->value];
                }
            }
        }
    });

$harness->test('remove_maps', 'leaves unrelated maps intact',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        $mut->upsertListenerMaps($doc, 'example.com', ['example.com']);
        $mut->removeListenerMaps($doc, 'example.com');
        $default = $doc->findBlock('listener', 'Default');
        $existingFound = false;
        foreach ($default->findAllChildDirectives('map') as $m) {
            if ($m->value === 'existing.com existing.com') $existingFound = true;
        }
        if (!$existingFound) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'existing.com map was destroyed'];
        }
    });

// ── end-to-end: brace balance survives all operations ─────────
$harness->test('integrity', 'brace balance stays zero across many ops',
    function () use ($baseConfig) {
        $doc = (new OlsConfigParser())->parseString($baseConfig);
        $mut = new OlsConfigMutator();
        for ($i = 0; $i < 10; $i++) {
            $d = "site{$i}.local";
            $mut->upsertVirtualHost($doc, $d);
            $mut->upsertListenerMaps($doc, $d, [$d], includeMail: true);
        }
        for ($i = 0; $i < 10; $i += 2) {
            $d = "site{$i}.local";
            $mut->removeListenerMaps($doc, $d);
            $mut->removeVirtualHost($doc, $d);
        }
        $out = $doc->toString();
        $open = substr_count($out, '{');
        $close = substr_count($out, '}');
        if ($open !== $close) {
            return [
                'outcome' => TestHarness::FAIL,
                'message' => "braces drifted: open={$open}, close={$close}",
            ];
        }
    });

exit($harness->run());
