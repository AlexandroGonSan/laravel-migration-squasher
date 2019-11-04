<?php
namespace Stackout\Squasher\Command;

use Stackout\Squasher\MigrationSquasher;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class SquashMigrations extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'migrate:squash';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aggregates all of your migrations into one migration per table.';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        (new MigrationSquasher($this->option('path'), $this->option('output'), $this->option('move-to')))->squash();
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array();
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array(
            array('path', 'p', InputOption::VALUE_OPTIONAL, 'The path to the migrations folder',
                'database/migrations'),
            array('output', 'o', InputOption::VALUE_OPTIONAL, 'The path to the output folder of squashes',
                'tests/migrations'),
            array('move-to', 'mv', InputOption::VALUE_OPTIONAL, 'The path where old migrations will be moved.',
                'database/migrations')
        );
    }

}
