#!/usr/bin/env php
<?php
/**
 * projecthub-coverage-check.php — routes + ProjectHub/Services class references in tests.
 *   php projecthub-coverage-check.php [--verbose] [--strict-methods]
 */
if (php_sapi_name() !== 'cli') {
    exit(1);
}
$opts = getopt('', ['help', 'verbose', 'strict-methods']) ?: [];
if (isset($opts['help'])) {
    echo "projecthub-coverage-check.php [--verbose] [--strict-methods]\n";
    exit(0);
}
$v = isset($opts['verbose']);
$strictMethods = isset($opts['strict-methods']);

$routes = file_get_contents(__DIR__ . '/../routes.php') ?: '';
preg_match_all("/['\"]\\/project-hub\\/[^'\"]+['\"]/", $routes, $m);
$list = array_unique($m[0] ?? []);
$tests = '';
foreach (glob(__DIR__ . '/project-hub-*.php') ?: [] as $f) {
    $tests .= file_get_contents($f) ?: '';
}
$tests .= file_get_contents(__DIR__ . '/lib/projecthub-fixtures.php') ?: '';

$missingRoutes = [];
foreach ($list as $quoted) {
    $path = trim($quoted, "'\"");
    if (strpos($tests, $path) === false) {
        $missingRoutes[] = $path;
    }
}

$svcDir = realpath(__DIR__ . '/../src/Addons/ProjectHub/Services');
$missingClasses = [];
$serviceFiles = [];
if ($svcDir !== false) {
    foreach (glob($svcDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $sf) {
        $serviceFiles[] = $sf;
        $c = file_get_contents($sf) ?: '';
        if (!preg_match('/class\s+(\w+)/', $c, $cm)) {
            continue;
        }
        $short = $cm[1];
        if (strpos($tests, $short) === false) {
            $missingClasses[] = $short;
        }
    }
}

/**
 * Skip rules per plan acceptance criteria:
 *   - inherited / trait methods (already filtered by getDeclaringClass())
 *   - constructor + magic __ methods
 *   - tiny wrappers (<= 3 lines of body)
 *   - trivial getters/setters (single `return $this->x;`)
 *   - explicit // @coverage-ignore line immediately above the declaration
 */
$methodGap = 0;
$skipped = ['inherited' => 0, 'tiny' => 0, 'ignored' => 0, 'getter' => 0, 'ctor' => 0];
$gaps = [];
if ($strictMethods) {
    $bootstrap = __DIR__ . '/../cron/bootstrap.php';
    if (is_file($bootstrap)) {
        require_once $bootstrap;
    }
    foreach ($serviceFiles as $file) {
        $c = file_get_contents($file) ?: '';
        if (!preg_match('/namespace\s+([^;]+);/', $c, $nm)) {
            continue;
        }
        if (!preg_match('/class\s+(\w+)/', $c, $cm)) {
            continue;
        }
        $fq = trim($nm[1]) . '\\' . trim($cm[1]);
        if (!class_exists($fq, false)) {
            require_once $file;
        }
        if (!class_exists($fq)) {
            continue;
        }
        $rc = new ReflectionClass($fq);
        $lines = file($file) ?: [];
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $fq) {
                $skipped['inherited']++;
                continue;
            }
            $name = $m->getName();
            if ($name === '__construct' || str_starts_with($name, '__')) {
                $skipped['ctor']++;
                continue;
            }

            // @coverage-ignore on the line(s) above the declaration?
            $start = (int) $m->getStartLine();
            $ignored = false;
            for ($i = $start - 2; $i >= max(0, $start - 6); $i--) {
                $line = $lines[$i] ?? '';
                if (strpos($line, '@coverage-ignore') !== false) {
                    $ignored = true;
                    break;
                }
                if (preg_match('/^\s*(\*|\*\/|\/\*|\/\/|#)/', $line) || trim($line) === '') {
                    continue;
                }
                break;
            }
            if ($ignored) {
                $skipped['ignored']++;
                continue;
            }

            // Body size — count non-empty non-comment lines.
            $end = (int) $m->getEndLine();
            $bodyLines = 0;
            $bodyText = '';
            for ($i = $start; $i < $end; $i++) {
                $row = $lines[$i] ?? '';
                $bodyText .= $row;
                $t = trim($row);
                if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
                    continue;
                }
                $bodyLines++;
            }
            if ($bodyLines <= 3) {
                $skipped['tiny']++;
                continue;
            }
            // Trivial getter: single `return $this->X;` (with optional null coalescing).
            if (preg_match('/^\s*public\s+function[^{]*\{\s*return\s+\$this->[A-Za-z_]\w*(\s*\?\?\s*[^;]+)?;\s*\}\s*$/s', $bodyText)) {
                $skipped['getter']++;
                continue;
            }

            if (!preg_match('/\b' . preg_quote($name, '/') . '\b/', $tests)) {
                $methodGap++;
                $gaps[] = $fq . '::' . $name;
                if ($v) {
                    fwrite(STDERR, "method_not_in_tests {$fq}::{$name}\n");
                }
            }
        }
    }
}

$fail = $missingClasses !== [];
echo 'routes_matched=' . count($list)
    . ' uncovered_route_hints=' . count($missingRoutes)
    . ' service_classes=' . count($serviceFiles)
    . ' classes_missing_in_tests=' . count($missingClasses);
if ($strictMethods) {
    echo ' public_methods_not_name_matched_in_tests=' . $methodGap;
    echo ' skipped_inherited=' . $skipped['inherited'];
    echo ' skipped_tiny=' . $skipped['tiny'];
    echo ' skipped_ignored=' . $skipped['ignored'];
    echo ' skipped_getters=' . $skipped['getter'];
}
echo "\n";

if ($strictMethods && $v && $gaps !== []) {
    foreach ($gaps as $g) {
        fwrite(STDERR, "method_uncovered: {$g}\n");
    }
}

if ($v && $missingRoutes !== []) {
    foreach ($missingRoutes as $p) {
        fwrite(STDERR, "route hint (not in test text): {$p}\n");
    }
}
if ($v && $missingClasses !== []) {
    foreach ($missingClasses as $p) {
        fwrite(STDERR, "class uncovered: {$p}\n");
    }
}

exit($fail ? 1 : 0);
