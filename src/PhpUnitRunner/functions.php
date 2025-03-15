<?php

namespace Uzulla\McpPhpunit\PhpUnitRunner;

function runPhpunit(
    string $projectPath,
    ?string $testPath = null,
    ?string $outputXml = null,
    ?string $filter = null,
    bool $verbose = false
): array {
    $runner = new PhpUnitRunner($projectPath);
    
    // Check if PHPUnit is installed
    if (!$runner->checkInstallation()) {
        $errorMsg = "PHPUnit is not installed or not found at the expected location. " .
                   "Please install PHPUnit globally or via Composer.";
        return [$errorMsg, 1, null];
    }
    
    // Run the tests
    return $runner->runTests($testPath, $outputXml, $filter, $verbose);
}
