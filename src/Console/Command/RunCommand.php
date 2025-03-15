<?php

namespace Uzulla\McpPhpunit\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uzulla\McpPhpunit\McpIntegration\McpClient;

class RunCommand extends Command
{
    protected static $defaultName = 'run';
    protected static $defaultDescription = 'Run PHPUnit tests and fix failures with Claude Code';
    
    protected function configure()
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument(
                'project-path',
                InputArgument::OPTIONAL,
                'Path to the PHP project',
                getcwd()
            )
            ->addOption(
                'phpunit-binary',
                'p',
                InputOption::VALUE_REQUIRED,
                'Path to PHPUnit binary'
            )
            ->addOption(
                'test-path',
                't',
                InputOption::VALUE_REQUIRED,
                'Path to specific test file or directory'
            )
            ->addOption(
                'filter',
                'f',
                InputOption::VALUE_REQUIRED,
                'PHPUnit filter pattern'
            )
            ->addOption(
                'max-errors',
                'm',
                InputOption::VALUE_REQUIRED,
                'Maximum number of errors to process per batch',
                3
            )
            ->addOption(
                'max-iterations',
                'i',
                InputOption::VALUE_REQUIRED,
                'Maximum number of fix iterations',
                10
            )
            ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_NONE,
                'Verbose output'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Run without sending to Claude (for testing)'
            )
            ->addOption(
                'auto-mode',
                'a',
                InputOption::VALUE_NONE,
                'Run in automatic mode without user input prompts'
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Get command options
        $projectPath = $input->getArgument('project-path');
        $phpunitBinary = $input->getOption('phpunit-binary');
        $testPath = $input->getOption('test-path');
        $filter = $input->getOption('filter');
        $maxErrors = (int)$input->getOption('max-errors');
        $maxIterations = (int)$input->getOption('max-iterations');
        $verbose = $input->getOption('verbose');
        $dryRun = $input->getOption('dry-run');
        $autoMode = $input->getOption('auto-mode');
        
        // Validate project path
        if (!is_dir($projectPath)) {
            $io->error("Project path does not exist: {$projectPath}");
            return Command::FAILURE;
        }
        
        // Display configuration
        $io->section('Configuration');
        $io->table(
            ['Setting', 'Value'],
            [
                ['Project Path', $projectPath],
                ['PHPUnit Binary', $phpunitBinary ?: 'Auto-detect'],
                ['Test Path', $testPath ?: 'All tests'],
                ['Filter', $filter ?: 'None'],
                ['Max Errors Per Batch', $maxErrors],
                ['Max Iterations', $maxIterations],
                ['Verbose', $verbose ? 'Yes' : 'No'],
                ['Dry Run', $dryRun ? 'Yes' : 'No'],
                ['Auto Mode', $autoMode ? 'Yes' : 'No']
            ]
        );
        
        // Initialize MCP client
        $io->section('Initializing MCP Client');
        
        try {
            $mcpClient = new McpClient(
                $projectPath,
                $maxErrors,
                $phpunitBinary,
                $dryRun // Use mock API if dry run is enabled
            );
            
            // Process PHPUnit errors
            $io->section('Processing PHPUnit Tests');
            
            $success = $mcpClient->processPhpunitErrors(
                $testPath,
                $filter,
                $verbose,
                $maxIterations,
                $autoMode
            );
            
            if ($success) {
                $io->success('All PHPUnit tests pass!');
                return Command::SUCCESS;
            } else {
                $io->warning('Not all PHPUnit tests could be fixed.');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error("Error: {$e->getMessage()}");
            
            if ($verbose) {
                $io->error($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }
}
