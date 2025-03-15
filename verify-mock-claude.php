<?php

// Simple script to verify the Mock Claude API interaction

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

// Create a mock error message for division by zero
$message = [
    'errors_by_file' => [
        'src/Calculator.php' => [
            [
                'message' => 'Division by zero',
                'line' => 10,
                'test_name' => 'testDivide',
                'error_type' => 'Error',
                'class_name' => 'CalculatorTest'
            ]
        ]
    ]
];

// Create the MockClaudeApi instance
$mockApi = new \Uzulla\McpPhpunit\McpIntegration\MockClaudeApi();

// Get a response for the division by zero error
$response = $mockApi->getResponse($message);

// Display the response
echo "Mock Claude API Response:" . PHP_EOL;
echo "Status: " . $response['status'] . PHP_EOL;
echo "Message: " . substr($response['message'], 0, 100) . "..." . PHP_EOL;
echo "Number of fixes: " . count($response['fixes']) . PHP_EOL;

if (!empty($response['fixes'])) {
    echo PHP_EOL . "First fix details:" . PHP_EOL;
    echo "File: " . $response['fixes'][0]['file_path'] . PHP_EOL;
    echo "Search: " . substr($response['fixes'][0]['search'], 0, 50) . "..." . PHP_EOL;
    echo "Replace: " . substr($response['fixes'][0]['replace'], 0, 50) . "..." . PHP_EOL;
}

// Create a mock error message for syntax error
$message = [
    'errors_by_file' => [
        'tests/CalculatorTest.php' => [
            [
                'message' => 'syntax error, unexpected token ")"',
                'line' => 15,
                'test_name' => 'testDivide',
                'error_type' => 'ParseError',
                'class_name' => 'CalculatorTest'
            ]
        ]
    ]
];

// Get a response for the syntax error
$response = $mockApi->getResponse($message);

// Display the response
echo PHP_EOL . "Mock Claude API Response for Syntax Error:" . PHP_EOL;
echo "Status: " . $response['status'] . PHP_EOL;
echo "Message: " . substr($response['message'], 0, 100) . "..." . PHP_EOL;
echo "Number of fixes: " . count($response['fixes']) . PHP_EOL;

if (!empty($response['fixes'])) {
    echo PHP_EOL . "First fix details:" . PHP_EOL;
    echo "File: " . $response['fixes'][0]['file_path'] . PHP_EOL;
    echo "Search: " . substr($response['fixes'][0]['search'], 0, 50) . "..." . PHP_EOL;
    echo "Replace: " . substr($response['fixes'][0]['replace'], 0, 50) . "..." . PHP_EOL;
}

// Test McpClient with MockClaudeApi
echo PHP_EOL . "Testing McpClient with MockClaudeApi:" . PHP_EOL;

// Create the McpClient instance with mock API enabled
$mcpClient = new \Uzulla\McpPhpunit\McpIntegration\McpClient(
    __DIR__,  // Project path
    3,        // Max errors per batch
    null,     // PHPUnit binary (auto-detect)
    true      // Use mock API
);

// Prepare a mock message
$batch = [
    'batch' => [
        'index' => 0,
        'total_errors' => 1,
        'batch_size' => 1,
        'has_more' => false
    ],
    'errors_by_file' => [
        'src/Calculator.php' => [
            [
                'message' => 'Division by zero',
                'line' => 10,
                'test_name' => 'testDivide',
                'error_type' => 'Error',
                'class_name' => 'CalculatorTest'
            ]
        ]
    ]
];

// Prepare the MCP message
$message = $mcpClient->prepareMcpMessage($batch);

// Send to Claude (which will use the mock API)
$response = $mcpClient->sendToClaude($message);

// Display the response
echo "McpClient Response:" . PHP_EOL;
echo "Status: " . $response['status'] . PHP_EOL;
echo "Message: " . substr($response['message'], 0, 100) . "..." . PHP_EOL;
echo "Number of fixes: " . count($response['fixes']) . PHP_EOL;

echo PHP_EOL . "Mock Claude API verification completed successfully!" . PHP_EOL;
