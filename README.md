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

## Integration with Claude Code

### Using with Claude Code API

To use this tool with Claude Code, you can integrate it into your workflow as follows:

1. **Run PHPUnit tests and capture errors**:
   ```bash
   python src/main.py /path/to/php/project --dry-run > phpunit_errors.json
   ```

2. **Send errors to Claude Code**:
   ```python
   import json
   import anthropic

   # Load the PHPUnit errors
   with open('phpunit_errors.json', 'r') as f:
       phpunit_errors = json.load(f)

   # Initialize Claude client
   client = anthropic.Anthropic(api_key="your_api_key")

   # Prepare the message for Claude
   message = f"""
   I'm having issues with my PHP tests. Here are the PHPUnit errors:
   
   ```json
   {json.dumps(phpunit_errors, indent=2)}
   ```
   
   Can you help me fix these issues?
   """

   # Send to Claude
   response = client.messages.create(
       model="claude-3-opus-20240229",
       max_tokens=1000,
       messages=[
           {"role": "user", "content": message}
       ]
   )

   # Print Claude's response
   print(response.content[0].text)
   ```

### Using with Claude Code in MCP

If you're using Claude Code in an MCP-enabled environment:

1. **Run the integration directly**:
   ```bash
   python src/main.py /path/to/php/project
   ```

2. **In your MCP client, handle the message type**:
   ```python
   def handle_mcp_message(message):
       if message["type"] == "mcp_phpunit_errors":
           # Extract error information
           errors = message["errors"]
           file_contents = message["file_contents"]
           
           # Process the errors
           for file_path, file_errors in errors.items():
               print(f"Errors in {file_path}:")
               for error in file_errors:
                   print(f"  Line {error['line']}: {error['message']}")
                   
               # Get the file content
               if file_path in file_contents:
                   code = file_contents[file_path]
                   # Send to Claude for fixing
                   # ...
   ```

3. **Example Claude prompt template**:
   ```
   I have a PHP test that's failing with the following error:
   
   File: {{file_path}}
   Error: {{error_message}}
   Line: {{error_line}}
   
   Here's the code:
   
   ```php
   {{file_content}}
   ```
   
   Please help me fix this issue.
   ```

## Project Structure

- `src/PhpUnitRunner`: PHPUnit test execution
- `src/ErrorFormatter`: Parsing and formatting PHPUnit test failures
- `src/McpIntegration`: Communication with Claude Code via MCP
- `samples/php_project`: Sample PHP project with test failures
