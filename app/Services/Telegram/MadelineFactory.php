<?php

namespace App\Services\Telegram;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;

class MadelineFactory
{
    public function make(): API
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

        return $api;
    }
}
