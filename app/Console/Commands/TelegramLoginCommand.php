<?php

namespace App\Console\Commands;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Console\Command;

class TelegramLoginCommand extends Command
{
    protected $signature = 'telegram:login';

    protected $description = 'Telegram QR code login';

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
        $api->start();

        $this->info('Login successful!');
        return self::SUCCESS;
    }
}
