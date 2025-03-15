<?php

namespace Uzulla\McpPhpunit\PhpUnitRunner;

class PhpUnitRunner
{
    private string $projectPath;
    private string $phpunitBinary;

    public function __construct(string $projectPath, ?string $phpunitBinary = null)
    {
        $this->projectPath = realpath($projectPath);
        
        if ($phpunitBinary) {
            $this->phpunitBinary = $phpunitBinary;
        } else {
            // Try to find PHPUnit binary
            $vendorPhpunit = $this->projectPath . '/vendor/bin/phpunit';
            if (file_exists($vendorPhpunit)) {
                $this->phpunitBinary = $vendorPhpunit;
            } else {
                // Use global PHPUnit
                $this->phpunitBinary = 'phpunit';
            }
        }
        
        // Check if the project path exists
        if (!is_dir($this->projectPath)) {
            throw new \InvalidArgumentException("Project path does not exist: {$this->projectPath}");
        }
    }

    public function runTests(
        ?string $testPath = null,
        ?string $outputXml = null,
        ?string $filter = null,
        bool $verbose = false
    ): array {
        // Build the command
        $cmd = [$this->phpunitBinary];
        
        // Add verbose flag if requested
        if ($verbose) {
            $cmd[] = '--verbose';
        }
        
        // Add filter if specified
        if ($filter) {
            $cmd[] = '--filter';
            $cmd[] = $filter;
        }
        
        // Add output XML path if specified
        if ($outputXml) {
            $cmd[] = '--log-junit';
            $cmd[] = $outputXml;
        }
        
        // Add test path if specified
        if ($testPath) {
            $cmd[] = $testPath;
        }
        
        // Run the command
        try {
            $process = new \Symfony\Component\Process\Process($cmd);
            $process->setWorkingDirectory($this->projectPath);
            $process->run();
            
            $output = $process->getOutput() . $process->getErrorOutput();
            $returnCode = $process->getExitCode();
            
            // Read XML output if it was generated
            $xmlContent = null;
            if ($outputXml && file_exists($outputXml)) {
                $xmlContent = file_get_contents($outputXml);
            }
            
            return [$output, $returnCode, $xmlContent];
        } catch (\Exception $e) {
            return ["Error running PHPUnit: {$e->getMessage()}", 1, null];
        }
    }

    public function checkInstallation(): bool
    {
        try {
            $process = new \Symfony\Component\Process\Process([$this->phpunitBinary, '--version']);
            $process->setWorkingDirectory($this->projectPath);
            $process->run();
            
            return $process->getExitCode() === 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
