<?php

// Script to fix the Calculator.php file in the sample project to match test expectations

// Path to the sample project
$sampleProjectPath = __DIR__ . '/samples/php_project';
$calculatorFile = $sampleProjectPath . '/src/Calculator.php';
$testFile = $sampleProjectPath . '/tests/CalculatorTest.php';

echo "Fixing Calculator.php to match test expectations..." . PHP_EOL;

// First, run PHPUnit to show the failing test
echo "Initial PHPUnit run (should fail):" . PHP_EOL;
$command = "cd {$sampleProjectPath} && vendor/bin/phpunit tests/";
$output = shell_exec($command . ' 2>&1');
echo $output . PHP_EOL;

// Read the current Calculator.php file
$calculatorContent = file_get_contents($calculatorFile);
echo "Original Calculator.php content:" . PHP_EOL;
echo $calculatorContent . PHP_EOL;

// Read the current CalculatorTest.php file
$testContent = file_get_contents($testFile);
echo "Original CalculatorTest.php content:" . PHP_EOL;
echo $testContent . PHP_EOL;

// Create backups of the original files
file_put_contents($calculatorFile . '.bak', $calculatorContent);
echo "Created backup at {$calculatorFile}.bak" . PHP_EOL;

// Apply the fix to Calculator.php - add division by zero check that returns 0
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
    $calculatorContent
);

// Write the fixed content back to the file
file_put_contents($calculatorFile, $fixedCalculatorContent);
echo "Applied fix to {$calculatorFile}" . PHP_EOL;
echo "Fixed Calculator.php content:" . PHP_EOL;
echo $fixedCalculatorContent . PHP_EOL;

// Run PHPUnit again to verify the fix
echo "Final PHPUnit run (should pass):" . PHP_EOL;
$output = shell_exec($command . ' 2>&1');
echo $output . PHP_EOL;

// Restore the original file (to keep the sample project in its original state)
echo "Restoring original Calculator.php file..." . PHP_EOL;
file_put_contents($calculatorFile, $calculatorContent);
echo "Original file restored." . PHP_EOL;

echo "Fix demonstration completed." . PHP_EOL;
