# MCP PHPUnit Integration

This project provides integration between PHPUnit and Claude API. It allows you to run PHPUnit tests, detect failures, and report them to Claude API for assistance with fixing the issues.

## Features

- Run PHPUnit tests and capture failures
- Format test failures for Claude API
- Send formatted errors to Claude API
- Process errors incrementally in batches
- Apply fixes suggested by Claude API
- Sample PHP project with intentional test failures

## Installation

```bash
composer install
```

## Usage

```bash
php bin/mcp-phpunit /path/to/php/project [options]
```

### Options

- `--verbose, -v`: Run in verbose mode
- `--max-errors, -m`: Maximum errors per batch (default: 3)
- `--max-iterations, -i`: Maximum iterations to run (default: 10)
- `--mock`: Use mock Claude API (for testing)

## Demo Scripts

- `full-execution-demo.php`: Demonstrates the complete fix process with detailed logging
- `complete-fix-demo.php`: Shows how to fix the sample project's division by zero error
- `run-mcp-client.php`: Runs the McpClient on the sample project
- `fix-sample-project-final.php`: Applies a direct fix to the sample project

## Project Structure

- `src/PhpUnitRunner`: PHPUnit test execution
- `src/ErrorFormatter`: Parsing and formatting PHPUnit test failures
- `src/McpIntegration`: Communication with Claude API
- `src/Console`: Command-line interface
- `samples/php_project`: Sample PHP project with test failures
