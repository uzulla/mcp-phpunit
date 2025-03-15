"""
PHPUnit Error Formatter for MCP

This module formats PHPUnit test failures into a structure suitable for processing by Claude via MCP.
It handles parsing the PHPUnit XML output and converting it to a format that can be used with MCP.
"""

import json
import xml.etree.ElementTree as ET
from typing import Dict, List, Optional, Any


class PhpUnitError:
    """Represents a single PHPUnit test failure."""

    def __init__(
        self,
        message: str,
        file: str,
        line: int,
        test_name: str,
        error_type: str,
        class_name: str,
    ):
        self.message = message
        self.file = file
        self.line = line
        self.test_name = test_name
        self.error_type = error_type
        self.class_name = class_name

    def to_dict(self) -> Dict[str, Any]:
        """Convert the error to a dictionary representation."""
        return {
            "message": self.message,
            "file": self.file,
            "line": self.line,
            "test_name": self.test_name,
            "error_type": self.error_type,
            "class_name": self.class_name
        }


class PhpUnitErrorFormatter:
    """Formats PHPUnit test failures for MCP integration."""

    def __init__(self, max_errors_per_batch: int = 5):
        """
        Initialize the formatter.
        
        Args:
            max_errors_per_batch: Maximum number of errors to include in a single batch
        """
        self.max_errors_per_batch = max_errors_per_batch

    def parse_phpunit_xml(self, xml_content: str) -> List[PhpUnitError]:
        """
        Parse PHPUnit JUnit XML output and extract test failures.
        
        Args:
            xml_content: The XML output from PHPUnit
            
        Returns:
            List of PhpUnitError objects
        """
        errors = []
        
        try:
            root = ET.fromstring(xml_content)
            
            # Find all testcase elements with failures or errors
            for testsuite in root.findall('.//testsuite'):
                for testcase in testsuite.findall('.//testcase'):
                    # Check if this testcase has a failure
                    failure = testcase.find('./failure')
                    error = testcase.find('./error')
                    
                    if failure is not None or error is not None:
                        # Use either failure or error element
                        element = failure if failure is not None else error
                        
                        # Extract error information
                        test_name = testcase.get('name', '')
                        class_name = testcase.get('class', '')
                        file = testcase.get('file', '')
                        line = int(testcase.get('line', '0'))
                        error_type = element.get('type', '')
                        message = element.text.strip() if element.text else ''
                        
                        # Extract line number from message if not in attributes
                        if line == 0 and message:
                            # Try to find line number in the message
                            import re
                            line_match = re.search(r'\.php:(\d+)', message)
                            if line_match:
                                line = int(line_match.group(1))
                        
                        errors.append(PhpUnitError(
                            message=message,
                            file=file,
                            line=line,
                            test_name=test_name,
                            error_type=error_type,
                            class_name=class_name
                        ))
        except ET.ParseError as e:
            print(f"Error parsing XML: {e}")
        
        return errors

    def format_for_mcp(self, errors: List[PhpUnitError], batch_index: int = 0) -> Dict[str, Any]:
        """
        Format errors for MCP integration.
        
        Args:
            errors: List of PHPUnit errors
            batch_index: Index of the current batch when processing incrementally
            
        Returns:
            Dictionary formatted for MCP
        """
        # Calculate the slice of errors for this batch
        start_idx = batch_index * self.max_errors_per_batch
        end_idx = start_idx + self.max_errors_per_batch
        batch_errors = errors[start_idx:end_idx]
        
        # Group errors by file
        errors_by_file = {}
        for error in batch_errors:
            if error.file not in errors_by_file:
                errors_by_file[error.file] = []
            errors_by_file[error.file].append(error.to_dict())
        
        # Format for MCP
        return {
            "batch": {
                "index": batch_index,
                "total_errors": len(errors),
                "batch_size": len(batch_errors),
                "has_more": end_idx < len(errors)
            },
            "errors_by_file": errors_by_file
        }

    def get_total_batches(self, total_errors: int) -> int:
        """
        Calculate the total number of batches needed.
        
        Args:
            total_errors: Total number of errors
            
        Returns:
            Number of batches needed
        """
        return (total_errors + self.max_errors_per_batch - 1) // self.max_errors_per_batch


def format_phpunit_output(phpunit_xml: str, max_errors_per_batch: int = 5, batch_index: int = 0) -> str:
    """
    Format PHPUnit XML output for MCP.
    
    Args:
        phpunit_xml: PHPUnit JUnit XML output
        max_errors_per_batch: Maximum errors per batch
        batch_index: Current batch index
        
    Returns:
        JSON string formatted for MCP
    """
    formatter = PhpUnitErrorFormatter(max_errors_per_batch)
    errors = formatter.parse_phpunit_xml(phpunit_xml)
    formatted = formatter.format_for_mcp(errors, batch_index)
    return json.dumps(formatted, indent=2)


if __name__ == "__main__":
    # Example usage
    import sys
    
    if len(sys.argv) > 1:
        # Read PHPUnit XML output from file
        with open(sys.argv[1], 'r') as f:
            phpunit_xml = f.read()
    else:
        # Read from stdin
        phpunit_xml = sys.stdin.read()
    
    # Parse batch index if provided
    batch_index = 0
    if len(sys.argv) > 2:
        try:
            batch_index = int(sys.argv[2])
        except ValueError:
            pass
    
    # Parse max errors per batch if provided
    max_errors = 5
    if len(sys.argv) > 3:
        try:
            max_errors = int(sys.argv[3])
        except ValueError:
            pass
    
    # Format and print
    formatted_output = format_phpunit_output(phpunit_xml, max_errors, batch_index)
    print(formatted_output)
