<?php

namespace Adldap\Laravel\Commands;

use Adldap\Models\User;
use Adldap\Laravel\Traits\ImportsUsers;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class Import extends Command
{
    use ImportsUsers;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'adldap:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports LDAP users into the local database with a random 16 character hashed password.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Retrieve the Adldap instance.
        $adldap = $this->getAdldap();

        if (!$adldap->getConnection()->isBound()) {
            // If the connection isn't bound yet, we'll connect to the server manually.
            $adldap->connect();
        }

        $search = $adldap->search()->users();

        if ($filter = $this->option('filter')) {
            // If the filter option was given, we'll
            // insert it into our search query.
            $search->rawFilter($filter);
        }

        if ($user = $this->argument('user')) {
            $users = [$search->findOrFail($user)];

            $this->info("Found user '{$users[0]->getCommonName()}'. Importing...");
        } else {
            // Retrieve all users.
            $users = $search->paginate()->getResults();

            $count = count($users);

            $this->info("Found {$count} user(s). Starting import...");
        }

        $this->info("Successfully imported {$this->import($users)} user(s).");
    }

    /**
     * Imports the specified users and returns the total
     * number of users successfully imported.
     *
     * @param mixed $users
     *
     * @return int
     */
    public function import($users = [])
    {
        $imported = 0;

        foreach ($users as $user) {
            if ($user instanceof User) {
                try {
                    // Import the user and then save the model.
                    $model = $this->getModelFromAdldap($user);

                    if ($this->saveModel($model) && $model->wasRecentlyCreated) {
                        // Only increment imported for new models.
                        $imported++;

                        // Log the successful import.
                        if ($this->isLogging()) {
                            logger()->info("Imported user {$user->getCommonName()}");
                        }
                    }
                } catch (\Exception $e) {
                    // Log the unsuccessful import.
                    if ($this->isLogging()) {
                        logger()->error("Unable to import user {$user->getCommonName()}. {$e->getMessage()}");
                    }
                }
            }
        }

        return $imported;
    }

    /**
     * Returns true / false if the current import is being logged.
     *
     * @return bool
     */
    public function isLogging()
    {
        return $this->option('log') == 'true';
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    public function getArguments()
    {
        return [
            ['user', InputArgument::OPTIONAL, 'The specific user to import using ANR.'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    public function getOptions()
    {
        return [
            ['filter', null, InputOption::VALUE_OPTIONAL, 'The raw filter for limiting users imported.'],

            ['log', true, InputOption::VALUE_OPTIONAL, 'Log successful and unsuccessful imported users.']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function createModel()
    {
        $model = auth()->getProvider()->getModel();

        return new $model();
    }
}
