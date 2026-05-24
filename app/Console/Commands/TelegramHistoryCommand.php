<?php

namespace App\Console\Commands;

use App\Services\Telegram\MadelineFactory;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Console\Command;

class TelegramHistoryCommand extends Command
{
    protected $signature = 'telegram:history
        {chat? : @username, числовой id (-100…), t.me-ссылка или id пользователя}
        {--limit=100 : Сколько сообщений выгрузить (игнорируется при --all)}
        {--all : Выгрузить всю историю}
        {--save= : Путь к JSON-файлу для сохранения}
        {--with-media : Скачивать вложения в storage/app/telegram/media/{chat_id}/}
        {--since= : Выгрузить сообщения не старше указанной даты (например: "1 day ago", "2025-05-23", "3 hours ago")}';

    protected $description = 'Выгрузка истории сообщений Telegram-чата';

    public function handle(): int
    {
        try {
            $chat = $this->argument('chat') ?? env('TG_CHAT_ID');
            if (!$chat) {
                $this->error('Укажите чат аргументом или задайте TG_CHAT_ID в .env');
                return self::FAILURE;
            }
            $mp = (new MadelineFactory())->make();

            // Populate internal peer database so getInfo() can resolve the chat
            $mp->getDialogIds();

            $info = $mp->getInfo($chat);
            $peerId = $info['bot_api_id'];

            $this->info("Чат: {$peerId}");

            $collected = [];
            $offsetId = 0;
            $limit = (int) $this->option('limit');
            $all = (bool) $this->option('all');
            $since = $this->option('since');
            $sinceTs = 0;
            $batchSize = 100;
            $bar = null;

            if ($since) {
                $sinceTs = strtotime($since);
                if ($sinceTs === false) {
                    $this->error("Не удалось разобрать дату: {$since}");
                    return self::FAILURE;
                }
                $all = true;
                $this->info('Сообщения с: ' . date('Y-m-d H:i:s', $sinceTs));
            }

            do {
                try {
                    $resp = $mp->messages->getHistory([
                        'peer'        => $chat,
                        'offset_id'   => $offsetId,
                        'offset_date' => 0,
                        'add_offset'  => 0,
                        'limit'       => $batchSize,
                        'max_id'      => 0,
                        'min_id'      => 0,
                        'hash'        => 0,
                    ]);
                } catch (RPCErrorException $e) {
                    $this->warn("RPC error: {$e->rpc} — пауза 10с");
                    sleep(10);
                    continue;
                }

                if ($all && $bar === null && isset($resp['count'])) {
                    $bar = $this->output->createProgressBar($resp['count']);
                    $bar->start();
                }

                $batch = $resp['messages'] ?? [];
                if (empty($batch)) {
                    break;
                }

                foreach ($batch as $msg) {
                    $msgDate = $msg['date'] ?? 0;

                    if ($sinceTs && $msgDate < $sinceTs) {
                        break 2;
                    }

                    $collected[] = $this->normalize($msg);

                    if ($this->option('with-media') && isset($msg['media'])) {
                        $this->downloadMedia($mp, $msg, $peerId);
                    }

                    $bar?->advance();

                    if (!$all && count($collected) >= $limit) {
                        break 2;
                    }
                }

                $offsetId = end($batch)['id'];
                usleep(300_000);
            } while ($all || count($collected) < $limit);

            $bar?->finish();
            if ($bar) {
                $this->newLine();
            }

            $savePath = $this->option('save');
            if ($savePath) {
                file_put_contents(
                    $savePath,
                    json_encode($collected, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                );
                $this->info("Сохранено в {$savePath}");
            }

            $this->info('Получено: ' . count($collected) . ' сообщений');

            $top = collect($collected)
                ->filter(fn ($msg) => $msg['from_id'] !== null && $msg['reactions_total'] > 0)
                ->groupBy('from_id')
                ->map(fn ($msgs, $userId) => [
                    'user_id'   => $userId,
                    'reactions'  => $msgs->sum('reactions_total'),
                    'messages'   => $msgs->count(),
                ])
                ->sortByDesc('reactions')
                ->take(5)
                ->values();

            if ($top->isNotEmpty()) {
                $this->newLine();
                $this->info('Топ-5 по реакциям:');
                $topRows = $top->map(function ($row, $i) use ($mp) {
                    $name = $row['user_id'];
                    try {
                        $info = $mp->getInfo($row['user_id']);
                        $name = match ($info['type'] ?? '') {
                            'user', 'bot' => trim(($info['User']['first_name'] ?? '') . ' ' . ($info['User']['last_name'] ?? '')),
                            default => $info['Chat']['title'] ?? $info['Channel']['title'] ?? $name,
                        };
                        if ($username = $info['User']['username'] ?? null) {
                            $name .= " (@{$username})";
                        }
                    } catch (\Throwable) {}
                    return [$i + 1, $name, $row['reactions'], $row['messages']];
                })->toArray();
                $this->table(['#', 'Пользователь', 'Реакций', 'Сообщений'], $topRows);

                $medals = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];
                $lines = ["🏆 **Топ-5 по реакциям за последний день:**\n"];
                foreach ($topRows as $i => $row) {
                    $lines[] = "{$medals[$i]} {$row[1]} — {$row[2]} реакций ({$row[3]} сообщ.)";
                }
                $text = implode("\n", $lines);

                $mp->messages->sendMessage([
                    'peer'       => $chat,
                    'message'    => $text,
                    'parse_mode' => 'Markdown',
                ]);
                $this->info('Топ отправлен в чат.');
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

    private function normalize(array $msg): array
    {
        if (($msg['_'] ?? '') === 'messageService') {
            $text = '[service: ' . ($msg['action']['_'] ?? 'unknown') . ']';
        } else {
            $text = $msg['message'] ?? '';
        }

        $reactions = [];
        $reactionsTotal = 0;
        foreach ($msg['reactions']['results'] ?? [] as $r) {
            $emoji = $r['reaction']['emoticon']
                ?? ($r['reaction']['_'] === 'reactionPaid' ? '⭐' : null)
                ?? "custom:{$r['reaction']['document_id']}"
            ;
            $count = $r['count'] ?? 0;
            $reactions[$emoji] = $count;
            $reactionsTotal += $count;
        }

        return [
            'id'              => $msg['id'],
            'date'            => date('Y-m-d H:i:s', $msg['date'] ?? 0),
            'from_id'         => $msg['from_id'] ?? null,
            'text'            => $text,
            'reply_to'        => $msg['reply_to']['reply_to_msg_id'] ?? null,
            'has_media'       => isset($msg['media']),
            'media_type'      => $msg['media']['_'] ?? null,
            'views'           => $msg['views'] ?? null,
            'forwards'        => $msg['forwards'] ?? null,
            'reactions'       => $reactions,
            'reactions_total' => $reactionsTotal,
        ];
    }

    private function downloadMedia($mp, array $msg, string|int $peerId): void
    {
        $path = storage_path("app/telegram/media/{$peerId}");
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }

        try {
            $downloadInfo = $mp->getDownloadInfo($msg);
            $name = ($downloadInfo['name'] ?? $msg['id']) . ($downloadInfo['ext'] ?? '');
            $mp->downloadToFile($msg, "{$path}/{$name}");
        } catch (\Throwable $e) {
            $this->warn("Не удалось скачать медиа msg#{$msg['id']}: {$e->getMessage()}");
        }
    }
}
