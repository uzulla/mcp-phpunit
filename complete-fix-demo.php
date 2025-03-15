<?php

// Script to demonstrate the complete fix process for the sample project

// Path to the sample project
$sampleProjectPath = __DIR__ . '/samples/php_project';
$calculatorFile = $sampleProjectPath . '/src/Calculator.php';

echo "Demonstrating complete fix process for the sample project..." . PHP_EOL;

// First, run PHPUnit to show the failing test
echo "Initial PHPUnit run (should fail):" . PHP_EOL;
$command = "cd {$sampleProjectPath} && vendor/bin/phpunit tests/";
$output = shell_exec($command . ' 2>&1');
echo $output . PHP_EOL;

// Read the current Calculator.php file
$calculatorContent = file_get_contents($calculatorFile);
echo "Original Calculator.php content:" . PHP_EOL;
echo $calculatorContent . PHP_EOL;

// Create a backup of the original file
file_put_contents($calculatorFile . '.bak', $calculatorContent);
echo "Created backup at {$calculatorFile}.bak" . PHP_EOL;

// Apply the fix - add division by zero check
$fixedContent = str_replace(
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
file_put_contents($calculatorFile, $fixedContent);
echo "Applied fix to {$calculatorFile}" . PHP_EOL;
echo "Fixed Calculator.php content:" . PHP_EOL;
echo $fixedContent . PHP_EOL;

// Run PHPUnit again to verify the fix
echo "Final PHPUnit run (should pass):" . PHP_EOL;
$output = shell_exec($command . ' 2>&1');
echo $output . PHP_EOL;

// Check if tests pass
if (strpos($output, "OK") !== false) {
    echo "Success! All tests pass after applying the fix." . PHP_EOL;
} else {
    echo "Error: Tests still failing after applying the fix." . PHP_EOL;
}

// Verify the fix includes division by zero check
if (strpos($fixedContent, "if (\$b == 0)") !== false) {
    echo "Division by zero check successfully added." . PHP_EOL;
} else {
    echo "Warning: Division by zero check not found in the fixed file." . PHP_EOL;
}

// Restore the original file (to keep the sample project in its original state)
echo "Restoring original Calculator.php file..." . PHP_EOL;
file_put_contents($calculatorFile, $calculatorContent);
echo "Original file restored." . PHP_EOL;

echo "Fix demonstration completed." . PHP_EOL;
