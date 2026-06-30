<?php
/**
 * Diff Generator
 * 
 * Generates human-readable diffs for configuration changes.
 * Used for audit logging and change visualization.
 */

namespace VpsAdmin\Agent\Lib;

class DiffGenerator
{
    /**
     * Generate a unified diff between two strings
     */
    public function generate(string $original, string $modified, string $label = 'config'): string
    {
        $originalLines = explode("\n", $original);
        $modifiedLines = explode("\n", $modified);

        $diff = $this->computeDiff($originalLines, $modifiedLines);
        
        if (empty($diff)) {
            return '';
        }

        $output = "--- a/{$label}\n";
        $output .= "+++ b/{$label}\n";
        $output .= $this->formatDiff($diff, $originalLines, $modifiedLines);

        return $output;
    }

    /**
     * Generate diff from file paths
     */
    public function fromFiles(string $originalPath, string $modifiedPath): string
    {
        $original = file_exists($originalPath) ? file_get_contents($originalPath) : '';
        $modified = file_exists($modifiedPath) ? file_get_contents($modifiedPath) : '';

        return $this->generate($original, $modified, basename($originalPath));
    }

    /**
     * Generate diff from original content and new content
     */
    public function fromContent(string $originalContent, string $newContent, string $label = 'config'): string
    {
        return $this->generate($originalContent, $newContent, $label);
    }

    /**
     * Compute the diff using LCS algorithm
     */
    private function computeDiff(array $original, array $modified): array
    {
        $m = count($original);
        $n = count($modified);

        // Build LCS table
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($original[$i - 1] === $modified[$j - 1]) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }

        // Backtrack to find diff
        $diff = [];
        $i = $m;
        $j = $n;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $original[$i - 1] === $modified[$j - 1]) {
                array_unshift($diff, ['type' => ' ', 'line' => $original[$i - 1], 'orig' => $i, 'mod' => $j]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                array_unshift($diff, ['type' => '+', 'line' => $modified[$j - 1], 'orig' => null, 'mod' => $j]);
                $j--;
            } else {
                array_unshift($diff, ['type' => '-', 'line' => $original[$i - 1], 'orig' => $i, 'mod' => null]);
                $i--;
            }
        }

        return $diff;
    }

    /**
     * Format diff output with context
     */
    private function formatDiff(array $diff, array $original, array $modified): string
    {
        $output = '';
        $contextLines = 3;
        $hunks = [];
        $currentHunk = null;

        for ($i = 0; $i < count($diff); $i++) {
            $entry = $diff[$i];
            
            if ($entry['type'] !== ' ') {
                // Start or extend a hunk
                if ($currentHunk === null) {
                    $start = max(0, $i - $contextLines);
                    $currentHunk = ['start' => $start, 'end' => $i];
                }
                $currentHunk['end'] = min(count($diff) - 1, $i + $contextLines);
            } else if ($currentHunk !== null) {
                // Check if we should close the hunk
                $distanceToNextChange = $this->findNextChange($diff, $i);
                if ($distanceToNextChange > $contextLines * 2) {
                    $currentHunk['end'] = min(count($diff) - 1, $i + $contextLines - 1);
                    $hunks[] = $currentHunk;
                    $currentHunk = null;
                }
            }
        }

        if ($currentHunk !== null) {
            $hunks[] = $currentHunk;
        }

        // Format hunks
        foreach ($hunks as $hunk) {
            $origStart = null;
            $modStart = null;
            $origCount = 0;
            $modCount = 0;
            $lines = '';

            for ($i = $hunk['start']; $i <= $hunk['end']; $i++) {
                $entry = $diff[$i];
                
                if ($entry['orig'] !== null && $origStart === null) {
                    $origStart = $entry['orig'];
                }
                if ($entry['mod'] !== null && $modStart === null) {
                    $modStart = $entry['mod'];
                }
                
                if ($entry['type'] !== '+') $origCount++;
                if ($entry['type'] !== '-') $modCount++;

                $lines .= $entry['type'] . $entry['line'] . "\n";
            }

            $origStart = $origStart ?? 1;
            $modStart = $modStart ?? 1;

            $output .= "@@ -{$origStart},{$origCount} +{$modStart},{$modCount} @@\n";
            $output .= $lines;
        }

        return $output;
    }

    private function findNextChange(array $diff, int $currentIndex): int
    {
        for ($i = $currentIndex + 1; $i < count($diff); $i++) {
            if ($diff[$i]['type'] !== ' ') {
                return $i - $currentIndex;
            }
        }
        return PHP_INT_MAX;
    }
}

