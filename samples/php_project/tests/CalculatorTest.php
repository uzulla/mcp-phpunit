<?php

namespace Tests;

use App\Calculator;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase {
    public function testAdd() {
        $calculator = new Calculator();
        $this->assertEquals(4, $calculator->add(2, 2));
    }
    
    public function testSubtract() {
        $calculator = new Calculator();
        $this->assertEquals(0, $calculator->subtract(2, 2));
    }
    
    public function testMultiply() {
        $calculator = new Calculator();
        $this->assertEquals(6, $calculator->multiply(2, 3));
    }
    
    public function testDivide() {
        $calculator = new Calculator();
        $this->assertEquals(2, $calculator->divide(4, 2));
        // This will fail - division by zero
        $this->assertEquals(0, $calculator->divide(4, 0));
    }
}
