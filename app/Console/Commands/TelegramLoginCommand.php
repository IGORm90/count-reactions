<?php

namespace App\Console\Commands;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramLoginCommand extends Command
{
    protected $signature = 'telegram:login
        {--phone= : Phone number}
        {--password= : 2FA password if enabled}
        {--timeout=300 : Seconds to wait for code}';

    protected $description = 'Telegram login for CI (polls GitHub variable for code)';

    public function handle(): int
    {
        $phone = $this->option('phone');
        if (!$phone) {
            $this->error('--phone is required');
            return self::FAILURE;
        }

        $settings = new Settings();
        $settings->setAppInfo(
            (new AppInfo())
                ->setApiId((int) env('TG_API_ID'))
                ->setApiHash(env('TG_API_HASH'))
        );

        $sessionPath = storage_path('app/telegram/userbot.madeline');
        $api = new API($sessionPath, $settings);

        $this->info("Sending login code to {$phone}...");
        $api->phoneLogin($phone);
        $this->info('Code sent! Set TG_LOGIN_CODE variable in repo settings.');

        $code = $this->pollForCode();
        if (!$code) {
            return self::FAILURE;
        }

        $this->info("Completing login with code {$code}...");
        $authorization = $api->completePhoneLogin($code);

        if (($authorization['_'] ?? '') === 'account.password') {
            $password = $this->option('password');
            if (!$password) {
                $this->error('2FA is enabled. Provide --password');
                return self::FAILURE;
            }
            $api->complete2faLogin($password);
        }

        $this->info('Login successful!');
        return self::SUCCESS;
    }

    private function pollForCode(): ?string
    {
        $repo = env('GITHUB_REPOSITORY');
        $token = env('GH_PAT');

        if (!$repo || !$token) {
            $this->error('GITHUB_REPOSITORY and GH_PAT env vars are required');
            return null;
        }

        $timeout = (int) $this->option('timeout');
        $deadline = time() + $timeout;

        // Reset variable to WAITING
        $this->setGithubVariable($repo, $token, 'TG_LOGIN_CODE', 'WAITING');
        $this->info("Polling TG_LOGIN_CODE variable (timeout: {$timeout}s)...");

        while (time() < $deadline) {
            sleep(5);

            $value = $this->getGithubVariable($repo, $token, 'TG_LOGIN_CODE');

            if ($value && $value !== 'WAITING' && $value !== 'USED') {
                $this->info("Got code: {$value}");
                $this->setGithubVariable($repo, $token, 'TG_LOGIN_CODE', 'USED');
                return $value;
            }

            $remaining = $deadline - time();
            if ($remaining % 30 < 6) {
                $this->info("Waiting for code... ({$remaining}s left)");
            }
        }

        $this->error('Timeout waiting for code');
        return null;
    }

    private function getGithubVariable(string $repo, string $token, string $name): ?string
    {
        $response = Http::withToken($token)
            ->get("https://api.github.com/repos/{$repo}/actions/variables/{$name}");

        if ($response->successful()) {
            return $response->json('value');
        }

        $this->warn("API error: {$response->status()} {$response->body()}");
        return null;
    }

    private function setGithubVariable(string $repo, string $token, string $name, string $value): void
    {
        $url = "https://api.github.com/repos/{$repo}/actions/variables/{$name}";

        $response = Http::withToken($token)
            ->patch($url, ['name' => $name, 'value' => $value]);

        if (!$response->successful()) {
            Http::withToken($token)
                ->post("https://api.github.com/repos/{$repo}/actions/variables", [
                    'name' => $name,
                    'value' => $value,
                ]);
        }
    }
}
