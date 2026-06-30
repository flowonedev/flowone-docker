<?php
/**
 * Test DOCX content extraction
 */

require_once __DIR__ . '/../vendor/autoload.php';

$filePath = '/mnt/nas-drive/c015c2e2a3258660412b97f750227cc7/258675548106f959bf52a2e0d9151065.docx';

echo "Testing DOCX extraction\n";
echo "File: $filePath\n";
echo "Exists: " . (file_exists($filePath) ? 'YES' : 'NO') . "\n";
echo "Readable: " . (is_readable($filePath) ? 'YES' : 'NO') . "\n";
echo "Size: " . (file_exists($filePath) ? filesize($filePath) : 0) . " bytes\n\n";

if (!file_exists($filePath)) {
    echo "File not found!\n";
    
    // List directory contents
    $dir = dirname($filePath);
    echo "\nDirectory contents of $dir:\n";
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f !== '.' && $f !== '..') {
                echo "  $f\n";
            }
        }
    } else {
        echo "  Directory not accessible\n";
    }
    exit(1);
}

// Check if PhpWord is available
echo "PhpWord available: " . (class_exists('\PhpOffice\PhpWord\IOFactory') ? 'YES' : 'NO') . "\n\n";

if (!class_exists('\PhpOffice\PhpWord\IOFactory')) {
    echo "PhpWord not installed! Run: composer require phpoffice/phpword\n";
    exit(1);
}

// Try to extract content
try {
    echo "Loading DOCX...\n";
    $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
    
    echo "Extracting text...\n";
    $text = '';
    
    foreach ($phpWord->getSections() as $sectionIndex => $section) {
        echo "Section $sectionIndex:\n";
        foreach ($section->getElements() as $elementIndex => $element) {
            $elementText = extractTextFromElement($element);
            if ($elementText) {
                echo "  Element $elementIndex: " . substr($elementText, 0, 100) . "\n";
                $text .= $elementText . "\n";
            }
        }
    }
    
    echo "\n=== EXTRACTED CONTENT ===\n";
    echo $text ?: "(empty)";
    echo "\n=========================\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

function extractTextFromElement($element): string
{
    $text = '';
    
    if (method_exists($element, 'getText')) {
        $text = $element->getText();
    } elseif (method_exists($element, 'getElements')) {
        foreach ($element->getElements() as $child) {
            $text .= extractTextFromElement($child) . ' ';
        }
    }
    
    // Handle TextRun elements
    if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
        foreach ($element->getElements() as $child) {
            $text .= extractTextFromElement($child) . ' ';
        }
    }
    
    return trim($text);
}

