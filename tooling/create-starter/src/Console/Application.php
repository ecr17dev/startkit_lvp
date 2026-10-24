<?php

namespace Starter\Installer\Console;

use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * The `laravel-starter` executable.
 */
class Application extends ConsoleApplication
{
    public const VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct('Laravel Starter installer', self::VERSION);

        $this->addCommand(new NewCommand(version: self::VERSION));
        $this->addCommand(new ValidateCommand);
    }
}
