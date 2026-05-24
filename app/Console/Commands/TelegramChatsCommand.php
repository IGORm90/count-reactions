<?php

namespace App\Console\Commands;

use App\Services\Telegram\MadelineFactory;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Console\Command;

class TelegramChatsCommand extends Command
{
    protected $signature = 'telegram:chats
        {--type=all : Фильтр по типу: all|user|bot|chat|supergroup|channel}
        {--json : Вывести как JSON}';

    protected $description = 'Список чатов текущего Telegram-аккаунта';

    public function handle(): int
    {
        try {
            $mp = (new MadelineFactory())->make();

            $this->info('Загрузка диалогов...');
            $dialogs = $mp->getFullDialogs();

            $rows = [];
            foreach (array_keys($dialogs) as $peerId) {
                try {
                    $info = $mp->getInfo($peerId);
                } catch (\Throwable) {
                    continue;
                }

                $type = $info['type'] ?? 'unknown';

                $title = match ($type) {
                    'user', 'bot' => trim(
                        ($info['User']['first_name'] ?? '') . ' ' . ($info['User']['last_name'] ?? '')
                    ),
                    default => $info['Chat']['title'] ?? $info['Channel']['title'] ?? '(no title)',
                };

                $username = $info['User']['username']
                    ?? $info['Chat']['username']
                    ?? $info['Channel']['username']
                    ?? null;

                $rows[] = [
                    'id'       => $info['bot_api_id'] ?? '',
                    'type'     => $type,
                    'title'    => $title,
                    'username' => $username ? "@{$username}" : '',
                ];
            }

            $filterType = $this->option('type');
            if ($filterType !== 'all') {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row) => $row['type'] === $filterType,
                ));
            }

            if ($this->option('json')) {
                $this->line(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } else {
                $this->table(['ID', 'Type', 'Title', 'Username'], $rows);
                $this->info('Всего: ' . count($rows));
            }

            return self::SUCCESS;
        } catch (RPCErrorException $e) {
            $this->error("Telegram RPC error: {$e->rpc}");
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
