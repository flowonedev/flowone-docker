<?php
/**
 * Time Tracker Implementation Test
 * 
 * Run this file in browser to test the batch endpoint:
 * https://email.devcon1.hu/api/test-time-tracker.php
 * 
 * Or via CLI: php test-time-tracker.php
 */

header('Content-Type: text/html; charset=utf-8');

// Check if this is a CLI or web request
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>Time Tracker Test</title>";
    echo "<style>
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #6366f1; }
        .test { background: #16213e; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .pass { border-left: 4px solid #22c55e; }
        .fail { border-left: 4px solid #ef4444; }
        .info { border-left: 4px solid #3b82f6; }
        pre { background: #0f0f23; padding: 10px; border-radius: 4px; overflow-x: auto; }
        code { color: #a5b4fc; }
        .btn { background: #6366f1; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #4f46e5; }
    </style></head><body>";
    echo "<h1>Time Tracker Implementation Test</h1>";
}

function output($message, $class = 'info') {
    global $isCli;
    if ($isCli) {
        $prefix = $class === 'pass' ? '[PASS]' : ($class === 'fail' ? '[FAIL]' : '[INFO]');
        echo "$prefix $message\n";
    } else {
        echo "<div class='test $class'>$message</div>";
    }
}

// Test 1: Check if route file has the batch endpoint
output("<strong>Test 1: Check routes.php for batch endpoint</strong>", 'info');
$routesFile = __DIR__ . '/../routes.php';
if (file_exists($routesFile)) {
    $routesContent = file_get_contents($routesFile);
    if (strpos($routesContent, 'track-time-batch') !== false) {
        output("Route /statistics/track-time-batch found in routes.php", 'pass');
    } else {
        output("Route /statistics/track-time-batch NOT found in routes.php", 'fail');
    }
} else {
    output("routes.php not found at: $routesFile", 'fail');
}

// Test 2: Check if StatisticsController has trackTimeBatch method
output("<strong>Test 2: Check StatisticsController for trackTimeBatch method</strong>", 'info');
$controllerFile = __DIR__ . '/../src/Controllers/StatisticsController.php';
if (file_exists($controllerFile)) {
    $controllerContent = file_get_contents($controllerFile);
    if (strpos($controllerContent, 'function trackTimeBatch') !== false) {
        output("trackTimeBatch() method found in StatisticsController.php", 'pass');
    } else {
        output("trackTimeBatch() method NOT found in StatisticsController.php", 'fail');
    }
} else {
    output("StatisticsController.php not found at: $controllerFile", 'fail');
}

// Test 3: Check frontend composable for localStorage
output("<strong>Test 3: Check useTimeTracker.js for localStorage persistence</strong>", 'info');
$composableFile = __DIR__ . '/../../frontend/src/composables/useTimeTracker.js';
// Try dist location too
if (!file_exists($composableFile)) {
    $composableFile = __DIR__ . '/../../dist/assets/'; // Would be bundled
}
$srcComposable = realpath(__DIR__ . '/../../frontend/src/composables/useTimeTracker.js');
if ($srcComposable && file_exists($srcComposable)) {
    $composableContent = file_get_contents($srcComposable);
    
    $checks = [
        'STORAGE_KEY' => strpos($composableContent, 'STORAGE_KEY') !== false,
        'localStorage.setItem' => strpos($composableContent, 'localStorage.setItem') !== false,
        'localStorage.getItem' => strpos($composableContent, 'localStorage.getItem') !== false,
        'IDLE_THRESHOLD' => strpos($composableContent, 'IDLE_THRESHOLD') !== false,
        'handleUserActivity' => strpos($composableContent, 'handleUserActivity') !== false,
        'SYNC_INTERVAL = 3' => strpos($composableContent, '3 * 60 * 1000') !== false,
    ];
    
    foreach ($checks as $feature => $found) {
        if ($found) {
            output("$feature - Found", 'pass');
        } else {
            output("$feature - NOT Found", 'fail');
        }
    }
} else {
    output("useTimeTracker.js not found (may need to check built files)", 'info');
}

if (!$isCli) {
    echo "<hr>";
    echo "<h2>Manual API Test</h2>";
    echo "<p>Click the button below to test the batch endpoint (requires authentication):</p>";
    echo "<button class='btn' onclick='testBatchEndpoint()'>Test Batch Endpoint</button>";
    echo "<button class='btn' onclick='testLocalStorage()'>Test localStorage</button>";
    echo "<pre id='result'></pre>";
    
    echo "<script>
    async function testBatchEndpoint() {
        const result = document.getElementById('result');
        result.textContent = 'Testing batch endpoint...\\n';
        
        const testData = [
            { section: 'email', duration_seconds: 30, folder: 'INBOX' },
            { section: 'drive', duration_seconds: 15, folder: null },
            { section: 'calendar', duration_seconds: 10, folder: null }
        ];
        
        try {
            // Get auth token from localStorage (if user is logged in)
            const authData = localStorage.getItem('auth');
            let token = null;
            if (authData) {
                const parsed = JSON.parse(authData);
                token = parsed.token;
            }
            
            if (!token) {
                result.textContent += 'No auth token found. Please login first.\\n';
                result.textContent += 'Testing without auth (will likely fail)...\\n';
            }
            
            const headers = {
                'Content-Type': 'application/json'
            };
            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }
            
            const response = await fetch('/api/statistics/track-time-batch', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify(testData)
            });
            
            const data = await response.json();
            result.textContent += 'Response status: ' + response.status + '\\n';
            result.textContent += 'Response: ' + JSON.stringify(data, null, 2) + '\\n';
            
            if (data.success && data.data?.tracked > 0) {
                result.textContent += '\\n[PASS] Batch endpoint is working!';
            } else if (response.status === 401) {
                result.textContent += '\\n[INFO] Auth required - please login to MailFlow first';
            } else {
                result.textContent += '\\n[FAIL] Unexpected response';
            }
        } catch (error) {
            result.textContent += 'Error: ' + error.message + '\\n';
            result.textContent += '[FAIL] Request failed';
        }
    }
    
    function testLocalStorage() {
        const result = document.getElementById('result');
        result.textContent = 'Testing localStorage persistence...\\n\\n';
        
        const STORAGE_KEY = 'mailflow_time_tracker';
        
        // Check if there's existing data
        const existing = localStorage.getItem(STORAGE_KEY);
        if (existing) {
            result.textContent += 'Existing data found:\\n' + existing + '\\n\\n';
        } else {
            result.textContent += 'No existing time tracker data in localStorage\\n\\n';
        }
        
        // Test write
        const testData = {
            accumulatedTime: { email: { INBOX: 60 }, drive: { _none: 30 } },
            savedAt: Date.now()
        };
        
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(testData));
            result.textContent += '[PASS] Write test succeeded\\n';
            
            // Test read
            const readBack = localStorage.getItem(STORAGE_KEY);
            const parsed = JSON.parse(readBack);
            if (parsed.accumulatedTime && parsed.savedAt) {
                result.textContent += '[PASS] Read test succeeded\\n';
                result.textContent += 'Data: ' + JSON.stringify(parsed, null, 2) + '\\n';
            }
            
            // Clean up test data
            localStorage.removeItem(STORAGE_KEY);
            result.textContent += '[PASS] Cleanup succeeded\\n';
            result.textContent += '\\nlocalStorage is working correctly!';
        } catch (e) {
            result.textContent += '[FAIL] localStorage error: ' + e.message;
        }
    }
    </script>";
    
    echo "</body></html>";
}
?>

