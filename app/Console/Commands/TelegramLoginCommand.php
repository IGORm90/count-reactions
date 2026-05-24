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
        {--phone= : Phone number to send code to}
        {--password= : 2FA password if enabled}
        {--poll-code : Poll GitHub variable TG_LOGIN_CODE for the verification code}
        {--code= : Verification code (for local/manual use)}
        {--timeout=300 : Max seconds to wait for code when polling}';

    protected $description = 'Telegram login (supports polling GitHub variable for CI)';

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
        $this->info('Code sent!');

        $code = $this->option('code');

        if (!$code && $this->option('poll-code')) {
            $code = $this->pollForCode();
        }

        if (!$code) {
            $this->error('No code provided. Use --code or --poll-code');
            return self::FAILURE;
        }

        $this->info('Completing login with code...');
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
        $token = env('GITHUB_TOKEN');

        if (!$repo || !$token) {
            $this->error('GITHUB_REPOSITORY and GITHUB_TOKEN are required for --poll-code');
            return null;
        }

        $timeout = (int) $this->option('timeout');
        $deadline = time() + $timeout;

        $this->info("Polling GitHub variable TG_LOGIN_CODE (timeout: {$timeout}s)...");
        $this->info('Set the variable in repo Settings > Variables > Actions to your code.');

        // Clear the variable first
        $this->setGithubVariable($repo, $token, 'TG_LOGIN_CODE', 'WAITING');

        while (time() < $deadline) {
            sleep(5);

            $value = $this->getGithubVariable($repo, $token, 'TG_LOGIN_CODE');

            if ($value && $value !== 'WAITING' && $value !== '') {
                $this->info("Got code: {$value}");
                // Clear it after reading
                $this->setGithubVariable($repo, $token, 'TG_LOGIN_CODE', 'USED');
                return $value;
            }

            $remaining = $deadline - time();
            if ($remaining % 30 < 5) {
                $this->info("Still waiting... ({$remaining}s remaining)");
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

        return null;
    }

    private function setGithubVariable(string $repo, string $token, string $name, string $value): void
    {
        $url = "https://api.github.com/repos/{$repo}/actions/variables/{$name}";

        $response = Http::withToken($token)
            ->patch($url, ['name' => $name, 'value' => $value]);

        if (!$response->successful()) {
            // Variable might not exist, try creating
            Http::withToken($token)
                ->post("https://api.github.com/repos/{$repo}/actions/variables", [
                    'name' => $name,
                    'value' => $value,
                ]);
        }
    }
}
