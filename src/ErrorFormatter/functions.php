<?php

namespace Uzulla\McpPhpunit\ErrorFormatter;

function formatPhpunitOutput(
    string $phpunitXml,
    int $maxErrorsPerBatch = 5,
    int $batchIndex = 0
): string {
    $formatter = new PhpUnitErrorFormatter($maxErrorsPerBatch);
    $errors = $formatter->parsePhpunitXml($phpunitXml);
    $formatted = $formatter->formatForMcp($errors, $batchIndex);
    return json_encode($formatted, JSON_PRETTY_PRINT);
}
