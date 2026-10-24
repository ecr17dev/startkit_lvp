<?php

namespace Starter\Installer\Console;

use Starter\Installer\Config\ConfigValidator;
use Starter\Installer\ExitCode;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * laravel-starter validate <file>: the contract checks without a project.
 */
class ValidateCommand extends Command
{
    public function __construct()
    {
        parent::__construct('validate');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Validate a starter.config.json (schema, plain secrets and cross rules)')
            ->addArgument('file', InputArgument::OPTIONAL, 'Configuration file', 'starter.config.json')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ConfigValidator)->validateFile((string) $input->getArgument('file'));
        $code = $result['valid'] ? ExitCode::OK : ExitCode::CONFIG_INVALID;

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(['valid' => $result['valid'], 'errors' => $result['errors'], 'warnings' => $result['warnings']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $code;
        }

        $io = new SymfonyStyle($input, $output);
        $io->listing([...array_map(fn (string $error) => "<error>✗</error> {$error}", $result['errors']), ...array_map(fn (string $warning) => "<comment>!</comment> {$warning}", $result['warnings'])]);
        $result['valid'] ? $io->success('Valid configuration.') : $io->error('Invalid configuration.');

        return $code;
    }
}
