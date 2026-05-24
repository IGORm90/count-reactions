<?php

namespace App\Console\Commands;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Console\Command;

class TelegramLoginCommand extends Command
{
    protected $signature = 'telegram:login
        {--phone= : Phone number to send code to}
        {--code= : Verification code received from Telegram}
        {--password= : 2FA password if enabled}';

    protected $description = 'Non-interactive Telegram login for CI';

    public function handle(): int
    {
        $settings = new Settings();
        $settings->setAppInfo(
            (new AppInfo())
                ->setApiId((int) env('TG_API_ID'))
                ->setApiHash(env('TG_API_HASH'))
        );

        $sessionPath = storage_path('app/telegram/userbot.madeline');

        $api = new API($sessionPath, $settings);

        $phone = $this->option('phone');
        $code = $this->option('code');
        $password = $this->option('password');

        if ($phone) {
            $this->info("Sending login code to {$phone}...");
            $api->phoneLogin($phone);
            $this->info('Code sent. Run this command again with --code=XXXXX');
            return self::SUCCESS;
        }

        if ($code) {
            $this->info('Completing phone login...');
            $authorization = $api->completePhoneLogin($code);

            if ($authorization['_'] === 'account.password') {
                if (!$password) {
                    $this->error('2FA is enabled. Run again with --code and --password');
                    return self::FAILURE;
                }
                $api->complete2faLogin($password);
            }

            $this->info('Login successful!');
            return self::SUCCESS;
        }

        $this->error('Provide --phone to start login or --code to complete it.');
        return self::FAILURE;
    }
}
