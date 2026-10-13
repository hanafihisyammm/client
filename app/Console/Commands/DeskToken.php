<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class DeskToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'desk:token
        {email : The user the token will act as}
        {--name=automation : A label to tell this token apart from others}
        {--revoke : Delete the user\'s tokens with this name instead of creating one}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or revoke an API token that lets a script use the API as a user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user found with that email.');

            return self::FAILURE;
        }

        $name = $this->option('name');

        if ($this->option('revoke')) {
            $deleted = $user->tokens()->where('name', $name)->delete();
            $this->info("Revoked {$deleted} token(s) named \"{$name}\" for {$user->email}.");

            return self::SUCCESS;
        }

        if (! $user->isActive()) {
            $this->error('That user is deactivated, so a token for them would not work.');

            return self::FAILURE;
        }

        // The token can do whatever this user can do, so it is only shown once
        $token = $user->createToken($name)->plainTextToken;

        $this->line($token);

        return self::SUCCESS;
    }
}
