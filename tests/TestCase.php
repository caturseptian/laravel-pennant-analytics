<?php

namespace CaturSeptian\PennantAnalytics\Tests;

use CaturSeptian\PennantAnalytics\PennantAnalyticsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\PennantServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PennantServiceProvider::class, PennantAnalyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        // DB_CONNECTION=mysql|pgsql runs the suite against a real server (see CI).
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
        $app['config']->set('pennant.default', 'array');
        $app['config']->set('pennant-analytics.persistence', 'sync');
        $app['config']->set('auth.providers.users.model', TestUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_pennant_analytics_tables.php';
        $migration->down();
        $migration->up();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    protected function user(int $id): TestUser
    {
        return (new TestUser)->forceFill(['id' => $id, 'name' => "User {$id}"]);
    }
}

class TestUser extends User
{
    protected $table = 'users';

    public $timestamps = false;
}
