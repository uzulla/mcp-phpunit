<?php

namespace Uzulla\McpPhpunit\ErrorFormatter;

class PhpUnitError
{
    private string $message;
    private string $file;
    private int $line;
    private string $testName;
    private string $errorType;
    private string $className;

    public function __construct(
        string $message,
        string $file,
        int $line,
        string $testName,
        string $errorType,
        string $className
    ) {
        $this->message = $message;
        $this->file = $file;
        $this->line = $line;
        $this->testName = $testName;
        $this->errorType = $errorType;
        $this->className = $className;
    }

    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'test_name' => $this->testName,
            'error_type' => $this->errorType,
            'class_name' => $this->className
        ];
    }
}
