#!/usr/bin/env php
<?php
/**
 * OlsConfigParser Test Suite
 *
 * Verifies:
 *   - The parser produces an AST whose `toString()` is byte-identical
 *     to the input for unmodified documents (round-trip fidelity).
 *   - Comments, blank lines, indentation, and inline comments survive.
 *   - Nested blocks (module -> children -> sub-blocks) parse correctly.
 *   - virtualhost / virtualHost case variation is preserved.
 *   - Unterminated blocks throw OlsParseException with the right line.
 *   - A real-world httpd_config.conf parses without errors (if present).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/ols-parser-test.php --verbose
 *
 * Options:
 *   --verbose
 *   --skip-send  n/a
 *   --only=GROUP  roundtrip,errors,realworld
 *   --smoke
 *   --json
 *   --help
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

use VpsAdmin\Agent\Provisioner\Exceptions\OlsParseException;
use VpsAdmin\Agent\Provisioner\Ols\Ast\BlankLineNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\BlockNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\CommentNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\DirectiveNode;
use VpsAdmin\Agent\Provisioner\Ols\OlsConfigParser;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('OlsConfigParser', $opts);

$samples = [
    'minimal' => "serverName flowone\n",
    'with-comment' => "# A comment\nserverName flowone\n",
    'simple-block' => <<<'CFG'
virtualhost example.com {
  vhRoot                  /home/$VH_NAME
  configFile              $SERVER_ROOT/conf/vhosts/$VH_NAME/vhost.conf
  allowSymbolLink         1
  enableScript            1
  restrained              1
}

CFG,
    'listener-with-maps' => <<<'CFG'
listener Default {
  address                 *:80
  secure                  0
  map                     example.com example.com
  map                     other.com other.com
}

CFG,
    'nested-blocks' => <<<'CFG'
module cache {
  ls_enabled              1
  storagePath             /tmp/lscache
  rewrite {
    enable                  1
  }
}

CFG,
    'blank-lines-and-comments' => <<<'CFG'
# Top of file


serverName flowone

# Block below
listener Default {
  # inside listener
  address                 *:80
}


CFG,
    'inline-comment-on-directive' => "serverName flowone   # production\n",
    'casevariant' => "virtualHost ExaMple.COM {\n  vhRoot  /home/\$VH_NAME\n}\n",
];

// ── roundtrip: parse then toString equals original ────────────
foreach ($samples as $label => $cfg) {
    $harness->test('roundtrip', "byte-identical round-trip: {$label}",
        function () use ($cfg) {
            $parser = new OlsConfigParser();
            $doc = $parser->parseString($cfg);
            $out = $doc->toString();
            if ($out !== $cfg) {
                return [
                    'outcome' => TestHarness::FAIL,
                    'message' => "diff:\n--- expected ---\n" . substr($cfg, 0, 300)
                        . "\n--- got ---\n" . substr($out, 0, 300),
                ];
            }
        });
}

// ── structure: AST contents reflect input ────────────────────
$harness->test('roundtrip', 'simple-block produces expected AST shape',
    function () use ($samples) {
        $doc = (new OlsConfigParser())->parseString($samples['simple-block']);
        $vhost = $doc->findBlock('virtualhost', 'example.com');
        if ($vhost === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhost block not found'];
        }
        $vhRoot = $vhost->findChildDirective('vhRoot');
        if ($vhRoot === null || $vhRoot->value !== '/home/$VH_NAME') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhRoot wrong: ' . ($vhRoot?->value ?? '<null>')];
        }
    });

$harness->test('roundtrip', 'listener block lists all child maps',
    function () use ($samples) {
        $doc = (new OlsConfigParser())->parseString($samples['listener-with-maps']);
        $listener = $doc->findBlock('listener', 'Default');
        if ($listener === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'listener not found'];
        }
        $maps = $listener->findAllChildDirectives('map');
        if (count($maps) !== 2) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected 2 maps, got ' . count($maps)];
        }
    });

$harness->test('roundtrip', 'nested block: rewrite inside module cache',
    function () use ($samples) {
        $doc = (new OlsConfigParser())->parseString($samples['nested-blocks']);
        $module = $doc->findBlock('module', 'cache');
        if (!$module) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'module not found'];
        }
        $rewrite = $module->findChildBlock('rewrite');
        if (!$rewrite) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'rewrite child block not found'];
        }
        $enable = $rewrite->findChildDirective('enable');
        if (!$enable || $enable->value !== '1') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'enable wrong'];
        }
    });

$harness->test('roundtrip', 'inline comments on directives preserve',
    function () use ($samples) {
        $doc = (new OlsConfigParser())->parseString($samples['inline-comment-on-directive']);
        $sn = $doc->findDirective('serverName');
        if (!$sn) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'serverName not found'];
        }
        if ($sn->inlineComment === null || strpos($sn->inlineComment, 'production') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'inline comment not captured'];
        }
    });

$harness->test('roundtrip', 'virtualHost casing is preserved exactly',
    function () use ($samples) {
        $doc = (new OlsConfigParser())->parseString($samples['casevariant']);
        $b = $doc->findBlock('virtualhost', 'ExaMple.COM');
        if (!$b) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'case-variant block not found'];
        }
        if ($b->name !== 'virtualHost') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'casing changed: ' . $b->name];
        }
    });

// ── errors: bad input throws OlsParseException ────────────────
$harness->test('errors', 'unterminated block throws OlsParseException',
    function () {
        $bad = "virtualhost example.com {\n  vhRoot foo\n";
        try {
            (new OlsConfigParser())->parseString($bad);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (OlsParseException $e) {
            if ($e->sourceLine !== 1) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'wrong line: ' . $e->sourceLine];
            }
        }
    });

$harness->test('errors', 'block header without name throws',
    function () {
        // The header "  {" with no name is rejected before parseBlock,
        // because the parser only enters parseBlock when the line ends
        // in `{` AND has a name token before it. A bare `{` line falls
        // through to parseDirective which rejects empty-name.
        $bad = "{\n  vhRoot foo\n}\n";
        try {
            (new OlsConfigParser())->parseString($bad);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (OlsParseException) {
            // ok
        }
    });

// ── realworld: actual httpd_config.conf if present ────────────
$harness->test('realworld', 'parse the live /usr/local/lsws/conf/httpd_config.conf',
    function () {
        $path = '/usr/local/lsws/conf/httpd_config.conf';
        if (!is_readable($path)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'live config not readable from this user'];
        }
        $parser = new OlsConfigParser();
        $doc = $parser->parseFile($path);
        $original = file_get_contents($path);
        $rendered = $doc->toString();
        if ($rendered !== $original) {
            // Show line counts and first divergence offset to make
            // failure debuggable without dumping a 1000-line config.
            $origLines = substr_count($original, "\n");
            $newLines = substr_count($rendered, "\n");
            $diffAt = -1;
            $min = min(strlen($original), strlen($rendered));
            for ($i = 0; $i < $min; $i++) {
                if ($original[$i] !== $rendered[$i]) { $diffAt = $i; break; }
            }
            return [
                'outcome' => TestHarness::FAIL,
                'message' => sprintf(
                    "live config did not round-trip: origLines=%d, newLines=%d, firstDiffByte=%d, lenOrig=%d, lenNew=%d",
                    $origLines, $newLines, $diffAt, strlen($original), strlen($rendered)
                ),
            ];
        }
    });

exit($harness->run());
