<?php

namespace Uzulla\McpPhpunit\McpIntegration;

class MockClaudeApi
{
    private array $responses = [];
    
    public function __construct()
    {
        // Default mock responses for common PHP errors
        $this->responses = [
            'division_by_zero' => [
                'status' => 'success',
                'message' => "I've analyzed the PHPUnit test failures and found a division by zero error in the Calculator class. Here's my recommendation:\n\n" .
                             "The issue is in the divide() method of the Calculator class. It doesn't check for division by zero, which causes a PHP error.\n\n" .
                             "To fix this, we need to add a check for division by zero and throw an exception when it occurs.",
                'fixes' => [
                    [
                        'file_path' => 'src/Calculator.php',
                        'search' => "    public function divide(\$a, \$b) {\n        // Intentional bug: no division by zero check\n        return \$a / \$b;\n    }",
                        'replace' => "    public function divide(\$a, \$b) {\n        // Check for division by zero\n        if (\$b == 0) {\n            throw new \\InvalidArgumentException('Division by zero is not allowed');\n        }\n        return \$a / \$b;\n    }"
                    ]
                ]
            ],
            'syntax_error' => [
                'status' => 'success',
                'message' => "I've analyzed the PHPUnit test failures and found a syntax error in the PHP code. Here's my recommendation:\n\n" .
                             "There's a syntax error in the code, likely a missing bracket, semicolon, or parenthesis.",
                'fixes' => [
                    [
                        'file_path' => 'tests/CalculatorTest.php',
                        'search' => "    public function testDivide() {\n        \$calculator = new Calculator();\n        \$this->assertEquals(2, \$calculator->divide(4, 2));\n        // This will fail - division by zero\n        \$this->assertEquals(0, \$calculator->divide(4, 0));\n    }",
                        'replace' => "    public function testDivide() {\n        \$calculator = new Calculator();\n        \$this->assertEquals(2, \$calculator->divide(4, 2));\n        \n        // Test division by zero exception\n        \$this->expectException(\\InvalidArgumentException::class);\n        \$calculator->divide(4, 0);\n    }"
                    ]
                ]
            ]
        ];
    }
    
    public function addResponse(string $key, array $response): void
    {
        $this->responses[$key] = $response;
    }
    
    public function getResponse(array $message): array
    {
        // Analyze the message to determine which mock response to return
        $errorDetails = '';
        
        if (isset($message['errors_by_file'])) {
            foreach ($message['errors_by_file'] as $filePath => $errors) {
                foreach ($errors as $error) {
                    $errorDetails .= $error['message'] ?? '';
                }
            }
        }
        
        // Check for division by zero errors
        if (strpos($errorDetails, 'division by zero') !== false || 
            strpos($errorDetails, 'divide') !== false) {
            return $this->responses['division_by_zero'];
        }
        
        // Check for syntax errors
        if (strpos($errorDetails, 'syntax error') !== false) {
            return $this->responses['syntax_error'];
        }
        
        // Default response if no specific error is detected
        return [
            'status' => 'success',
            'message' => "I've analyzed the PHPUnit test failures but couldn't determine a specific fix. Please check your code manually.",
            'fixes' => []
        ];
    }
}
