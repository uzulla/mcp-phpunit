<?php

// Script to run McpClient on the sample project with real Claude API

// Load environment variables from .env file
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Check if Claude API key is set
if (!isset($_ENV['CLAUDE_API_KEY']) || empty($_ENV['CLAUDE_API_KEY'])) {
    echo "Error: CLAUDE_API_KEY environment variable is not set. Please set it in your .env file." . PHP_EOL;
    exit(1);
}

// Find and load the Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Path to the sample project
$sampleProjectPath = __DIR__ . '/samples/php_project';
$calculatorFile = $sampleProjectPath . '/src/Calculator.php';

// Ensure the sample project is in its original state (with the bug)
echo "Ensuring sample project is in its original state..." . PHP_EOL;
$originalCalculatorContent = <<<'EOD'
<?php

namespace App;

class Calculator {
    public function add($a, $b) {
        return $a + $b;
    }
    
    public function subtract($a, $b) {
        return $a - $b;
    }
    
    public function multiply($a, $b) {
        return $a * $b;
    }
    
    public function divide($a, $b) {
        // Intentional bug: no division by zero check
        return $a / $b;
    }
}
EOD;

// Make sure the Calculator.php file has the bug
file_put_contents($calculatorFile, $originalCalculatorContent);
echo "Reset Calculator.php to original state with division by zero bug" . PHP_EOL;

// First, run PHPUnit to show the failing test
echo "Initial PHPUnit run (should fail):" . PHP_EOL;
$command = "cd {$sampleProjectPath} && vendor/bin/phpunit tests/";
$output = shell_exec($command . ' 2>&1');
echo $output . PHP_EOL;

// Create McpClient instance with real API
echo "Creating McpClient instance with real Claude API..." . PHP_EOL;
$mcpClient = new \Uzulla\McpPhpunit\McpIntegration\McpClient(
    $sampleProjectPath,
    3,        // Max errors per batch
    null,     // PHPUnit binary (auto-detect)
    false     // Use real API
);

// Process PHPUnit errors
echo "Processing PHPUnit errors with Claude API..." . PHP_EOL;
$success = $mcpClient->processPhpunitErrors(
    null,     // Test path (all tests)
    null,     // Filter
    true,     // Verbose
    3,        // Max iterations
    true      // Auto mode
);

echo $success ? "Success! All tests pass." : "Failed to fix all test failures." . PHP_EOL;

// Run PHPUnit again to verify the fix
echo "Final PHPUnit run (should pass):" . PHP_EOL;
$command = "cd {$sampleProjectPath} && vendor/bin/phpunit tests/";
$output = shell_exec($command . ' 2>&1');
echo $output . PHP_EOL;

// Show the fixed Calculator.php file
echo "Fixed Calculator.php file:" . PHP_EOL;
$fixedContent = file_get_contents($calculatorFile);
echo $fixedContent . PHP_EOL;

// Restore the original file (to keep the sample project in its original state)
echo "Restoring original Calculator.php file..." . PHP_EOL;
file_put_contents($calculatorFile, $originalCalculatorContent);
echo "Original file restored." . PHP_EOL;

echo "McpClient test completed." . PHP_EOL;
