<?php

namespace Uzulla\McpPhpunit\McpIntegration;

use Uzulla\McpPhpunit\PhpUnitRunner\PhpUnitRunner;
use Uzulla\McpPhpunit\ErrorFormatter\PhpUnitErrorFormatter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class McpClient
{
    private string $projectPath;
    private int $maxErrorsPerBatch;
    private PhpUnitRunner $phpunitRunner;
    private PhpUnitErrorFormatter $errorFormatter;
    private ?MockClaudeApi $mockClaudeApi;
    
    public function __construct(
        string $projectPath,
        int $maxErrorsPerBatch = 3,
        ?string $phpunitBinary = null,
        bool $useMockApi = false
    ) {
        $this->projectPath = realpath($projectPath);
        $this->maxErrorsPerBatch = $maxErrorsPerBatch;
        
        // Initialize PHPUnit runner
        $this->phpunitRunner = new PhpUnitRunner($projectPath, $phpunitBinary);
        
        // Initialize error formatter
        $this->errorFormatter = new PhpUnitErrorFormatter($maxErrorsPerBatch);
        
        // Initialize mock API if requested
        $this->mockClaudeApi = $useMockApi ? new MockClaudeApi() : null;
    }
    
    public function runPhpunitTests(
        ?string $testPath = null,
        ?string $filter = null,
        bool $verbose = false
    ): array {
        // Create a temporary file for XML output
        $xmlOutputPath = tempnam(sys_get_temp_dir(), 'phpunit_');
        
        try {
            // Run PHPUnit tests
            list($output, $returnCode, $xmlContent) = $this->phpunitRunner->runTests(
                $testPath,
                $xmlOutputPath,
                $filter,
                $verbose
            );
            
            // If tests passed or XML output is missing, return early
            if ($returnCode === 0 || !$xmlContent) {
                return [$output, $returnCode, []];
            }
            
            // Parse the errors
            $errors = $this->errorFormatter->parsePhpunitXml($xmlContent);
            
            // Calculate total batches
            $totalBatches = $this->errorFormatter->getTotalBatches(count($errors));
            
            // Format errors into batches
            $batches = [];
            for ($batchIdx = 0; $batchIdx < $totalBatches; $batchIdx++) {
                $formatted = $this->errorFormatter->formatForMcp($errors, $batchIdx);
                $batches[] = $formatted;
            }
            
            return [$output, $returnCode, $batches];
        } finally {
            // Clean up the temporary file
            if (file_exists($xmlOutputPath)) {
                unlink($xmlOutputPath);
            }
        }
    }
    
    public function prepareMcpMessage(
        array $batch,
        ?array $fileContents = null
    ): array {
        // If file_contents not provided, read the files
        if ($fileContents === null) {
            $fileContents = [];
            foreach (array_keys($batch['errors_by_file']) as $filePath) {
                // Skip vendor files
                if (strpos($filePath, 'vendor') !== false) {
                    continue;
                }
                
                $fullPath = $this->projectPath . '/' . $filePath;
                if (file_exists($fullPath)) {
                    $fileContents[$filePath] = file_get_contents($fullPath);
                }
            }
            
            // Make sure src/Calculator.php and tests/CalculatorTest.php are included
            $keyFiles = [
                'src/Calculator.php',
                'tests/CalculatorTest.php'
            ];
            
            foreach ($keyFiles as $keyFile) {
                if (!isset($fileContents[$keyFile])) {
                    $fullPath = $this->projectPath . '/' . $keyFile;
                    if (file_exists($fullPath)) {
                        $fileContents[$keyFile] = file_get_contents($fullPath);
                    }
                }
            }
        }
        
        // Prepare the MCP message
        $message = [
            'type' => 'mcp_phpunit_errors',
            'batch_info' => $batch['batch'],
            'errors_by_file' => $batch['errors_by_file'],
            'file_contents' => $fileContents,
            'project_path' => $this->projectPath
        ];
        
        return $message;
    }
    
    public function sendToClaude(array $message): array
    {
        echo "Sending message to Claude Code via MCP...\n";
        
        // If using mock API, return mock response
        if ($this->mockClaudeApi !== null) {
            return $this->mockClaudeApi->getResponse($message);
        }
        
        // Check for API key in environment
        $apiKey = getenv('CLAUDE_API_KEY');
        if (!$apiKey) {
            echo "Error: CLAUDE_API_KEY environment variable not set\n";
            return [
                'status' => 'error',
                'message' => 'CLAUDE_API_KEY environment variable not set. Please set your Claude API key.'
            ];
        }
        
        // Extract error data from the message to enhance Claude's context
        $errorDetails = '';
        foreach ($message['errors_by_file'] as $filePath => $errors) {
            $errorDetails .= "\nErrors in {$filePath}:\n";
            foreach ($errors as $error) {
                $errorDetails .= "- Line {$error['line'] ?? 'unknown'}: {$error['message'] ?? 'No message'}\n";
            }
        }
        
        // First, let's run PHPUnit directly to get the actual error message
        echo "Running PHPUnit to capture error messages...\n";
        $phpunitErrorOutput = $this->runPhpunitAndGetOutput();
        
        // Claude API endpoint
        $apiUrl = 'https://api.anthropic.com/v1/messages';
        
        // Prepare request headers
        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json'
        ];
        
        // Format the message for Claude API with a specific task to analyze PHPUnit errors
        $claudeMessage = [
            'model' => 'claude-3-opus-20240229',
            'max_tokens' => 4000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "You are a PHP expert analyzing PHPUnit test failures and helping fix them."
                    // Content truncated for brevity - full prompt in previous implementation
                ]
            ]
        ];
        
        try {
            // Make API request
            $client = new Client();
            $response = $client->post($apiUrl, [
                'headers' => $headers,
                'json' => $claudeMessage
            ]);
            
            // Parse and return Claude's response
            $claudeResponse = json_decode($response->getBody(), true);
            $claudeText = isset($claudeResponse['content'][0]['text']) ? $claudeResponse['content'][0]['text'] : 'No content in response';
            
            // Extract file modifications from Claude's response
            $fixes = [];
            $fixPattern = '/FILE_TO_MODIFY:\s*([^\n]+)\nSEARCH:\s*```[^\n]*\n(.*?)```\nREPLACE:\s*```[^\n]*\n(.*?)```/s';
            
            if (preg_match_all($fixPattern, $claudeText, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $fixes[] = [
                        'file_path' => trim($match[1]),
                        'search' => trim($match[2]),
                        'replace' => trim($match[3])
                    ];
                }
            }
            
            return [
                'status' => 'success',
                'message' => $claudeText,
                'fixes' => $fixes
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => "Error communicating with Claude API: {$e->getMessage()}"
            ];
        }
    }
    
    public function runPhpunitAndGetOutput(?string $testPath = null): string
    {
        try {
            // Find the PHPUnit executable
            $possiblePhpunitPaths = [
                $this->projectPath . '/vendor/bin/phpunit',
                $this->projectPath . '/vendor/phpunit/phpunit/phpunit'
            ];
            
            $phpunitPath = null;
            foreach ($possiblePhpunitPaths as $path) {
                if (file_exists($path)) {
                    $phpunitPath = $path;
                    break;
                }
            }
            
            if (!$phpunitPath) {
                return "Could not find PHPUnit executable";
            }
            
            // Determine test paths
            $testPaths = [];
            if ($testPath) {
                $testPaths[] = $this->projectPath . '/' . $testPath;
            } else {
                $possibleTestDirs = [
                    $this->projectPath . '/tests',
                    $this->projectPath . '/test'
                ];
                
                foreach ($possibleTestDirs as $dir) {
                    if (is_dir($dir)) {
                        $testPaths[] = $dir;
                        break;
                    }
                }
            }
            
            if (empty($testPaths)) {
                return "No test paths found";
            }
            
            // Run PHPUnit
            $cmd = array_merge([$phpunitPath], $testPaths);
            $process = new \Symfony\Component\Process\Process($cmd);
            $process->setWorkingDirectory($this->projectPath);
            $process->run();
            
            return $process->getOutput() . $process->getErrorOutput();
        } catch (\Exception $e) {
            return "Error running PHPUnit: {$e->getMessage()}";
        }
    }
    
    public function checkPhpSyntax(string $code): bool
    {
        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'php_syntax_');
        file_put_contents($tempFile, $code);
        
        try {
            // Check syntax using PHP's lint mode
            $process = new \Symfony\Component\Process\Process(['php', '-l', $tempFile]);
            $process->run();
            
            // Clean up
            unlink($tempFile);
            
            // Return true if syntax is valid
            return $process->getExitCode() === 0;
        } catch (\Exception $e) {
            // Clean up
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            
            return false;
        }
    }
    
    public function applyFix(array $fix): bool
    {
        // Normalize the file path (sometimes it could start with '/' or './')
        $normalizedPath = ltrim($fix['file_path'], './');
        
        // Try multiple ways to find the file
        $possiblePaths = [
            $this->projectPath . '/' . $normalizedPath,  // Standard path
            $normalizedPath,                             // Maybe it's already absolute
            realpath($normalizedPath)                    // Just in case it's relative to CWD
        ];
        
        // Find the first existing file that's not in vendor directory
        $filePath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path) && strpos($path, 'vendor') === false) {
                $filePath = $path;
                break;
            }
        }
        
        // If no file found, try more targeted search in non-vendor directories,
        // prioritizing src/ and tests/ directories
        if ($filePath === null) {
            // Try to find by basename in the project source directories only
            $basename = basename($normalizedPath);
            
            // First look in src/ directory
            $srcPath = $this->projectPath . '/src/' . $basename;
            if (file_exists($srcPath)) {
                $filePath = $srcPath;
                echo "Found file in src directory: {$filePath}\n";
            }
            
            // If not found, look in tests/ directory
            if ($filePath === null) {
                $testsPath = $this->projectPath . '/tests/' . $basename;
                if (file_exists($testsPath)) {
                    $filePath = $testsPath;
                    echo "Found file in tests directory: {$filePath}\n";
                }
            }
            
            // If still not found, look in other non-vendor directories
            if ($filePath === null) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($this->projectPath)
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getFilename() === $basename) {
                        $candidatePath = $file->getPathname();
                        // Double check it's not in vendor
                        if (strpos($candidatePath, 'vendor') === false) {
                            $filePath = $candidatePath;
                            echo "Found file by basename: {$filePath}\n";
                            break;
                        }
                    }
                }
            }
        }
        
        // If still no file, give up
        if ($filePath === null) {
            echo "Error: File {$normalizedPath} does not exist\n";
            echo "Tried paths: " . implode(', ', $possiblePaths) . "\n";
            return false;
        }
        
        try {
            echo "Using file path: {$filePath}\n";
            
            // Read the file content
            $content = file_get_contents($filePath);
            
            // Make a backup of the file
            $backupPath = "{$filePath}.bak";
            file_put_contents($backupPath, $content);
            echo "Created backup at {$backupPath}\n";
            
            // First try exact match
            if (strpos($content, $fix['search']) !== false) {
                // Replace the search text with the replace text
                $newContent = str_replace($fix['search'], $fix['replace'], $content);
                
                // Check PHP syntax before writing the file
                $isValid = $this->checkPhpSyntax($newContent);
                
                if (!$isValid) {
                    echo "WARNING: The proposed fix contains PHP syntax errors\n";
                    
                    // Try to automatically fix common syntax errors
                    $patterns = [
                        '/public\s+public\s+/' => 'public ',
                        '/protected\s+protected\s+/' => 'protected ',
                        '/private\s+private\s+/' => 'private ',
                        '/public\s+protected\s+/' => 'protected ',
                        '/protected\s+public\s+/' => 'protected ',
                        '/public\s+private\s+/' => 'private ',
                        '/private\s+public\s+/' => 'private '
                    ];
                    
                    $fixedContent = $newContent;
                    foreach ($patterns as $pattern => $replacement) {
                        $fixedContent = preg_replace($pattern, $replacement, $fixedContent);
                    }
                    
                    // Check if the fixed content is valid
                    $isValidAfterFix = $this->checkPhpSyntax($fixedContent);
                    
                    if ($isValidAfterFix) {
                        // Write the fixed content
                        file_put_contents($filePath, $fixedContent);
                        echo "Successfully applied fix to {$fix['file_path']} and corrected PHP syntax errors\n";
                        return true;
                    } else {
                        echo "Could not fully correct PHP syntax errors. Skipping.\n";
                        return false;
                    }
                } else {
                    // No syntax errors, write the new content
                    file_put_contents($filePath, $newContent);
                    echo "Successfully applied fix to {$fix['file_path']} (exact match)\n";
                    return true;
                }
            }
            
            // If exact match fails, try with more flexible approaches
            // Try to extract a method name from the search string
            $functionName = null;
            
            // Look for PHP methods/functions
            $patterns = [
                '/function\s+(\w+)/',
                '/public\s+function\s+(\w+)/',
                '/private\s+function\s+(\w+)/',
                '/protected\s+function\s+(\w+)/'
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $fix['search'], $matches)) {
                    $functionName = $matches[1];
                    break;
                }
            }
            
            if ($functionName) {
                echo "Extracted method name: {$functionName}\n";
                
                // If we found a method name, look for this method in the file
                $methodPatterns = [
                    // Try various method patterns
                    "/function\s+{$functionName}\s*\([^{]*\{.*?\}/s",  // Basic function
                    "/public\s+function\s+{$functionName}\s*\([^{]*\{.*?\}/s",  // Public method
                    "/private\s+function\s+{$functionName}\s*\([^{]*\{.*?\}/s",  // Private method
                    "/protected\s+function\s+{$functionName}\s*\([^{]*\{.*?\}/s"  // Protected method
                ];
                
                foreach ($methodPatterns as $pattern) {
                    $matches = [];
                    if (preg_match_all($pattern, $content, $matches) === 1) {
                        $matchedMethod = $matches[0][0];
                        $newContent = str_replace($matchedMethod, $fix['replace'], $content);
                        
                        // Check PHP syntax before writing the file
                        $isValid = $this->checkPhpSyntax($newContent);
                        
                        if (!$isValid) {
                            echo "WARNING: The proposed fix contains PHP syntax errors\n";
                            return false;
                        } else {
                            // No syntax errors, write the new content
                            file_put_contents($filePath, $newContent);
                            echo "Successfully applied fix to method {$functionName}\n";
                            return true;
                        }
                    }
                }
            }
            
            // If we get here, we couldn't find a match
            echo "Error: Could not find the target code in {$filePath}\n";
            echo "Expected to find:\n{$fix['search']}\n";
            return false;
        } catch (\Exception $e) {
            echo "Error applying fix to {$filePath}: {$e->getMessage()}\n";
            return false;
        }
    }
    
    public function processPhpunitErrors(
        ?string $testPath = null,
        ?string $filter = null,
        bool $verbose = false,
        int $maxIterations = 10,
        bool $autoMode = false
    ): bool {
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            echo "\nIteration " . ($iteration + 1) . "/{$maxIterations}\n";
            
            // Get current PHPUnit output (will be used to show errors for manual fixes)
            $phpunitOutput = $this->runPhpunitAndGetOutput($testPath);
            echo "\nCurrent PHPUnit output:\n";
            echo str_repeat('-', 50) . "\n";
            
            // Print first 15 lines of output
            $outputLines = explode("\n", $phpunitOutput);
            $maxLines = min(15, count($outputLines));
            for ($i = 0; $i < $maxLines; $i++) {
                echo $outputLines[$i] . "\n";
            }
            if (count($outputLines) > $maxLines) {
                echo "... (more output not shown)\n";
            }
            echo str_repeat('-', 50) . "\n";
            
            // Run PHPUnit tests
            list($output, $returnCode, $batches) = $this->runPhpunitTests($testPath, $filter, $verbose);
            
            // If tests pass, we're done
            if ($returnCode === 0) {
                echo "All PHPUnit tests pass!\n";
                return true;
            }
            
            // If there are failures, process them in batches
            echo "Found " . count($batches) . " batches of test failures\n";
            
            foreach ($batches as $batchIdx => $batch) {
                echo "\nProcessing batch " . ($batchIdx + 1) . "/" . count($batches) . "\n";
                
                // Prepare MCP message
                $message = $this->prepareMcpMessage($batch);
                
                // Add the current PHPUnit output to the message
                $message['phpunit_output'] = $phpunitOutput;
                
                // Send to Claude
                $response = $this->sendToClaude($message);
                
                // Display Claude's suggested fixes in a more user-friendly format
                if ($response['status'] === 'success') {
                    echo "\n============= CLAUDE'S ANALYSIS AND SUGGESTED FIXES =============\n";
                    $suggestion = $response['message'];
                    echo $suggestion . "\n";
                    echo "\n==============================================================\n";
                    
                    // Check if we have structured fixes
                    if (!empty($response['fixes'])) {
                        echo "\nClaude suggested " . count($response['fixes']) . " fixes to apply\n";
                        
                        // Debug: Show the raw fixes for inspection
                        echo "\n===== RAW FIX DETAILS FOR DEBUGGING =====\n";
                        foreach ($response['fixes'] as $fixIdx => $fix) {
                            echo "\nFix " . ($fixIdx + 1) . ":\n";
                            echo "File: {$fix['file_path']}\n";
                            echo "Search:\n";
                            echo $fix['search'] . "\n";
                            echo "Replace:\n";
                            echo $fix['replace'] . "\n";
                            
                            // Check this fix for known issues
                            if (preg_match('/(public|private|protected)\s+(public|private|protected)\s+/', $fix['replace'], $matches)) {
                                echo "WARNING: Detected duplicate access modifiers in fix: {$matches[0]}\n";
                            }
                        }
                        echo "=========================================\n";
                        
                        // List files that will be modified
                        $filesToModify = array_unique(array_column($response['fixes'], 'file_path'));
                        echo "\nFiles that would be modified:\n";
                        foreach ($filesToModify as $file) {
                            echo "  - {$file}\n";
                        }
                        
                        // In auto_mode, apply the fixes automatically without asking
                        if ($autoMode) {
                            echo "\nAuto mode enabled. Applying fixes automatically...\n";
                            $appliedFixes = [];
                            foreach ($response['fixes'] as $fixIdx => $fix) {
                                echo "\nApplying fix " . ($fixIdx + 1) . "/" . count($response['fixes']) . ":\n";
                                
                                // Pre-check for common PHP syntax issues before attempting to apply
                                
                                // Check for duplicate access modifiers with more patterns
                                // First do a direct check for the known problematic pattern
                                if (strpos($fix['replace'], 'public public function') !== false) {
                                    echo "WARNING: Fix contains 'public public function'. Fixing...\n";
                                    $fix['replace'] = str_replace('public public function', 'public function', $fix['replace']);
                                    echo "Modified replace content:\n";
                                    echo $fix['replace'] . "\n";
                                }
                                
                                // Then check for other variations with regex
                                $patterns = [
                                    '/public\s+public\s+/' => 'public ',
                                    '/protected\s+protected\s+/' => 'protected ',
                                    '/private\s+private\s+/' => 'private ',
                                    '/public\s+protected\s+/' => 'protected ',
                                    '/protected\s+public\s+/' => 'protected ',
                                    '/public\s+private\s+/' => 'private ',
                                    '/private\s+public\s+/' => 'private '
                                ];
                                
                                foreach ($patterns as $pattern => $replacement) {
                                    if (preg_match($pattern, $fix['replace'])) {
                                        echo "WARNING: Fix contains duplicate modifiers: '{$pattern}'. Fixing...\n";
                                        $fix['replace'] = preg_replace($pattern, $replacement, $fix['replace']);
                                        echo "Modified replace content:\n";
                                        echo $fix['replace'] . "\n";
                                    }
                                }
                                
                                $success = $this->applyFix($fix);
                                if ($success) {
                                    $appliedFixes[] = $fix;
                                }
                            }
                            
                            if (!empty($appliedFixes)) {
                                // After applying fixes, run PHPUnit again to see if we've made progress
                                echo "\nRunning PHPUnit after applying fixes...\n";
                                $newPhpunitOutput = $this->runPhpunitAndGetOutput($testPath);
                                echo "\nPHPUnit output after fixes:\n";
                                echo str_repeat('-', 50) . "\n";
                                
                                // Print first 15 lines of new output
                                $newOutputLines = explode("\n", $newPhpunitOutput);
                                $maxLines = min(15, count($newOutputLines));
                                for ($i = 0; $i < $maxLines; $i++) {
                                    echo $newOutputLines[$i] . "\n";
                                }
                                if (count($newOutputLines) > $maxLines) {
                                    echo "... (more output not shown)\n";
                                }
                                echo str_repeat('-', 50) . "\n";
                            }
                        } else {
                            // Ask the user if they want to apply the suggested fixes
                            try {
                                echo "\nWould you like to apply these fixes? (y/n): ";
                                $userInput = trim(fgets(STDIN));
                                
                                if (strtolower($userInput) === 'y' || strtolower($userInput) === 'yes') {
                                    echo "\nApplying fixes...\n";
                                    $appliedFixes = [];
                                    foreach ($response['fixes'] as $fixIdx => $fix) {
                                        echo "\nApplying fix " . ($fixIdx + 1) . "/" . count($response['fixes']) . ":\n";
                                        $success = $this->applyFix($fix);
                                        if ($success) {
                                            $appliedFixes[] = $fix;
                                        }
                                    }
                                    
                                    if (!empty($appliedFixes)) {
                                        // After applying fixes, run PHPUnit again to see if we've made progress
                                        echo "\nRunning PHPUnit after applying fixes...\n";
                                        $newPhpunitOutput = $this->runPhpunitAndGetOutput($testPath);
                                        echo "\nPHPUnit output after fixes:\n";
                                        echo str_repeat('-', 50) . "\n";
                                        
                                        // Print first 15 lines of new output
                                        $newOutputLines = explode("\n", $newPhpunitOutput);
                                        $maxLines = min(15, count($newOutputLines));
                                        for ($i = 0; $i < $maxLines; $i++) {
                                            echo $newOutputLines[$i] . "\n";
                                        }
                                        if (count($newOutputLines) > $maxLines) {
                                            echo "... (more output not shown)\n";
                                        }
                                        echo str_repeat('-', 50) . "\n";
                                    }
                                } else {
                                    echo "\nYou chose not to apply the fixes. Continuing to the next batch...\n";
                                }
                            } catch (\Exception $e) {
                                // If input fails, fall back to auto_mode behavior
                                echo "\nInput not available. Applying fixes automatically...\n";
                                $appliedFixes = [];
                                foreach ($response['fixes'] as $fixIdx => $fix) {
                                    echo "\nApplying fix " . ($fixIdx + 1) . "/" . count($response['fixes']) . ":\n";
                                    $success = $this->applyFix($fix);
                                    if ($success) {
                                        $appliedFixes[] = $fix;
                                    }
                                }
                                
                                if (!empty($appliedFixes)) {
                                    // After applying fixes, run PHPUnit again to see if we've made progress
                                    echo "\nRunning PHPUnit after applying fixes...\n";
                                    $newPhpunitOutput = $this->runPhpunitAndGetOutput($testPath);
                                    echo "\nPHPUnit output after fixes:\n";
                                    echo str_repeat('-', 50) . "\n";
                                    echo (strlen($newPhpunitOutput) > 500 ? substr($newPhpunitOutput, 0, 500) . "..." : $newPhpunitOutput) . "\n";
                                    echo str_repeat('-', 50) . "\n";
                                }
                            }
                        }
                    } else {
                        // If no structured fixes were extracted, fall back to the previous behavior
                        echo "\nNo structured fixes were found in Claude's response.\n";
                        echo "You may need to apply fixes manually or try again.\n";
                        
                        // Identify files that might need to be modified based on the suggestion
                        $potentialFiles = [];
                        foreach (explode("\n", $suggestion) as $line) {
                            if (strpos($line, '.php') !== false) {
                                $parts = explode(' ', $line);
                                foreach ($parts as $part) {
                                    if (strpos($part, '.php') !== false) {
                                        // Extract just the filename part
                                        $filename = trim($part, '."\'(),;:');
                                        if (substr($filename, -4) === '.php') {
                                            $potentialFiles[] = $filename;
                                        }
                                    }
                                }
                            }
                        }
                        
                        if (!empty($potentialFiles)) {
                            echo "\nFiles that might need to be modified (based on Claude's analysis):\n";
                            foreach (array_unique($potentialFiles) as $file) {
                                echo "  - {$file}\n";
                            }
                        }
                    }
                } else {
                    echo "\nClaude encountered an error with the following message:\n";
                    echo json_encode($response, JSON_PRETTY_PRINT) . "\n";
                }
            }
            
            // Increment iteration counter
            $iteration++;
        }
        
        // If we've reached max_iterations, we've failed to fix all errors
        echo "Reached maximum iterations ({$maxIterations}) without fixing all test failures\n";
        return false;
    }
}
