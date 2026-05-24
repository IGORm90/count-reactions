# План: две artisan-команды для Telegram userbot

Реализуй на Laravel + [MadelineProto](https://docs.madelineproto.xyz/) две консольные команды, работающие через личный аккаунт Telegram (userbot, **не** Bot API):

1. `telegram:chats` — выводит список всех чатов, в которых состоит пользователь.
2. `telegram:history {chat} {--limit=100} {--all}` — выгружает историю сообщений конкретного чата.

---

## 0. Предусловия

- PHP 8.2+
- Laravel 10/11/12
- Установленные расширения: `mbstring`, `json`, `xml`, `dom`, желательно `gmp` или `bcmath`
- В `.env` уже должны лежать `TG_API_ID` и `TG_API_HASH` с https://my.telegram.org
- Файл сессии: `storage/app/telegram/userbot.madeline` (создаётся при первом логине)

Если сессии ещё нет — первая команда сама запросит номер/код/2FA и сохранит её.

---

## 1. Установка зависимостей

```bash
composer require danog/madelineproto
mkdir -p storage/app/telegram
```

Добавить в `.gitignore`:

```
/storage/app/telegram
```

Сессионный файл = полный доступ к аккаунту, в репозиторий его попадать не должно.

---

## 2. Общий сервис для подключения

Чтобы не дублировать инициализацию MadelineProto в каждой команде, создай тонкий сервис.

**Файл:** `app/Services/Telegram/MadelineFactory.php`

Задачи сервиса:
- Метод `make(): \danog\MadelineProto\API` — возвращает готовый экземпляр API.
- Внутри: собрать `Settings\AppInfo` из `env('TG_API_ID')`, `env('TG_API_HASH')`.
- Путь к сессии: `storage_path('app/telegram/userbot.madeline')`.
- Перед возвратом вызвать `$API->start()` — это либо подхватит сохранённую сессию, либо в интерактивном режиме попросит логин/код/2FA.

Зарегистрировать как singleton в `AppServiceProvider` не обязательно — обе команды короткоживущие, можно `new` прямо в `handle()`.

---

## 3. Команда 1: `telegram:chats`

**Файл:** `app/Console/Commands/TelegramChatsCommand.php`

```bash
php artisan make:command TelegramChatsCommand
```

### Сигнатура

```
telegram:chats {--type=all : all|user|group|channel|supergroup} {--json : вывести как JSON}
```

### Логика `handle()`

1. Получить API: `$mp = (new MadelineFactory)->make();`
2. Вытащить все диалоги:
   ```php
   $peers = $mp->getDialogs();
   ```
   Возвращается массив peer-объектов (по сути id чатов).
3. Для каждого peer получить подробности:
   ```php
   $info = $mp->getInfo($peer);
   ```
   В `$info` есть поля:
    - `bot_api_id` — числовой id в Bot API формате (`-100…` для супергрупп/каналов)
    - `type` — `user` / `bot` / `chat` / `supergroup` / `channel`
    - `User` / `Chat` / `Channel` — оригинальный объект из MTProto
    - `InputPeer` — то, что можно передавать в дальнейшие запросы
4. Собрать унифицированный массив строк:
   ```
   [
     'id'       => $info['bot_api_id'],
     'type'     => $info['type'],
     'title'    => для группы/канала — $info['Chat']['title'] ?? $info['Channel']['title'],
                   для юзера — trim(first_name . ' ' . last_name)
     'username' => $info['Chat']['username'] ?? $info['User']['username'] ?? null,
   ]
   ```
5. Если есть `--type` и он не `all` — отфильтровать по `type`.
6. Вывод:
    - `--json` → `$this->line(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));`
    - иначе → `$this->table(['ID', 'Type', 'Title', 'Username'], $rows);`

### Обработка ошибок

- Обернуть в try/catch на `\Throwable`, выводить `$this->error($e->getMessage())` и возвращать `self::FAILURE`.
- Особо ловить `danog\MadelineProto\RPCErrorException` — типичные коды: `FLOOD_WAIT_X`, `AUTH_KEY_UNREGISTERED`.

---

## 4. Команда 2: `telegram:history`

**Файл:** `app/Console/Commands/TelegramHistoryCommand.php`

```bash
php artisan make:command TelegramHistoryCommand
```

### Сигнатура

```
telegram:history
    {chat               : @username, числовой id (-100…), t.me-ссылка или id пользователя}
    {--limit=100        : сколько сообщений выгрузить (игнорируется, если --all)}
    {--all              : выгрузить всю историю (с пагинацией)}
    {--save=            : путь к JSON-файлу для сохранения (необязательно)}
    {--with-media       : скачивать вложения в storage/app/telegram/media/{chat_id}/}
```

### Логика `handle()`

1. `$chat = $this->argument('chat');` — MadelineProto сама поймёт формат peer'а.
2. `$mp = (new MadelineFactory)->make();`
3. Проверить, что чат вообще доступен:
   ```php
   $info = $mp->getInfo($chat);
   $peerId = $info['bot_api_id'];
   ```
4. Подготовить контейнер `$collected = [];`.
5. Пагинация:

   ```php
   $offsetId = 0;
   $limit    = (int) $this->option('limit');
   $all      = (bool) $this->option('all');
   $batchSize = 100; // максимум, который отдаёт Telegram за запрос

   do {
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

       $batch = $resp['messages'] ?? [];
       if (empty($batch)) {
           break;
       }

       foreach ($batch as $msg) {
           $collected[] = $this->normalize($msg);

           if (!$all && count($collected) >= $limit) {
               break 2;
           }
       }

       $offsetId = end($batch)['id'];
       usleep(300_000); // 0.3 с — щадим лимиты
   } while ($all || count($collected) < $limit);
   ```

6. Метод `normalize(array $msg): array` приводит сообщение к плоскому виду:
   ```php
   return [
       'id'            => $msg['id'],
       'date'          => date('Y-m-d H:i:s', $msg['date'] ?? 0),
       'from_id'       => $msg['from_id']['user_id']
                          ?? $msg['from_id']['channel_id']
                          ?? null,
       'text'          => $msg['message'] ?? '',
       'reply_to'      => $msg['reply_to']['reply_to_msg_id'] ?? null,
       'has_media'     => isset($msg['media']),
       'media_type'    => $msg['media']['_'] ?? null, // messageMediaPhoto, …Document, …
       'views'         => $msg['views'] ?? null,
       'forwards'      => $msg['forwards'] ?? null,
   ];
   ```

7. Если передан `--with-media` и у сообщения есть медиа — вызвать:
   ```php
   $path = storage_path("app/telegram/media/{$peerId}");
   if (!is_dir($path)) mkdir($path, 0775, true);
   $mp->downloadToFile($msg, "{$path}/{$msg['id']}");
   ```
   (Имя файла без расширения — MadelineProto сама подберёт; если нужно — взять `$info = $mp->getDownloadInfo($msg); $name = $info['name'].$info['ext'];`.)

8. Вывод:
    - Если задан `--save=path.json` → сохранить `json_encode($collected, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)` в файл.
    - Иначе → `$this->table(['ID', 'Date', 'From', 'Text'], $rowsForTable)`, обрезая текст до ~80 символов.

9. Финальная сводка: `$this->info("Получено: " . count($collected) . " сообщений");`

### Прогресс-бар

При `--all` использовать `$this->output->createProgressBar()`. Общее количество — `$resp['count']` из первого ответа (для каналов/супергрупп это поле есть; для обычных чатов может отсутствовать — тогда бар без max).

### Обработка `FloodWait`

MadelineProto по умолчанию автоматически ждёт, если флуд-вейт меньше определённого порога. Но на длинных выгрузках всё равно стоит ловить `RPCErrorException` и логировать:

```php
try { /* getHistory */ }
catch (\danog\MadelineProto\RPCErrorException $e) {
    $this->warn("RPC error: {$e->rpc} — пауза 10с");
    sleep(10);
    continue;
}
```

---

## 5. Структура итоговых файлов

```
app/
├── Console/
│   └── Commands/
│       ├── TelegramChatsCommand.php
│       └── TelegramHistoryCommand.php
└── Services/
    └── Telegram/
        └── MadelineFactory.php
storage/
└── app/
    └── telegram/
        ├── userbot.madeline        ← сессия (в .gitignore)
        └── media/                  ← скачанные вложения (в .gitignore)
```

В Laravel 11/12 команды автоматически регистрируются через `app/Console/Kernel.php` (или `bootstrap/app.php` в 11+) — отдельная регистрация не нужна, если используется автодискавери из `Console\Commands`.

---

## 6. Сценарий проверки

```bash
# 1. Первый запуск — попросит номер/код/2FA
php artisan telegram:chats

# 2. Список только групп в JSON
php artisan telegram:chats --type=supergroup --json

# 3. Последние 50 сообщений из чата по username
php artisan telegram:history @some_chat --limit=50

# 4. Вся история по числовому id, в файл, с медиа
php artisan telegram:history -1001234567890 --all --save=storage/app/telegram/dump.json --with-media
```

---

## 7. Подводные камни — учти при реализации

- **getHistory вернёт максимум 100 за запрос.** Любые большие значения `--limit` всё равно разбиваются на пачки по 100 внутри цикла.
- **`getDialogs()` тоже пагинируется**, но MadelineProto оборачивает это сама и возвращает все диалоги. Для аккаунтов с тысячами чатов вызов может быть долгим — это нормально.
- **`from_id` у каналов** — это id канала, а не пользователя; в анонимных группах может быть `null` или id админ-канала. Не падай на отсутствии поля.
- **Служебные сообщения** (`messageService` — добавление участника, смена аватара и т.п.) имеют другую структуру: нет `message`, есть `action`. В `normalize()` проверь `$msg['_']` — если `messageService`, текст лучше брать как `'[service: ' . $msg['action']['_'] . ']'`.
- **Удалённые сообщения** в истории не приходят. Если нужны — отдельная задача через `updates`, не входит в этот план.
- **Не клади `userbot.madeline` в git.** Сессия = полный доступ к аккаунту.
- **Userbot против ToS Telegram при злоупотреблениях.** Личное использование (читать свои чаты, выгружать историю) обычно безопасно, массовая активность приводит к блокировке аккаунта.

---

## 8. Что НЕ делать в этой итерации

- Не запускать постоянный EventHandler — это другая задача (реалтайм-приём).
- Не строить очереди/джобы — обе команды синхронные и короткоживущие (ну или долгие при `--all`, но не демоны).
- Не лезть в БД — вывод в консоль/JSON-файл. Сохранение в БД — следующий шаг, если понадобится.

---

## 9. Definition of Done

- [ ] `composer require danog/madelineproto` выполнен, lock-файл обновлён.
- [ ] `MadelineFactory` создаёт API и поднимает сессию.
- [ ] `php artisan telegram:chats` показывает таблицу чатов с корректными id/типами/названиями.
- [ ] `--type` и `--json` у `telegram:chats` работают.
- [ ] `php artisan telegram:history @chat --limit=10` возвращает последние 10 сообщений.
- [ ] `--all` корректно пагинирует и не зависает на пустых пачках.
- [ ] `--save` пишет валидный JSON в указанный путь.
- [ ] `--with-media` скачивает вложения в `storage/app/telegram/media/{chat_id}/`.
- [ ] Ошибки RPC выводятся читаемо, команда не падает молча.
- [ ] `storage/app/telegram/` в `.gitignore`.
