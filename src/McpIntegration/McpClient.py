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
from typing import Dict, List, Optional, Any, Tuple

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
                full_path = os.path.join(self.project_path, file_path)
                if os.path.isfile(full_path):
                    with open(full_path, 'r') as f:
                        file_contents[file_path] = f.read()
        
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
        
        This is a placeholder method that would be implemented with actual
        Claude API integration. For now, it just prints the message and
        returns a mock response.
        
        Args:
            message: MCP message to send
            
        Returns:
            Claude's response
        """
        print("Sending message to Claude Code via MCP:")
        print(json.dumps(message, indent=2))
        
        # This is where the actual API call to Claude would happen
        # For now, return a mock response
        return {
            "status": "success",
            "message": "This is a mock response. In a real implementation, this would be Claude's response.",
            "fixes": []
        }

    def process_phpunit_errors(self, 
                              test_path: Optional[str] = None,
                              filter: Optional[str] = None,
                              verbose: bool = False,
                              max_iterations: int = 10) -> bool:
        """
        Process PHPUnit test failures with Claude Code via MCP.
        
        This method runs PHPUnit tests, sends the failures to Claude, applies the fixes,
        and repeats until all tests pass or max_iterations is reached.
        
        Args:
            test_path: Path to test file or directory
            filter: PHPUnit filter string
            verbose: Whether to run in verbose mode
            max_iterations: Maximum number of iterations to run
            
        Returns:
            True if all tests pass, False otherwise
        """
        iteration = 0
        
        while iteration < max_iterations:
            print(f"\nIteration {iteration + 1}/{max_iterations}")
            
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
                
                # Send to Claude
                response = self.send_to_claude(message)
                
                # In a real implementation, we would apply fixes here
                # For now, just print a message
                print("\nClaude suggests the following fixes:")
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
