<?php

namespace Uzulla\McpPhpunit\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Uzulla\McpPhpunit\Console\Command\RunCommand;

class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('MCP PHPUnit Integration', '1.0.0');
        
        // Add commands
        $this->add(new RunCommand());
    }
    
    protected function getDefaultCommands(): array
    {
        $defaultCommands = parent::getDefaultCommands();
        
        // Add custom default commands here if needed
        
        return $defaultCommands;
    }
    
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        // Display application header
        if (!$input->hasParameterOption(['--quiet', '-q'])) {
            $output->writeln([
                '<info>MCP PHPUnit Integration - Automatically fix PHPUnit test failures with Claude Code</info>',
                '<comment>Version: ' . $this->getVersion() . '</comment>',
                ''
            ]);
        }
        
        return parent::doRun($input, $output);
    }
}
