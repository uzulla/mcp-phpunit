<?php

// Script to test the MCP PHPUnit integration on the sample project

// Find and load the Composer autoloader
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php'
];

$autoloaderFound = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    echo "Error: Could not find Composer autoloader. Please run 'composer install' first." . PHP_EOL;
    exit(1);
}

// Load environment variables from .env file
if (file_exists(__DIR__ . '/.env')) {
    echo "Loading environment variables from .env file..." . PHP_EOL;
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Path to the sample project
$sampleProjectPath = __DIR__ . '/samples/php_project';

echo "Running MCP PHPUnit integration on sample project at {$sampleProjectPath}" . PHP_EOL;

// Create McpClient instance
$mcpClient = new \Uzulla\McpPhpunit\McpIntegration\McpClient(
    $sampleProjectPath,
    3,        // Max errors per batch
    null,     // PHPUnit binary (auto-detect)
    true      // Use mock API for testing
);

// Process PHPUnit errors
$success = $mcpClient->processPhpunitErrors(
    null,     // Test path (all tests)
    null,     // Filter
    true,     // Verbose
    3,        // Max iterations
    true      // Auto mode
);

echo $success ? "Success! All tests pass." : "Failed to fix all test failures." . PHP_EOL;
