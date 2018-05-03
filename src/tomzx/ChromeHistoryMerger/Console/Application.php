<?php

namespace tomzx\ChromeHistoryMerger\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use tomzx\ChromeHistoryMerger\Console\Command\MergeHistoryCommand;

class Application extends SymfonyApplication
{
    const VERSION = '@package_version@';

    private static $logo = '
        __            
  _____/ /_  ____ ___ 
 / ___/ __ \/ __ `__ \
/ /__/ / / / / / / / /
\___/_/ /_/_/ /_/ /_/ 
';

    public function __construct()
    {
        parent::__construct('Chrome History Merger by Tom Rochette', self::VERSION);
    }

    public function getHelp()
    {
        return self::$logo . parent::getHelp();
    }

    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();
        $commands[] = $this->add(new MergeHistoryCommand());
        return $commands;
    }
}
