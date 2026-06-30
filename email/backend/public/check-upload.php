<?php
/**
 * Upload Configuration Diagnostic
 * Run this to check PHP upload settings
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$diagnostics = [
    'php_version' => PHP_VERSION,
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
    'file_uploads' => ini_get('file_uploads'),
    'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
    'tmp_dir_exists' => is_dir(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
    'tmp_dir_writable' => is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
    'storage_path' => '/var/www/vps-admin/storage/drive',
    'storage_exists' => is_dir('/var/www/vps-admin/storage/drive'),
    'storage_writable' => is_writable('/var/www/vps-admin/storage/drive'),
];

// Convert PHP shorthand to bytes for comparison
function toBytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

$uploadMax = toBytes($diagnostics['upload_max_filesize']);
$postMax = toBytes($diagnostics['post_max_size']);

$diagnostics['upload_max_bytes'] = $uploadMax;
$diagnostics['post_max_bytes'] = $postMax;
$diagnostics['effective_max_upload'] = min($uploadMax, $postMax);
$diagnostics['effective_max_upload_formatted'] = 
    $diagnostics['effective_max_upload'] >= 1048576 
        ? round($diagnostics['effective_max_upload'] / 1048576, 2) . ' MB'
        : round($diagnostics['effective_max_upload'] / 1024, 2) . ' KB';

// Check for common issues
$diagnostics['issues'] = [];

if ($uploadMax < 10485760) { // Less than 10MB
    $diagnostics['issues'][] = 'upload_max_filesize is less than 10MB';
}

if ($postMax < 10485760) { // Less than 10MB
    $diagnostics['issues'][] = 'post_max_size is less than 10MB';
}

if ($postMax < $uploadMax) {
    $diagnostics['issues'][] = 'post_max_size should be larger than upload_max_filesize';
}

if (!$diagnostics['tmp_dir_writable']) {
    $diagnostics['issues'][] = 'Upload temp directory is not writable';
}

if (!$diagnostics['storage_exists']) {
    $diagnostics['issues'][] = 'Storage directory does not exist';
} elseif (!$diagnostics['storage_writable']) {
    $diagnostics['issues'][] = 'Storage directory is not writable';
}

$diagnostics['status'] = empty($diagnostics['issues']) ? 'OK' : 'ISSUES_FOUND';

echo json_encode($diagnostics, JSON_PRETTY_PRINT);

