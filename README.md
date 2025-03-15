# MCP PHPUnit Integration

This project provides integration between PHPUnit and Claude Code via MCP (Multi-agent Conversation Protocol). It allows you to run PHPUnit tests, detect failures, and report them to Claude Code for assistance with fixing the issues.

## Features

- Run PHPUnit tests and capture failures
- Format test failures for Claude Code
- Send formatted errors to Claude Code via MCP
- Process errors incrementally in batches
- Sample PHP project with intentional test failures

## Usage

```bash
python src/main.py /path/to/php/project [options]
```

### Options

- `--verbose, -v`: Run in verbose mode
- `--max-errors, -m`: Maximum errors per batch (default: 3)
- `--max-iterations, -i`: Maximum iterations to run (default: 10)
- `--dry-run`: Run without sending to Claude (for testing)

## Project Structure

- `src/PhpUnitRunner`: PHPUnit test execution
- `src/ErrorFormatter`: Parsing and formatting PHPUnit test failures
- `src/McpIntegration`: Communication with Claude Code via MCP
- `samples/php_project`: Sample PHP project with test failures
