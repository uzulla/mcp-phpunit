<?php

namespace Uzulla\McpPhpunit\ErrorFormatter;

class PhpUnitErrorFormatter
{
    private int $maxErrorsPerBatch;

    public function __construct(int $maxErrorsPerBatch = 5)
    {
        $this->maxErrorsPerBatch = $maxErrorsPerBatch;
    }

    public function parsePhpunitXml(string $xmlContent): array
    {
        $errors = [];
        
        try {
            $xml = new \SimpleXMLElement($xmlContent);
            
            // Find all testcase elements with failures or errors
            foreach ($xml->xpath('//testsuite') as $testsuite) {
                foreach ($testsuite->xpath('//testcase') as $testcase) {
                    // Check if this testcase has a failure
                    $failure = $testcase->xpath('./failure');
                    $error = $testcase->xpath('./error');
                    
                    if (!empty($failure) || !empty($error)) {
                        // Use either failure or error element
                        $element = !empty($failure) ? $failure[0] : $error[0];
                        
                        // Extract error information
                        $testName = (string)$testcase['name'] ?? '';
                        $className = (string)$testcase['class'] ?? '';
                        $file = (string)$testcase['file'] ?? '';
                        $line = (int)($testcase['line'] ?? 0);
                        $errorType = (string)$element['type'] ?? '';
                        $message = trim((string)$element);
                        
                        // Extract line number from message if not in attributes
                        if ($line === 0 && $message) {
                            // Try to find line number in the message
                            if (preg_match('/\.php:(\d+)/', $message, $matches)) {
                                $line = (int)$matches[1];
                            }
                        }
                        
                        $errors[] = new PhpUnitError(
                            $message,
                            $file,
                            $line,
                            $testName,
                            $errorType,
                            $className
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Error parsing XML: {$e->getMessage()}");
        }
        
        return $errors;
    }

    public function formatForMcp(array $errors, int $batchIndex = 0): array
    {
        // Calculate the slice of errors for this batch
        $startIdx = $batchIndex * $this->maxErrorsPerBatch;
        $endIdx = $startIdx + $this->maxErrorsPerBatch;
        $batchErrors = array_slice($errors, $startIdx, $this->maxErrorsPerBatch);
        
        // Group errors by file
        $errorsByFile = [];
        foreach ($batchErrors as $error) {
            if (!isset($errorsByFile[$error->toArray()['file']])) {
                $errorsByFile[$error->toArray()['file']] = [];
            }
            $errorsByFile[$error->toArray()['file']][] = $error->toArray();
        }
        
        // Format for MCP
        return [
            'batch' => [
                'index' => $batchIndex,
                'total_errors' => count($errors),
                'batch_size' => count($batchErrors),
                'has_more' => $endIdx < count($errors)
            ],
            'errors_by_file' => $errorsByFile
        ];
    }

    public function getTotalBatches(int $totalErrors): int
    {
        return (int)ceil($totalErrors / $this->maxErrorsPerBatch);
    }
}
