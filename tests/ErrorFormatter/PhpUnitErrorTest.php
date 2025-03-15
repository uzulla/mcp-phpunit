<?php

namespace Uzulla\McpPhpunit\Tests\ErrorFormatter;

use PHPUnit\Framework\TestCase;
use Uzulla\McpPhpunit\ErrorFormatter\PhpUnitError;

class PhpUnitErrorTest extends TestCase
{
    public function testToArray(): void
    {
        $error = new PhpUnitError(
            'Division by zero',
            'src/Calculator.php',
            42,
            'testDivide',
            'InvalidArgumentException',
            'CalculatorTest'
        );
        
        $array = $error->toArray();
        
        $this->assertIsArray($array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);
        $this->assertArrayHasKey('test_name', $array);
        $this->assertArrayHasKey('error_type', $array);
        $this->assertArrayHasKey('class_name', $array);
        
        $this->assertEquals('Division by zero', $array['message']);
        $this->assertEquals('src/Calculator.php', $array['file']);
        $this->assertEquals(42, $array['line']);
        $this->assertEquals('testDivide', $array['test_name']);
        $this->assertEquals('InvalidArgumentException', $array['error_type']);
        $this->assertEquals('CalculatorTest', $array['class_name']);
    }
}
