"""
MCP (Multi-agent Conversation Protocol) Client for PHPUnit Integration

This module provides functionality to integrate PHPUnit with Claude Code via MCP.
It handles sending PHPUnit test failures to Claude and processing the responses.
"""

import json
import os
import sys
import time
import tempfile
import subprocess
import re
from typing import Dict, List, Optional, Any, Tuple
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

# Add the project root to the Python path
sys.path.append(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))

from src.PhpUnitRunner.Runner import PhpUnitRunner, run_phpunit
from src.ErrorFormatter.Formatter import PhpUnitErrorFormatter, format_phpunit_output


class McpClient:
    """Client for interacting with Claude Code via MCP."""

    def __init__(self, 
                 project_path: str, 
                 max_errors_per_batch: int = 3,
                 phpunit_binary: Optional[str] = None):
        """
        Initialize the MCP client.
        
        Args:
            project_path: Path to the PHP project
            max_errors_per_batch: Maximum number of errors to process in a single batch
            phpunit_binary: Path to the PHPUnit binary
        """
        self.project_path = os.path.abspath(project_path)
        self.max_errors_per_batch = max_errors_per_batch
        
        # Initialize PHPUnit runner
        self.phpunit_runner = PhpUnitRunner(project_path, phpunit_binary)
        
        # Initialize error formatter
        self.error_formatter = PhpUnitErrorFormatter(max_errors_per_batch)

    def run_phpunit_tests(self, 
                        test_path: Optional[str] = None,
                        filter: Optional[str] = None,
                        verbose: bool = False) -> Tuple[str, int, List[Dict[str, Any]]]:
        """
        Run PHPUnit tests and format the failures.
        
        Args:
            test_path: Path to test file or directory
            filter: PHPUnit filter string
            verbose: Whether to run in verbose mode
            
        Returns:
            Tuple of (raw_output, return_code, formatted_batches)
        """
        # Create a temporary file for XML output
        with tempfile.NamedTemporaryFile(suffix='.xml', delete=False) as tmp:
            xml_output_path = tmp.name
        
        try:
            # Run PHPUnit tests
            output, return_code, xml_content = self.phpunit_runner.run_tests(
                test_path, 
                xml_output_path, 
                filter, 
                verbose
            )
            
            # If tests passed or XML output is missing, return early
            if return_code == 0 or not xml_content:
                return output, return_code, []
            
            # Parse the errors
            errors = self.error_formatter.parse_phpunit_xml(xml_content)
            
            # Calculate total batches
            total_batches = self.error_formatter.get_total_batches(len(errors))
            
            # Format errors into batches
            batches = []
            for batch_idx in range(total_batches):
                formatted = self.error_formatter.format_for_mcp(errors, batch_idx)
                batches.append(formatted)
            
            return output, return_code, batches
        finally:
            # Clean up the temporary file
            if os.path.exists(xml_output_path):
                os.unlink(xml_output_path)

    def prepare_mcp_message(self, 
                           batch: Dict[str, Any], 
                           file_contents: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
        """
        Prepare a message for Claude Code via MCP.
        
        Args:
            batch: Formatted batch of errors
            file_contents: Dictionary mapping file paths to their contents
            
        Returns:
            MCP message dictionary
        """
        # If file_contents not provided, read the files
        if file_contents is None:
            file_contents = {}
            for file_path in batch["errors_by_file"].keys():
                # Skip vendor files
                if "vendor" in file_path.split(os.path.sep):
                    continue
                    
                full_path = os.path.join(self.project_path, file_path)
                if os.path.isfile(full_path):
                    with open(full_path, 'r') as f:
                        file_contents[file_path] = f.read()
                        
            # Make sure src/Calculator.php and tests/CalculatorTest.php are included
            key_files = [
                "src/Calculator.php",
                "tests/CalculatorTest.php"
            ]
            
            for key_file in key_files:
                if key_file not in file_contents:
                    full_path = os.path.join(self.project_path, key_file)
                    if os.path.isfile(full_path):
                        with open(full_path, 'r') as f:
                            file_contents[key_file] = f.read()
        
        # Prepare the MCP message
        message = {
            "type": "mcp_phpunit_errors",
            "batch_info": batch["batch"],
            "errors": batch["errors_by_file"],
            "file_contents": file_contents,
            "project_path": self.project_path
        }
        
        return message

    def send_to_claude(self, message: Dict[str, Any]) -> Dict[str, Any]:
        """
        Send a message to Claude Code via MCP.
        
        Sends the error information to Claude via Claude's API.
        
        Args:
            message: MCP message to send
            
        Returns:
            Claude's response with extracted fixes
        """
        import os
        import requests
        import re
        import subprocess
        import json
        
        print("Sending message to Claude Code via MCP...")
        
        # Check for API key in environment
        api_key = os.environ.get('CLAUDE_API_KEY')
        if not api_key:
            print("Error: CLAUDE_API_KEY environment variable not set")
            return {
                "status": "error",
                "message": "CLAUDE_API_KEY environment variable not set. Please set your Claude API key."
            }
        
        # Extract error data from the message to enhance Claude's context
        error_details = ""
        for file_path, errors in message.get("errors_by_file", {}).items():
            error_details += f"\nErrors in {file_path}:\n"
            for error in errors:
                error_details += f"- Line {error.get('line', 'unknown')}: {error.get('message', 'No message')}\n"
        
        # First, let's run PHPUnit directly to get the actual error message
        print("Running PHPUnit to capture error messages...")
        phpunit_error_output = ""
        
        try:
            # Find the PHPUnit executable
            possible_phpunit_paths = [
                os.path.join(self.project_path, "vendor", "bin", "phpunit"),
                os.path.join(self.project_path, "vendor", "phpunit", "phpunit", "phpunit"),
                # Add more possible locations if needed
            ]
            
            phpunit_path = None
            for path in possible_phpunit_paths:
                if os.path.isfile(path):
                    phpunit_path = path
                    break
            
            if not phpunit_path:
                print("Warning: Could not find PHPUnit executable")
                phpunit_error_output = "Could not find PHPUnit executable"
            else:
                # Determine test paths dynamically
                test_paths = []
                
                # First try from specific error files
                for path in message.get("errors_by_file", {}).keys():
                    if "test" in path.lower():
                        full_path = os.path.join(self.project_path, path)
                        if os.path.exists(full_path):
                            test_paths.append(full_path)
                
                # If no test paths found, look for test directories
                if not test_paths:
                    possible_test_dirs = [
                        os.path.join(self.project_path, "tests"),
                        os.path.join(self.project_path, "test"),
                        # Add more possibilities if needed
                    ]
                    
                    for test_dir in possible_test_dirs:
                        if os.path.isdir(test_dir):
                            test_paths.append(test_dir)
                            break
                
                if test_paths:
                    # Run PHPUnit and capture output
                    cmd = [phpunit_path] + test_paths
                    process = subprocess.run(cmd, capture_output=True, text=True)
                    phpunit_error_output = process.stdout + process.stderr
                    print("PHPUnit error output captured")
                else:
                    phpunit_error_output = "Could not determine test paths"
                    print("Warning: Could not determine test paths")
        except Exception as e:
            print(f"Warning: Error running PHPUnit directly: {e}")
            phpunit_error_output = f"Error running PHPUnit: {str(e)}"
        
        # Claude API endpoint
        api_url = "https://api.anthropic.com/v1/messages"
        
        # Prepare request headers
        headers = {
            "x-api-key": api_key,
            "anthropic-version": "2023-06-01",
            "content-type": "application/json"
        }
        
        # Format the message for Claude API with a specific task to analyze PHPUnit errors
        claude_message = {
            "model": "claude-3-opus-20240229",
            "max_tokens": 4000,
            "messages": [
                {
                    "role": "user",
                    "content": f"""You are a PHP expert analyzing PHPUnit test failures and helping fix them.

I have a PHP project with failing tests. Below are the most recent PHPUnit error messages and the relevant code files.

# Most Recent PHPUnit Error Output:
```
{message.get("phpunit_output", phpunit_error_output)}
```

# Relevant Files:
{json.dumps(message.get("file_contents", {}), indent=2)}

# Errors by File:
{json.dumps(message.get("errors_by_file", {}), indent=2)}

# Detailed Error Information:
{error_details}

Please analyze the errors and suggest specific fixes to make the tests pass. Look for both logical test failures AND syntax errors in the PHP code.

For each fix, provide:

1. The specific error you're addressing
2. The root cause of the issue (explain your analysis)
3. Your recommended fix in this exact format:

FILE_TO_MODIFY: [relative path to file]
SEARCH: ```php
[exact code to find]
```
REPLACE: ```php
[code to replace it with]
```

IMPORTANT GUIDELINES:
- DO NOT modify any files in the vendor/ directory - these are third-party libraries and should not be changed
- Only modify the project's own source code (typically in src/ or tests/ directories)
- Be very precise about the code to search for - include enough context (like surrounding lines) to uniquely identify the section to change
- For syntax errors (like unclosed brackets or parentheses), look for patterns like extra parentheses or missing braces 
- Include the COMPLETE method or code block that needs fixing in both SEARCH and REPLACE sections
- Pay special attention to PHPUnit exception testing methods like expectException()
- When fixing division by zero errors, add proper exception throwing and error handling
- For test methods with syntax errors, fix both the syntax AND the test logic if needed
- Make sure your SEARCH string exactly matches the current code (including indentation and spacing)

For this project, the key files you should focus on are:
- src/Calculator.php - The main class with the divide method that needs division by zero handling
- tests/CalculatorTest.php - The test class that may have syntax or logic errors

If you see a syntax error in the PHPUnit output, make that your top priority to fix first.

IMPORTANT: If the error is related to unclosed parenthesis like ');)', make sure to include the exact problematic code line in your SEARCH.
"""
                }
            ]
        }
        
        try:
            # Make API request
            response = requests.post(api_url, headers=headers, json=claude_message)
            response.raise_for_status()  # Raise exception for HTTP errors
            
            # Parse and return Claude's response
            claude_response = response.json()
            claude_text = claude_response.get("content", [{"text": "No content in response"}])[0].get("text", "")
            
            # Extract file modifications from Claude's response
            fixes = []
            fix_pattern = r"FILE_TO_MODIFY:\s*([^\n]+)\nSEARCH:\s*```[^\n]*\n(.*?)```\nREPLACE:\s*```[^\n]*\n(.*?)```"
            
            # Use re.DOTALL to make . match newlines as well
            matches = re.findall(fix_pattern, claude_text, re.DOTALL)
            
            for file_path, search_text, replace_text in matches:
                fixes.append({
                    "file_path": file_path.strip(),
                    "search": search_text.strip(),
                    "replace": replace_text.strip()
                })
            
            # If no fixes were extracted, make it clear
            if not fixes:
                print("WARNING: No structured fixes could be extracted from Claude's response")
                print("Claude's response did not contain fixes in the expected format")
            
            return {
                "status": "success",
                "message": claude_text,
                "fixes": fixes
            }
            
        except requests.exceptions.RequestException as e:
            print(f"API request error: {e}")
            return {
                "status": "error",
                "message": f"Error communicating with Claude API: {str(e)}"
            }
        except (KeyError, ValueError, json.JSONDecodeError) as e:
            print(f"Error parsing Claude response: {e}")
            return {
                "status": "error",
                "message": f"Error parsing Claude response: {str(e)}"
            }

    def check_php_syntax(self, file_path: str, content: str) -> Tuple[bool, str]:
        """
        Check PHP code for syntax errors using php -l.
        
        Args:
            file_path: Path to the PHP file
            content: The PHP code content to check
            
        Returns:
            Tuple of (is_valid, error_message)
        """
        try:
            # Create a temporary file with the content
            with tempfile.NamedTemporaryFile(suffix='.php', delete=False) as tmp:
                tmp_path = tmp.name
                tmp.write(content.encode('utf-8'))
            
            # Run php -l on the temporary file
            cmd = ['php', '-l', tmp_path]
            process = subprocess.run(cmd, capture_output=True, text=True)
            
            # Check if there were syntax errors
            if process.returncode != 0 or "Errors parsing" in process.stdout or "Errors parsing" in process.stderr:
                error_output = process.stdout + process.stderr
                
                # Print content with line numbers for debugging
                print("\n===== Debug: Content with syntax errors =====")
                content_lines = content.split('\n')
                for i, line in enumerate(content_lines):
                    print(f"{i+1:3d}: {line}")
                print("=============================================")
                
                return False, error_output
            
            return True, "No syntax errors detected"
        except Exception as e:
            return False, f"Error checking PHP syntax: {str(e)}"
        finally:
            # Clean up the temporary file
            if 'tmp_path' in locals():
                if os.path.exists(tmp_path):
                    try:
                        os.unlink(tmp_path)
                    except:
                        pass  # Ignore errors on cleanup
    
    def fix_php_syntax_errors(self, content: str, error_message: str) -> Tuple[bool, str]:
        """
        Attempt to automatically fix common PHP syntax errors based on error messages.
        
        Args:
            content: The PHP code content with errors
            error_message: The error message from php -l
            
        Returns:
            Tuple of (is_fixed, fixed_content)
        """
        fixed_content = content
        is_fixed = False
        
        # Print error details for debugging
        print(f"\n===== PHP Error Message =====")
        print(error_message)
        print("=============================")
        
        # Get line number from error message
        line_num_match = re.search(r'on line (\d+)', error_message)
        error_line = None
        if line_num_match:
            try:
                error_line = int(line_num_match.group(1))
                print(f"Error detected on line {error_line}")
                
                # Show the problematic line and surrounding context
                content_lines = content.split('\n')
                if 1 <= error_line <= len(content_lines):
                    start_line = max(1, error_line - 2)
                    end_line = min(len(content_lines), error_line + 2)
                    
                    print("\nError context:")
                    for i in range(start_line - 1, end_line):
                        line_marker = ">>> " if i + 1 == error_line else "    "
                        print(f"{line_marker}{i+1:3d}: {content_lines[i]}")
            except ValueError:
                pass
        
        # Common syntax errors and their fixes
        if "Multiple access type modifiers are not allowed" in error_message:
            print("Detected multiple access type modifiers error")
            
            # First attempt: Fix at the specific error line if known
            if error_line and error_line <= len(content.split('\n')):
                problematic_line = content.split('\n')[error_line - 1]
                print(f"Problematic line: {problematic_line}")
                
                # Try to detect specific pattern of duplicate access modifiers
                # Look for "public public", "protected public", etc.
                modifier_pattern = r'(public|private|protected)\s+(public|private|protected)\s+'
                match = re.search(modifier_pattern, problematic_line)
                if match:
                    print(f"Found duplicate modifiers: '{match.group(0)}'")
                    first_modifier = match.group(1)
                    fixed_line = re.sub(modifier_pattern, f"{first_modifier} ", problematic_line)
                    print(f"Fixed line: {fixed_line}")
                    
                    # Replace the line in the content
                    content_lines = content.split('\n')
                    content_lines[error_line - 1] = fixed_line
                    fixed_content = '\n'.join(content_lines)
                    is_fixed = True
                    print(f"Fixed duplicate access modifiers: '{match.group(0).strip()}' -> '{first_modifier}'")
            
            # Second attempt: Search entire content for duplicate modifiers
            if not is_fixed:
                print("Scanning entire content for duplicate modifiers...")
                # Look for any occurrence of duplicate modifiers
                complete_pattern = r'(public|private|protected)\s+(public|private|protected)\s+function'
                matches = list(re.finditer(complete_pattern, fixed_content))
                
                if matches:
                    for match in matches:
                        matched_text = match.group(0)
                        first_modifier = match.group(1)
                        fixed_text = f"{first_modifier} function"
                        fixed_content = fixed_content.replace(matched_text, fixed_text)
                        is_fixed = True
                        print(f"Fixed duplicate modifiers: '{matched_text}' -> '{fixed_text}'")
                else:
                    print("No duplicate modifiers found in complete scan")
        
        # Check for specific duplicate access modifiers patterns
        for pattern, replacement in [
            (r'public\s+public\s+', 'public '),
            (r'protected\s+protected\s+', 'protected '),
            (r'private\s+private\s+', 'private '),
            (r'public\s+protected\s+', 'protected '),
            (r'protected\s+public\s+', 'protected '),
            (r'public\s+private\s+', 'private '),
            (r'private\s+public\s+', 'private ')
        ]:
            if re.search(pattern, fixed_content):
                fixed_content = re.sub(pattern, replacement, fixed_content)
                is_fixed = True
                print(f"Fixed duplicated modifiers using pattern: {pattern} -> {replacement}")
        
        if "syntax error, unexpected" in error_message and "expecting" in error_message:
            print("Detected unexpected token syntax error")
            
            # Try to fix common bracket/parenthesis/semicolon issues
            if ");)" in fixed_content:
                fixed_content = fixed_content.replace(");)", ");")
                is_fixed = True
                print("Fixed syntax error: replaced ');)' with ');'")
            
            if "unexpected }" in error_message and "->divide(4, 0));" in fixed_content:
                fixed_content = fixed_content.replace("->divide(4, 0));", "->divide(4, 0);")
                is_fixed = True
                print("Fixed syntax error: removed extra parenthesis in method call")
        
        if is_fixed:
            print("\n===== Fixed Content =====")
            fixed_lines = fixed_content.split('\n')
            for i, line in enumerate(fixed_lines):
                print(f"{i+1:3d}: {line}")
            print("=========================")
        else:
            print("\nCould not automatically fix the PHP syntax errors")
        
        return is_fixed, fixed_content
    
    def apply_fix(self, fix: Dict[str, str]) -> bool:
        """
        Apply a fix to a file.
        
        Args:
            fix: Dictionary containing file_path, search, and replace keys
            
        Returns:
            True if the fix was applied successfully, False otherwise
        """
        # Normalize the file path (sometimes it could start with '/' or './')
        normalized_path = fix["file_path"].lstrip('./')
        
        # Try multiple ways to find the file
        possible_paths = [
            os.path.join(self.project_path, normalized_path),  # Standard path
            normalized_path,                                   # Maybe it's already absolute
            os.path.abspath(normalized_path)                   # Just in case it's relative to CWD
        ]
        
        # Find the first existing file that's not in vendor directory
        file_path = None
        for path in possible_paths:
            if os.path.isfile(path) and "vendor" not in path.split(os.path.sep):
                file_path = path
                break
        
        # If no file found, try more targeted search in non-vendor directories,
        # prioritizing src/ and tests/ directories
        if file_path is None:
            # Try to find by basename in the project source directories only
            basename = os.path.basename(normalized_path)
            
            # First look in src/ directory
            src_path = os.path.join(self.project_path, "src", basename)
            if os.path.isfile(src_path):
                file_path = src_path
                print(f"Found file in src directory: {file_path}")
            
            # If not found, look in tests/ directory
            if file_path is None:
                tests_path = os.path.join(self.project_path, "tests", basename)
                if os.path.isfile(tests_path):
                    file_path = tests_path
                    print(f"Found file in tests directory: {file_path}")
            
            # If still not found, look in other non-vendor directories
            if file_path is None:
                for root, _, files in os.walk(self.project_path):
                    # Skip vendor directories
                    if "vendor" in root.split(os.path.sep):
                        continue
                    
                    if basename in files:
                        candidate_path = os.path.join(root, basename)
                        # Double check it's not in vendor
                        if "vendor" not in candidate_path:
                            file_path = candidate_path
                            print(f"Found file by basename: {file_path}")
                            break
        
        # If still no file, give up
        if file_path is None:
            print(f"Error: File {normalized_path} does not exist")
            print(f"Tried paths: {', '.join(possible_paths)}")
            return False
        
        try:
            print(f"Using file path: {file_path}")
            
            # Read the file content
            with open(file_path, 'r') as f:
                content = f.read()
            
            # Make a backup of the file
            backup_path = f"{file_path}.bak"
            with open(backup_path, 'w') as f:
                f.write(content)
            print(f"Created backup at {backup_path}")
            
            # First try exact match
            if fix["search"] in content:
                # Replace the search text with the replace text
                new_content = content.replace(fix["search"], fix["replace"])
                
                # Check PHP syntax before writing the file
                is_valid, error_message = self.check_php_syntax(file_path, new_content)
                
                if not is_valid:
                    print(f"WARNING: The proposed fix contains PHP syntax errors:")
                    print(error_message)
                    
                    # Try to automatically fix the syntax errors
                    is_fixed, fixed_content = self.fix_php_syntax_errors(new_content, error_message)
                    
                    if is_fixed:
                        # Check if the fixed content is valid
                        is_valid_after_fix, _ = self.check_php_syntax(file_path, fixed_content)
                        
                        if is_valid_after_fix:
                            # Write the fixed content
                            with open(file_path, 'w') as f:
                                f.write(fixed_content)
                            
                            print(f"Successfully applied fix to {fix['file_path']} and corrected PHP syntax errors")
                            return True
                        else:
                            print("Could not fully correct PHP syntax errors. Restoring from backup.")
                            with open(file_path, 'w') as f:
                                f.write(content)
                            return False
                    else:
                        print("Could not automatically fix PHP syntax errors")
                        
                        try:
                            print("\nOptions:")
                            print("1. Apply the fix anyway (may cause PHP errors)")
                            print("2. Skip this fix")
                            
                            user_input = input("\nChoose an option (1-2): ")
                            
                            if user_input == "1":
                                # Apply despite syntax errors
                                with open(file_path, 'w') as f:
                                    f.write(new_content)
                                print(f"Applied fix despite syntax errors")
                                return True
                            else:
                                print("Skipping this fix")
                                return False
                        except (EOFError, KeyboardInterrupt):
                            print("User input not available. Skipping to avoid syntax errors.")
                            return False
                else:
                    # No syntax errors, write the new content
                    with open(file_path, 'w') as f:
                        f.write(new_content)
                    
                    print(f"Successfully applied fix to {fix['file_path']} (exact match)")
                    return True
            
            # If exact match fails, try with more flexible approaches
            import re
            
            # Try to extract a method name from the search string
            method_match = None
            function_name = None
            
            # Look for PHP methods/functions
            for pattern in [
                r'function\s+(\w+)', 
                r'public\s+function\s+(\w+)',
                r'private\s+function\s+(\w+)',
                r'protected\s+function\s+(\w+)'
            ]:
                method_match = re.search(pattern, fix["search"])
                if method_match:
                    function_name = method_match.group(1)
                    break
            
            if function_name:
                print(f"Extracted method name: {function_name}")
                # If we found a method name, look for this method in the file
                for pattern in [
                    # Try various method patterns
                    rf'function\s+{function_name}\s*\([^{{]*\{{.*?\}}',  # Basic function
                    rf'public\s+function\s+{function_name}\s*\([^{{]*\{{.*?\}}',  # Public method
                    rf'private\s+function\s+{function_name}\s*\([^{{]*\{{.*?\}}',  # Private method
                    rf'protected\s+function\s+{function_name}\s*\([^{{]*\{{.*?\}}'  # Protected method
                ]:
                    method_matches = list(re.finditer(pattern, content, re.DOTALL))
                    if method_matches:
                        # Only use the match if there's just one - otherwise we might modify the wrong method
                        if len(method_matches) == 1:
                            matched_method = method_matches[0].group(0)
                            new_content = content.replace(matched_method, fix["replace"])
                            
                            # Check PHP syntax before writing the file
                            is_valid, error_message = self.check_php_syntax(file_path, new_content)
                            
                            if not is_valid:
                                print(f"WARNING: The proposed fix contains PHP syntax errors:")
                                print(error_message)
                                
                                # Try to automatically fix the syntax errors
                                is_fixed, fixed_content = self.fix_php_syntax_errors(new_content, error_message)
                                
                                if is_fixed:
                                    # Check if the fixed content is valid
                                    is_valid_after_fix, _ = self.check_php_syntax(file_path, fixed_content)
                                    
                                    if is_valid_after_fix:
                                        # Write the fixed content
                                        with open(file_path, 'w') as f:
                                            f.write(fixed_content)
                                        
                                        print(f"Successfully applied fix to method {function_name} and corrected PHP syntax errors")
                                        return True
                                    else:
                                        print("Could not fully correct PHP syntax errors. Restoring from backup.")
                                        return False
                                else:
                                    print("Could not automatically fix PHP syntax errors. Skipping.")
                                    return False
                            else:
                                # No syntax errors, write the new content
                                with open(file_path, 'w') as f:
                                    f.write(new_content)
                                
                                print(f"Successfully applied fix to method {function_name}")
                                return True
                        else:
                            print(f"WARNING: Found {len(method_matches)} matches for method {function_name}. Need more specific context.")
            
            # Try line-by-line matching approach
            search_lines = fix["search"].strip().split('\n')
            content_lines = content.strip().split('\n')
            
            # If the search has multiple lines, try to find a matching block of content
            if len(search_lines) > 1:
                for i in range(len(content_lines) - len(search_lines) + 1):
                    # Check if the first and last lines match (approximate)
                    first_line_match = search_lines[0].strip() in content_lines[i].strip()
                    last_line_match = search_lines[-1].strip() in content_lines[i + len(search_lines) - 1].strip()
                    
                    if first_line_match and last_line_match:
                        # Found a potential match block
                        match_block = '\n'.join(content_lines[i:i + len(search_lines)])
                        new_content = content.replace(match_block, fix["replace"])
                        
                        # Check PHP syntax before writing the file
                        is_valid, error_message = self.check_php_syntax(file_path, new_content)
                        
                        if not is_valid:
                            print(f"WARNING: The proposed fix contains PHP syntax errors:")
                            print(error_message)
                            
                            # Try to automatically fix the syntax errors
                            is_fixed, fixed_content = self.fix_php_syntax_errors(new_content, error_message)
                            
                            if is_fixed:
                                # Check if the fixed content is valid
                                is_valid_after_fix, _ = self.check_php_syntax(file_path, fixed_content)
                                
                                if is_valid_after_fix:
                                    # Write the fixed content
                                    with open(file_path, 'w') as f:
                                        f.write(fixed_content)
                                    
                                    print(f"Successfully applied fix using line block matching and corrected PHP syntax errors")
                                    return True
                                else:
                                    print("Could not fully correct PHP syntax errors. Skipping.")
                                    return False
                            else:
                                print("Could not automatically fix PHP syntax errors. Skipping.")
                                return False
                        else:
                            # No syntax errors, write the new content
                            with open(file_path, 'w') as f:
                                f.write(new_content)
                            
                            print(f"Successfully applied fix using line block matching")
                            return True
            
            # If we get here, we couldn't find a match
            print(f"Error: Could not find the target code in {file_path}")
            print("Expected to find:")
            print(fix["search"])
            
            # Display the content for debugging with line numbers
            print("\nActual file content with line numbers:")
            content_lines = content.split('\n')
            # Show at most 30 lines of content
            max_lines = min(30, len(content_lines))
            for i in range(max_lines):
                print(f"{i+1:3d}: {content_lines[i]}")
            if len(content_lines) > max_lines:
                print("... (more lines not shown)")
            
            print("\nPlease check the file manually and apply the fix:")
            print("--- REPLACEMENT CONTENT ---")
            replace_lines = fix["replace"].split('\n')
            for i in range(len(replace_lines)):
                print(f"{i+1:3d}: {replace_lines[i]}")
            print("-------------------------")
            
            # Offer options for manual fix
            try:
                print("\nOptions:")
                print("1. Replace entire file with the fix")
                print("2. Fix the syntax error in the file (best effort)")
                print("3. Skip this fix")
                
                user_input = input("\nChoose an option (1-3): ")
                
                if user_input == "1":
                    # Check PHP syntax before replacing entire file
                    is_valid, error_message = self.check_php_syntax(file_path, fix["replace"])
                    
                    if not is_valid:
                        print(f"WARNING: The replacement content contains PHP syntax errors:")
                        print(error_message)
                        
                        confirm = input("Replace anyway? (y/n): ")
                        if confirm.lower() not in ('y', 'yes'):
                            print("Skipping this fix")
                            return False
                    
                    # Replace entire file
                    with open(file_path, 'w') as f:
                        f.write(fix["replace"])
                    print(f"Replaced entire file content with the fix")
                    return True
                elif user_input == "2":
                    # Try to fix syntax errors
                    for pattern, replacement in [
                        (");)", ");"),               # Fix double closing parenthesis
                        ("public public", "public"), # Fix duplicate modifiers
                        ("protected public", "protected"),
                        ("private public", "private"),
                        ("public protected", "protected"),
                        ("public private", "private")
                    ]:
                        if pattern in content:
                            fixed_content = content.replace(pattern, replacement)
                            
                            # Check if the fixed content is valid
                            is_valid, _ = self.check_php_syntax(file_path, fixed_content)
                            
                            if is_valid:
                                with open(file_path, 'w') as f:
                                    f.write(fixed_content)
                                print(f"Fixed syntax error: replaced '{pattern}' with '{replacement}'")
                                return True
                    
                    print("Could not identify a specific syntax error to fix")
                    return False
                else:
                    print("Skipping this fix")
                    return False
            except (EOFError, KeyboardInterrupt):
                print("User input not available. Skipping manual fix.")
            
            return False
        
        except Exception as e:
            print(f"Error applying fix to {file_path}: {str(e)}")
            return False
    
    def run_phpunit_and_get_output(self, test_path: Optional[str] = None) -> str:
        """
        Run PHPUnit directly and return the raw output.
        
        Args:
            test_path: Path to test file or directory
            
        Returns:
            Raw PHPUnit output as string
        """
        phpunit_output = ""
        try:
            # Find the PHPUnit executable
            possible_phpunit_paths = [
                os.path.join(self.project_path, "vendor", "bin", "phpunit"),
                os.path.join(self.project_path, "vendor", "phpunit", "phpunit", "phpunit")
            ]
            
            phpunit_path = None
            for path in possible_phpunit_paths:
                if os.path.isfile(path):
                    phpunit_path = path
                    break
            
            if not phpunit_path:
                return "Could not find PHPUnit executable"
            
            # Determine test paths
            test_paths = []
            if test_path:
                test_paths.append(os.path.join(self.project_path, test_path))
            else:
                possible_test_dirs = [
                    os.path.join(self.project_path, "tests"),
                    os.path.join(self.project_path, "test")
                ]
                for dir_path in possible_test_dirs:
                    if os.path.isdir(dir_path):
                        test_paths.append(dir_path)
                        break
            
            if not test_paths:
                return "No test paths found"
            
            # Run PHPUnit
            cmd = [phpunit_path] + test_paths
            process = subprocess.run(cmd, capture_output=True, text=True)
            phpunit_output = process.stdout + process.stderr
            
            return phpunit_output
        except Exception as e:
            return f"Error running PHPUnit: {str(e)}"
    
    def process_phpunit_errors(self, 
                              test_path: Optional[str] = None,
                              filter: Optional[str] = None,
                              verbose: bool = False,
                              max_iterations: int = 10,
                              auto_mode: bool = False) -> bool:
        """
        Process PHPUnit test failures with Claude Code via MCP.
        
        This method runs PHPUnit tests, sends the failures to Claude, applies the fixes,
        and repeats until all tests pass or max_iterations is reached.
        
        Args:
            test_path: Path to test file or directory
            filter: PHPUnit filter string
            verbose: Whether to run in verbose mode
            max_iterations: Maximum number of iterations to run
            auto_mode: Whether to apply fixes automatically without user confirmation
            
        Returns:
            True if all tests pass, False otherwise
        """
        iteration = 0
        
        while iteration < max_iterations:
            print(f"\nIteration {iteration + 1}/{max_iterations}")
            
            # Get current PHPUnit output (will be used to show errors for manual fixes)
            phpunit_output = self.run_phpunit_and_get_output(test_path)
            print("\nCurrent PHPUnit output:")
            print("-" * 50)
            # Print first 15 lines of output
            output_lines = phpunit_output.split("\n")
            max_lines = min(15, len(output_lines))
            for i in range(max_lines):
                print(output_lines[i])
            if len(output_lines) > max_lines:
                print("... (more output not shown)")
            print("-" * 50)
            
            # Run PHPUnit tests
            output, return_code, batches = self.run_phpunit_tests(test_path, filter, verbose)
            
            # If tests pass, we're done
            if return_code == 0:
                print("All PHPUnit tests pass!")
                return True
            
            # If there are failures, process them in batches
            print(f"Found {len(batches)} batches of test failures")
            
            for batch_idx, batch in enumerate(batches):
                print(f"\nProcessing batch {batch_idx + 1}/{len(batches)}")
                
                # Prepare MCP message
                message = self.prepare_mcp_message(batch)
                
                # Add the current PHPUnit output to the message
                message["phpunit_output"] = phpunit_output
                
                # Send to Claude
                response = self.send_to_claude(message)
                
                # Display Claude's suggested fixes in a more user-friendly format
                if response["status"] == "success":
                    print("\n============= CLAUDE'S ANALYSIS AND SUGGESTED FIXES =============")
                    suggestion = response["message"]
                    print(suggestion)
                    print("\n==============================================================")
                    
                    # Check if we have structured fixes
                    if response["fixes"]:
                        print(f"\nClaude suggested {len(response['fixes'])} fixes to apply")
                        
                        # Debug: Show the raw fixes for inspection
                        print("\n===== RAW FIX DETAILS FOR DEBUGGING =====")
                        for fix_idx, fix in enumerate(response["fixes"]):
                            print(f"\nFix {fix_idx + 1}:")
                            print(f"File: {fix['file_path']}")
                            print(f"Search:")
                            print(fix["search"])
                            print(f"Replace:")
                            print(fix["replace"])
                            
                            # Check this fix for known issues
                            import re
                            duplicate_modifiers = re.search(r'(public|private|protected)\s+(public|private|protected)\s+', fix["replace"])
                            if duplicate_modifiers:
                                print(f"WARNING: Detected duplicate access modifiers in fix: {duplicate_modifiers.group(0)}")
                        print("=========================================")
                        
                        # List files that will be modified
                        files_to_modify = list(set(fix["file_path"] for fix in response["fixes"]))
                        print("\nFiles that would be modified:")
                        for file in files_to_modify:
                            print(f"  - {file}")
                        
                        # In auto_mode, apply the fixes automatically without asking
                        if auto_mode:
                            print("\nAuto mode enabled. Applying fixes automatically...")
                            applied_fixes = []
                            for fix_idx, fix in enumerate(response["fixes"]):
                                print(f"\nApplying fix {fix_idx + 1}/{len(response['fixes'])}:")
                                
                                # Pre-check for common PHP syntax issues before attempting to apply
                                has_syntax_issue = False
                                
                                # Check for duplicate access modifiers with more patterns
                                # First do a direct check for the known problematic pattern
                                if "public public function" in fix["replace"]:
                                    print("WARNING: Fix contains 'public public function'. Fixing...")
                                    fix["replace"] = fix["replace"].replace("public public function", "public function")
                                    print("Modified replace content:")
                                    print(fix["replace"])
                                
                                # Then check for other variations with regex
                                for pattern, replacement in [
                                    (r'public\s+public\s+', 'public '),
                                    (r'protected\s+protected\s+', 'protected '),
                                    (r'private\s+private\s+', 'private '),
                                    (r'public\s+protected\s+', 'protected '),
                                    (r'protected\s+public\s+', 'protected '),
                                    (r'public\s+private\s+', 'private '),
                                    (r'private\s+public\s+', 'private ')
                                ]:
                                    if re.search(pattern, fix["replace"]):
                                        print(f"WARNING: Fix contains duplicate modifiers: '{pattern}'. Fixing...")
                                        fix["replace"] = re.sub(pattern, replacement, fix["replace"])
                                        print("Modified replace content:")
                                        print(fix["replace"])
                                
                                success = self.apply_fix(fix)
                                if success:
                                    applied_fixes.append(fix)
                            
                            if applied_fixes:
                                # After applying fixes, run PHPUnit again to see if we've made progress
                                print("\nRunning PHPUnit after applying fixes...")
                                new_phpunit_output = self.run_phpunit_and_get_output(test_path)
                                print("\nPHPUnit output after fixes:")
                                print("-" * 50)
                                # Print first 15 lines of new output
                                new_output_lines = new_phpunit_output.split("\n")
                                max_lines = min(15, len(new_output_lines))
                                for i in range(max_lines):
                                    print(new_output_lines[i])
                                if len(new_output_lines) > max_lines:
                                    print("... (more output not shown)")
                                print("-" * 50)
                        else:
                            # Ask the user if they want to apply the suggested fixes
                            try:
                                user_input = input("\nWould you like to apply these fixes? (y/n): ")
                                
                                if user_input.lower() in ('y', 'yes'):
                                    print("\nApplying fixes...")
                                    applied_fixes = []
                                    for fix_idx, fix in enumerate(response["fixes"]):
                                        print(f"\nApplying fix {fix_idx + 1}/{len(response['fixes'])}:")
                                        success = self.apply_fix(fix)
                                        if success:
                                            applied_fixes.append(fix)
                                    
                                    if applied_fixes:
                                        # After applying fixes, run PHPUnit again to see if we've made progress
                                        print("\nRunning PHPUnit after applying fixes...")
                                        new_phpunit_output = self.run_phpunit_and_get_output(test_path)
                                        print("\nPHPUnit output after fixes:")
                                        print("-" * 50)
                                        # Print first 15 lines of new output
                                        new_output_lines = new_phpunit_output.split("\n")
                                        max_lines = min(15, len(new_output_lines))
                                        for i in range(max_lines):
                                            print(new_output_lines[i])
                                        if len(new_output_lines) > max_lines:
                                            print("... (more output not shown)")
                                        print("-" * 50)
                                else:
                                    print("\nYou chose not to apply the fixes. Continuing to the next batch...")
                            except EOFError:
                                # If input fails (e.g., in Claude Code), fall back to auto_mode behavior
                                print("\nInput not available. Applying fixes automatically...")
                                applied_fixes = []
                                for fix_idx, fix in enumerate(response["fixes"]):
                                    print(f"\nApplying fix {fix_idx + 1}/{len(response['fixes'])}:")
                                    success = self.apply_fix(fix)
                                    if success:
                                        applied_fixes.append(fix)
                                
                                if applied_fixes:
                                    # After applying fixes, run PHPUnit again to see if we've made progress
                                    print("\nRunning PHPUnit after applying fixes...")
                                    new_phpunit_output = self.run_phpunit_and_get_output(test_path)
                                    print("\nPHPUnit output after fixes:")
                                    print("-" * 50)
                                    print(new_phpunit_output[:500] + "..." if len(new_phpunit_output) > 500 else new_phpunit_output)
                                    print("-" * 50)
                    else:
                        # If no structured fixes were extracted, fall back to the previous behavior
                        print("\nNo structured fixes were found in Claude's response.")
                        print("You may need to apply fixes manually or try again.")
                        
                        # Identify files that might need to be modified based on the suggestion
                        potential_files = []
                        for line in suggestion.split('\n'):
                            if ".php" in line:
                                parts = line.split()
                                for part in parts:
                                    if ".php" in part:
                                        # Extract just the filename part
                                        filename = part.strip('."\'(),;:')
                                        if filename.endswith('.php'):
                                            potential_files.append(filename)
                        
                        if potential_files:
                            print("\nFiles that might need to be modified (based on Claude's analysis):")
                            for file in set(potential_files):
                                print(f"  - {file}")
                else:
                    print("\nClaude encountered an error with the following message:")
                    print(json.dumps(response, indent=2))
            
            # Increment iteration counter
            iteration += 1
        
        # If we've reached max_iterations, we've failed to fix all errors
        print(f"Reached maximum iterations ({max_iterations}) without fixing all test failures")
        return False


def main():
    """Main entry point for the MCP client."""
    import argparse
    
    parser = argparse.ArgumentParser(description="Process PHPUnit test failures with Claude Code via MCP")
    parser.add_argument("project_path", help="Path to the PHP project")
    parser.add_argument("--test-path", "-t", help="Path to test file or directory")
    parser.add_argument("--filter", "-f", help="PHPUnit filter string")
    parser.add_argument("--verbose", "-v", action="store_true", help="Run in verbose mode")
    parser.add_argument("--max-errors", "-m", type=int, default=3, help="Maximum errors per batch")
    parser.add_argument("--max-iterations", "-i", type=int, default=10, help="Maximum iterations")
    
    args = parser.parse_args()
    
    # Initialize MCP client
    client = McpClient(args.project_path, args.max_errors)
    
    # Process PHPUnit test failures
    success = client.process_phpunit_errors(
        args.test_path,
        args.filter,
        args.verbose,
        args.max_iterations
    )
    
    # Exit with appropriate code
    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()
