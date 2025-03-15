<?php

// Full execution demo script that shows the complete process with detailed logging

// Path to the sample project
$sampleProjectPath = __DIR__ . '/samples/php_project';
$calculatorFile = $sampleProjectPath . '/src/Calculator.php';

echo "====================================================================" . PHP_EOL;
echo "MCP PHPUnit Integration - Full Execution Demo" . PHP_EOL;
echo "====================================================================" . PHP_EOL;
echo PHP_EOL;

// Ensure the sample project is in its original state (with the bug)
echo "Step 1: Ensuring sample project is in its original state..." . PHP_EOL;
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
echo "✓ Reset Calculator.php to original state with division by zero bug" . PHP_EOL;
echo PHP_EOL;

// First, run PHPUnit to show the failing test
echo "Step 2: Running PHPUnit to verify the test fails..." . PHP_EOL;
$command = "cd {$sampleProjectPath} && vendor/bin/phpunit tests/";
$output = shell_exec($command . ' 2>&1');
echo $output . PHP_EOL;

// Check if the test fails as expected
if (strpos($output, "DivisionByZeroError") !== false) {
    echo "✓ Confirmed test fails with DivisionByZeroError as expected" . PHP_EOL;
} else {
    echo "✗ Test did not fail as expected" . PHP_EOL;
    exit(1);
}
echo PHP_EOL;

// Show the test file content
echo "Step 3: Examining the test file to understand the expected behavior..." . PHP_EOL;
$testFile = $sampleProjectPath . '/tests/CalculatorTest.php';
$testContent = file_get_contents($testFile);
echo $testContent . PHP_EOL;

// Analyze the test expectations
echo "✓ Test expects divide(4, 0) to return 0, not throw an exception" . PHP_EOL;
echo PHP_EOL;

// Create a backup of the original file
echo "Step 4: Creating backup of original file..." . PHP_EOL;
file_put_contents($calculatorFile . '.bak', $originalCalculatorContent);
echo "✓ Created backup at {$calculatorFile}.bak" . PHP_EOL;
echo PHP_EOL;

// Apply the fix - add division by zero check
echo "Step 5: Applying fix to Calculator.php..." . PHP_EOL;
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
    $originalCalculatorContent
);

// Write the fixed content back to the file
file_put_contents($calculatorFile, $fixedContent);
echo "✓ Applied fix to {$calculatorFile}" . PHP_EOL;
echo PHP_EOL;

// Show the fixed file content
echo "Step 6: Examining the fixed Calculator.php file..." . PHP_EOL;
echo $fixedContent . PHP_EOL;
echo "✓ Division by zero check added to return 0 when \$b is 0" . PHP_EOL;
echo PHP_EOL;

// Run PHPUnit again to verify the fix
echo "Step 7: Running PHPUnit again to verify the fix..." . PHP_EOL;
$output = shell_exec($command . ' 2>&1');
echo $output . PHP_EOL;

// Check if tests pass
if (strpos($output, "OK") !== false) {
    echo "✓ Success! All tests pass after applying the fix." . PHP_EOL;
} else {
    echo "✗ Error: Tests still failing after applying the fix." . PHP_EOL;
    exit(1);
}
echo PHP_EOL;

// Summary
echo "====================================================================" . PHP_EOL;
echo "Summary of Fix Process:" . PHP_EOL;
echo "====================================================================" . PHP_EOL;
echo "1. Identified division by zero error in Calculator.php" . PHP_EOL;
echo "2. Analyzed test expectations (return 0 for division by zero)" . PHP_EOL;
echo "3. Added division by zero check to Calculator.php" . PHP_EOL;
echo "4. Verified all tests now pass" . PHP_EOL;
echo PHP_EOL;

echo "Fix Details:" . PHP_EOL;
echo "------------" . PHP_EOL;
echo "Before:" . PHP_EOL;
echo "```php" . PHP_EOL;
echo "public function divide(\$a, \$b) {" . PHP_EOL;
echo "    // Intentional bug: no division by zero check" . PHP_EOL;
echo "    return \$a / \$b;" . PHP_EOL;
echo "}" . PHP_EOL;
echo "```" . PHP_EOL;
echo PHP_EOL;
echo "After:" . PHP_EOL;
echo "```php" . PHP_EOL;
echo "public function divide(\$a, \$b) {" . PHP_EOL;
echo "    // Check for division by zero" . PHP_EOL;
echo "    if (\$b == 0) {" . PHP_EOL;
echo "        return 0; // Return 0 for division by zero as expected by the test" . PHP_EOL;
echo "    }" . PHP_EOL;
echo "    return \$a / \$b;" . PHP_EOL;
echo "}" . PHP_EOL;
echo "```" . PHP_EOL;
echo PHP_EOL;

// Restore the original file (to keep the sample project in its original state)
echo "Step 8: Restoring original Calculator.php file..." . PHP_EOL;
file_put_contents($calculatorFile, $originalCalculatorContent);
echo "✓ Original file restored." . PHP_EOL;
echo PHP_EOL;

echo "====================================================================" . PHP_EOL;
echo "Full Execution Demo Completed Successfully" . PHP_EOL;
echo "====================================================================" . PHP_EOL;
