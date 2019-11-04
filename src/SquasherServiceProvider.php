<?php
/**
 * Created by IntelliJ IDEA.
 * User: jbarber
 * Date: 11/1/13
 * Time: 3:52 PM
 */

namespace Stackout\Squasher;

use Illuminate\Support\ServiceProvider;
use Stackout\Squasher\Command\SquashMigrations;

class SquasherServiceProvider extends ServiceProvider{

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerMigrationSquasher();

        $this->commands(
            'migrate:squash'
        );
    }

    /**
     * Register generate:model
     *
     * @return \Stackout\Squasher\Command\SquashMigrations
     */
    protected function registerMigrationSquasher()
    {
        $this->app->bind('migrate:squash', function($app)
        {
            return new SquashMigrations();
        });
    }
}