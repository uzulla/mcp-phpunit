<?php

namespace Uzulla\McpPhpunit\Tests\PhpUnitRunner;

use PHPUnit\Framework\TestCase;
use Uzulla\McpPhpunit\PhpUnitRunner\PhpUnitRunner;

class PhpUnitRunnerTest extends TestCase
{
    private string $testProjectPath;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testProjectPath = realpath(__DIR__ . '/../../samples/php_project');
    }
    
    public function testConstructor(): void
    {
        $runner = new PhpUnitRunner($this->testProjectPath);
        $this->assertInstanceOf(PhpUnitRunner::class, $runner);
    }
    
    public function testConstructorWithInvalidPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PhpUnitRunner('/path/that/does/not/exist');
    }
    
    public function testCheckInstallation(): void
    {
        $runner = new PhpUnitRunner($this->testProjectPath);
        
        // This test might fail if PHPUnit is not installed
        // We're just testing the method call, not the actual result
        $result = $runner->checkInstallation();
        $this->assertIsBool($result);
    }
}
