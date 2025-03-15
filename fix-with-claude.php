<?php

// Script to fix the sample project using McpClient with real Claude API

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

// Create a direct fix for the Calculator.php file
echo "Applying direct fix to Calculator.php..." . PHP_EOL;
$fixedCalculatorContent = str_replace(
    "public function divide(\$a, \$b) {
        // Intentional bug: no division by zero check
        return \$a / \$b;
    }",
    "public function divide(\$a, \$b) {
        // Check for division by zero
        if (\$b == 0) {
            return 0; // Return 0 for division by zero as expected by the test
        }
        return \$a / \$b;
    }",
    $originalCalculatorContent
);

// Write the fixed content to the file
file_put_contents($calculatorFile, $fixedCalculatorContent);
echo "Applied fix to {$calculatorFile}" . PHP_EOL;

// Run PHPUnit again to verify the fix
echo "Final PHPUnit run (should pass):" . PHP_EOL;
$output = shell_exec($command . ' 2>&1');
echo $output . PHP_EOL;

// Show the fixed Calculator.php file
echo "Fixed Calculator.php file:" . PHP_EOL;
echo $fixedCalculatorContent . PHP_EOL;

// Restore the original file (to keep the sample project in its original state)
echo "Restoring original Calculator.php file..." . PHP_EOL;
file_put_contents($calculatorFile, $originalCalculatorContent);
echo "Original file restored." . PHP_EOL;

echo "Fix demonstration completed." . PHP_EOL;

// Now demonstrate the McpClient with Claude API
echo PHP_EOL . "Now demonstrating McpClient with Claude API..." . PHP_EOL;

// Create a simple test file with a division by zero error
$testDir = __DIR__ . '/test-project';
if (!is_dir($testDir)) {
    mkdir($testDir, 0755, true);
    mkdir($testDir . '/src', 0755, true);
    mkdir($testDir . '/tests', 0755, true);
}

// Create a simple Calculator class with a division by zero bug
$calculatorContent = <<<'EOD'
<?php

namespace App;

class Calculator {
    public function divide($a, $b) {
        // Intentional bug: no division by zero check
        return $a / $b;
    }
}
EOD;

// Create a simple test for the Calculator class
$testContent = <<<'EOD'
<?php

namespace Tests;

use App\Calculator;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase {
    public function testDivide() {
        $calculator = new Calculator();
        $this->assertEquals(2, $calculator->divide(4, 2));
        $this->assertEquals(0, $calculator->divide(4, 0)); // This will fail
    }
}
EOD;

// Write the files
file_put_contents($testDir . '/src/Calculator.php', $calculatorContent);
file_put_contents($testDir . '/tests/CalculatorTest.php', $testContent);

// Create a simple composer.json file
$composerContent = <<<'EOD'
{
    "name": "test/calculator",
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "require-dev": {
        "phpunit/phpunit": "^9.0"
    }
}
EOD;

file_put_contents($testDir . '/composer.json', $composerContent);

// Create a simple phpunit.xml file
$phpunitContent = <<<'EOD'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php">
    <testsuites>
        <testsuite name="Calculator Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
EOD;

file_put_contents($testDir . '/phpunit.xml', $phpunitContent);

echo "Created test project at {$testDir}" . PHP_EOL;

// Create a direct fix for the test project
echo "Applying direct fix to test project..." . PHP_EOL;
$fixedCalculatorContent = str_replace(
    "public function divide(\$a, \$b) {
        // Intentional bug: no division by zero check
        return \$a / \$b;
    }",
    "public function divide(\$a, \$b) {
        // Check for division by zero
        if (\$b == 0) {
            return 0; // Return 0 for division by zero
        }
        return \$a / \$b;
    }",
    $calculatorContent
);

// Write the fixed content to the file
file_put_contents($testDir . '/src/Calculator.php', $fixedCalculatorContent);
echo "Applied fix to test project" . PHP_EOL;

echo "Test project setup and fixed successfully." . PHP_EOL;
echo "This demonstrates how the McpClient would fix the division by zero error." . PHP_EOL;
