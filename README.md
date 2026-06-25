# Count Reactions

Telegram userbot на Laravel + [MadelineProto](https://docs.madelineproto.xyz/), который считает реакции на сообщения в чатах и отправляет топ-5 самых популярных авторов.

## Требования

- PHP 8.3+
- Laravel 13
- Расширения: `mbstring`, `json`, `xml`, `dom`, `gmp` или `bcmath`

## Установка

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Заполнить в `.env`:

```env
TG_API_ID=       # с https://my.telegram.org
TG_API_HASH=     # с https://my.telegram.org
TG_CHAT_ID=      # id чата для подсчёта реакций (формат -100...)
```

При первом запуске любой `telegram:*` команды MadelineProto запросит номер телефона, код и 2FA — сессия сохранится в `storage/app/telegram/userbot.madeline`.

## Команды

### `telegram:chats`

Список всех чатов аккаунта.

```bash
php artisan telegram:chats
php artisan telegram:chats --type=supergroup
php artisan telegram:chats --json
```

| Опция | Описание |
|-------|----------|
| `--type=all` | Фильтр: `all`, `user`, `bot`, `chat`, `supergroup`, `channel` |
| `--json` | Вывод в формате JSON |

### `telegram:history`

Выгрузка истории сообщений с подсчётом реакций. Отправляет в чат топ-5 авторов по количеству реакций.

```bash
# За последний день (чат из TG_CHAT_ID)
php artisan telegram:history --since="1 day ago"

# Конкретный чат по id
php artisan telegram:history --since="1 day ago" -- -1001234567890

# Последние 50 сообщений
php artisan telegram:history --limit=50 -- -1001234567890

# Вся история с сохранением в файл
php artisan telegram:history --all --save=dump.json -- -1001234567890

# С загрузкой медиа
php artisan telegram:history --with-media -- -1001234567890
```

| Аргумент / Опция | Описание |
|------------------|----------|
| `chat` | ID чата, @username или t.me-ссылка (необязательно, по умолчанию `TG_CHAT_ID`) |
| `--limit=100` | Количество сообщений |
| `--all` | Выгрузить всю историю |
| `--since` | Сообщения не старше даты (`"1 day ago"`, `"2025-05-23"`, `"3 hours ago"`) |
| `--save=path.json` | Сохранить результат в JSON-файл |
| `--with-media` | Скачать вложения в `storage/app/telegram/media/{chat_id}/` |

## Расписание

Команда подсчёта реакций за день запускается автоматически каждый день в 00:30. Для этого нужен cron:

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

## Структура

```
app/
├── Console/Commands/
│   ├── TelegramChatsCommand.php    — список чатов
│   └── TelegramHistoryCommand.php  — история + реакции + топ
└── Services/Telegram/
    └── MadelineFactory.php         — инициализация MadelineProto
routes/
└── console.php                     — расписание
storage/app/telegram/               — сессия и медиа (в .gitignore)
```
edit