# MCP PHPUnit Integration Demo

This document provides a demonstration of how the MCP PHPUnit Integration works to automatically fix failing tests.

## Sample Project

The sample project in `samples/php_project` contains a simple `Calculator` class with an intentional bug in the `divide` method - it doesn't check for division by zero. The test for this method expects `divide(4, 0)` to return `0`, but without the check, it throws a `DivisionByZeroError`.

## Fix Process

The MCP PHPUnit Integration performs the following steps to fix the failing test:

1. Run PHPUnit tests to identify failures
2. Parse the PHPUnit output to locate the failing test and error
3. Analyze the test expectations and error message
4. Send the error information to Claude API
5. Receive a fix suggestion from Claude API
6. Apply the fix to the code
7. Run the tests again to verify the fix

## Demo Scripts

### Full Execution Demo

The `full-execution-demo.php` script demonstrates the complete fix process with detailed logging:

```bash
php full-execution-demo.php
```

This script:
- Resets the sample project to its original state
- Runs PHPUnit to show the failing test
- Examines the test file to understand the expected behavior
- Applies a fix to the Calculator.php file
- Runs PHPUnit again to verify the fix
- Restores the original file

### Complete Fix Demo

The `complete-fix-demo.php` script shows a simpler version of the fix process:

```bash
php complete-fix-demo.php
```

### Run McpClient

The `run-mcp-client.php` script demonstrates using the McpClient class to fix the sample project:

```bash
php run-mcp-client.php
```

## Fix Details

The fix adds a division by zero check to the `divide` method:

Before:
```php
public function divide($a, $b) {
    // Intentional bug: no division by zero check
    return $a / $b;
}
```

After:
```php
public function divide($a, $b) {
    // Check for division by zero
    if ($b == 0) {
        return 0; // Return 0 for division by zero as expected by the test
    }
    return $a / $b;
}
```

This fix makes the test pass because it returns `0` when dividing by zero, which is what the test expects.
