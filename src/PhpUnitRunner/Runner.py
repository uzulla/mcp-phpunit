"""
PHPUnit Runner Module

This module provides functionality to run PHPUnit tests and capture the output.
"""

import subprocess
import os
import sys
from typing import Dict, List, Optional, Tuple, Any


class PhpUnitRunner:
    """Runs PHPUnit tests and captures the output."""

    def __init__(self, project_path: str, phpunit_binary: Optional[str] = None):
        """
        Initialize the PHPUnit runner.
        
        Args:
            project_path: Path to the PHP project
            phpunit_binary: Path to the PHPUnit binary (defaults to vendor/bin/phpunit or global phpunit)
        """
        self.project_path = os.path.abspath(project_path)
        
        if phpunit_binary:
            self.phpunit_binary = phpunit_binary
        else:
            # Try to find PHPUnit binary
            vendor_phpunit = os.path.join(self.project_path, "vendor/bin/phpunit")
            if os.path.isfile(vendor_phpunit):
                self.phpunit_binary = vendor_phpunit
            else:
                # Use global PHPUnit
                self.phpunit_binary = "phpunit"
        
        # Check if the project path exists
        if not os.path.isdir(self.project_path):
            raise ValueError(f"Project path does not exist: {self.project_path}")

    def run_tests(self, 
                 test_path: Optional[str] = None,
                 output_xml: Optional[str] = None,
                 filter: Optional[str] = None,
                 verbose: bool = False) -> Tuple[str, int, Optional[str]]:
        """
        Run PHPUnit tests on the project.
        
        Args:
            test_path: Path to test file or directory (defaults to project path)
            output_xml: Path to save JUnit XML output
            filter: PHPUnit filter string
            verbose: Whether to run in verbose mode
            
        Returns:
            Tuple of (output, return_code, xml_output)
        """
        # Build the command
        cmd = [self.phpunit_binary]
        
        # Add verbose flag if requested
        if verbose:
            cmd.append("--verbose")
        
        # Add filter if specified
        if filter:
            cmd.extend(["--filter", filter])
        
        # Add output XML path if specified
        if output_xml:
            cmd.extend(["--log-junit", output_xml])
        
        # Add test path if specified
        if test_path:
            cmd.append(test_path)
        
        # Run the command
        try:
            result = subprocess.run(
                cmd,
                cwd=self.project_path,
                capture_output=True,
                text=True,
                check=False
            )
            
            # Read XML output if it was generated
            xml_content = None
            if output_xml and os.path.isfile(output_xml):
                with open(output_xml, 'r') as f:
                    xml_content = f.read()
            
            return result.stdout + result.stderr, result.returncode, xml_content
        except subprocess.SubprocessError as e:
            return f"Error running PHPUnit: {str(e)}", 1, None
        except Exception as e:
            return f"Unexpected error: {str(e)}", 1, None

    def check_installation(self) -> bool:
        """
        Check if PHPUnit is properly installed.
        
        Returns:
            True if PHPUnit is installed, False otherwise
        """
        try:
            result = subprocess.run(
                [self.phpunit_binary, "--version"],
                cwd=self.project_path,
                capture_output=True,
                text=True,
                check=False
            )
            return result.returncode == 0
        except Exception:
            return False


def run_phpunit(project_path: str, 
               test_path: Optional[str] = None,
               output_xml: Optional[str] = None,
               filter: Optional[str] = None,
               verbose: bool = False) -> Tuple[str, int, Optional[str]]:
    """
    Run PHPUnit on a project and optionally save the output to an XML file.
    
    Args:
        project_path: Path to the PHP project
        test_path: Path to test file or directory
        output_xml: Path to save JUnit XML output
        filter: PHPUnit filter string
        verbose: Whether to run in verbose mode
        
    Returns:
        Tuple of (output, return_code, xml_output)
    """
    runner = PhpUnitRunner(project_path)
    
    # Check if PHPUnit is installed
    if not runner.check_installation():
        error_msg = (
            "PHPUnit is not installed or not found at the expected location. "
            "Please install PHPUnit globally or via Composer."
        )
        return error_msg, 1, None
    
    # Run the tests
    return runner.run_tests(test_path, output_xml, filter, verbose)


if __name__ == "__main__":
    # Simple CLI interface
    import argparse
    
    parser = argparse.ArgumentParser(description="Run PHPUnit tests")
    parser.add_argument("project_path", help="Path to the PHP project")
    parser.add_argument("--test-path", "-t", help="Path to test file or directory")
    parser.add_argument("--output-xml", "-o", help="Path to save JUnit XML output")
    parser.add_argument("--filter", "-f", help="PHPUnit filter string")
    parser.add_argument("--verbose", "-v", action="store_true", help="Run in verbose mode")
    
    args = parser.parse_args()
    
    output, return_code, xml_output = run_phpunit(
        args.project_path,
        args.test_path,
        args.output_xml,
        args.filter,
        args.verbose
    )
    
    print(output)
    
    if xml_output:
        print("\nXML Output:")
        print(xml_output)
    
    sys.exit(return_code)
