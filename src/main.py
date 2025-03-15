"""
Main entry point for the MCP PHPUnit integration.

This script provides a command-line interface for running the MCP PHPUnit integration.
It handles parsing command-line arguments and running the appropriate functionality.
"""

import sys
import os
import argparse
from typing import List, Optional

# Add the project root to the Python path
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from src.McpIntegration.McpClient import McpClient


def parse_args() -> argparse.Namespace:
    """Parse command-line arguments."""
    parser = argparse.ArgumentParser(
        description="MCP PHPUnit Integration - Automatically fix PHPUnit test failures with Claude Code"
    )
    
    # Required arguments
    parser.add_argument(
        "project_path",
        help="Path to the PHP project to analyze"
    )
    
    # PHPUnit options
    phpunit_group = parser.add_argument_group("PHPUnit Options")
    phpunit_group.add_argument(
        "--test-path", "-t",
        help="Path to test file or directory"
    )
    phpunit_group.add_argument(
        "--filter", "-f",
        help="PHPUnit filter string"
    )
    phpunit_group.add_argument(
        "--verbose", "-v",
        action="store_true",
        help="Run PHPUnit in verbose mode"
    )
    
    # MCP options
    mcp_group = parser.add_argument_group("MCP Options")
    mcp_group.add_argument(
        "--max-errors", "-m",
        type=int,
        default=3,
        help="Maximum errors per batch (default: 3)"
    )
    mcp_group.add_argument(
        "--max-iterations", "-i",
        type=int,
        default=10,
        help="Maximum iterations to run (default: 10)"
    )
    mcp_group.add_argument(
        "--dry-run",
        action="store_true",
        help="Run without sending to Claude (for testing)"
    )
    
    return parser.parse_args()


def main() -> int:
    """Main entry point."""
    # Parse command-line arguments
    args = parse_args()
    
    # Initialize MCP client
    client = McpClient(
        args.project_path,
        max_errors_per_batch=args.max_errors
    )
    
    # If dry run, just analyze and print
    if args.dry_run:
        print("Running in dry-run mode (no fixes will be applied)")
        
        # Run PHPUnit tests
        output, return_code, batches = client.run_phpunit_tests(
            args.test_path,
            args.filter,
            args.verbose
        )
        
        # Print results
        print(output)
        
        if return_code == 0:
            print("All PHPUnit tests pass!")
            return 0
        
        print(f"Found {sum(batch['batch']['batch_size'] for batch in batches)} test failures in {len(batches)} batches")
        
        # Print first batch as example
        if batches:
            print("\nExample batch:")
            for file_path, errors in batches[0]["errors_by_file"].items():
                print(f"\nFile: {file_path}")
                for error in errors:
                    print(f"  Line {error['line']}: {error['message']}")
        
        return 1
    
    # Process PHPUnit test failures
    success = client.process_phpunit_errors(
        args.test_path,
        args.filter,
        args.verbose,
        args.max_iterations
    )
    
    return 0 if success else 1


if __name__ == "__main__":
    sys.exit(main())
