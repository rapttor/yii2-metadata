# rapttor/yii2-metadata

Per-record metadata as JSON in **one shared table**, for Yii2. One row is addressed by `key` (what the data is for: `legal`, `theme`, ...), `remote_table` (what kind of record: `profile`, `user`, `project`) and `remote_id`. The row keeps the current `content`, the `history` of values that were replaced, and a revision counter so two requests cannot silently overwrite each other.

Typical uses: consents and their proof (used by `rapttor/yii2-legal`), theme and notification settings per user, onboarding state, feature opt-ins.

## Install

```bash
composer require rapttor/yii2-metadata
```

```php
// config/web.php and config/console.php
'components' => [
    'metadata' => [
        'class' => rapttor\yii2metadata\Metadata::class,
        // 'table' => 'rapttor_metadata',   // shared by all Rapttor apps on this database
        // 'historyMax' => 200,             // history entries kept per row
        // 'noHistory' => ['theme'],        // keys where past values mean nothing
    ],
],
// console only:
'controllerMap' => ['migrate' => ['class' => yii\console\controllers\MigrateController::class,
    'migrationNamespaces' => ['rapttor\yii2metadata\migrations']]],
```

```bash
php yii migrate
```

## Use

```php
$m = Yii::$app->metadata;

$m->set('theme', 'profile', $userId, ['mode' => 'dark', 'size' => 14]);   // replace the content
$m->patch('theme', 'profile', $userId, ['mode' => 'light', 'size' => null]); // change some entries; null removes one
$m->get('theme', 'profile', $userId);                 // ['mode' => 'light']
$m->value('theme', 'profile', $userId, 'mode', 'dark'); // one entry, with a default

// read-modify-write that is safe against a concurrent save (the callback may run again, keep it free of side effects)
$m->modify('legal', 'profile', $userId, fn(array $c): array => $c + ['terms' => ['v' => '1.2']]);

$m->history('legal', 'profile', $userId);             // oldest first: entry, rev, replaced_at, value
$m->history('legal', 'profile', $userId, 'terms');    // only one entry's earlier values
$m->info('legal', 'profile', $userId);                // ['rev' => 3, 'updated_at' => ...]
```

| Method | Does |
|---|---|
| `get`, `value` | current content (whole, or one entry) |
| `set`, `patch`, `modify` | write; each real change raises `rev` and moves the old values into `history` |
| `history`, `info` | earlier values; revision and time |
| `export($remoteTable, $id, ?$onlyKeys)` | everything stored for a record, for a data export (access request) |
| `delete`, `deleteAll($remoteTable, $id)` | remove one row / every row of a record (call `deleteAll` when the account is deleted) |

## Rules it follows

- **Content is a JSON object.** Top-level entries are the unit of change and of history. Put values that change together under one entry. Store no passwords or secrets.
- **History** records the old value of every top-level entry that changed or was removed, with the revision and time. It is capped by `historyMax` (oldest dropped). A save that changes nothing writes nothing.
- **Concurrency**: every save is `UPDATE ... WHERE rev = :rev`; if another request got there first the change is re-applied to the fresh row (up to `retries` times, then a `RuntimeException`).
- **Ids are strings**, so integer, UUID and composite ids all work (`1` and `'1'` are the same record).
- Works on MySQL/MariaDB (`longtext`), PostgreSQL and SQLite (`text`). `key` is a reserved word in MySQL; Yii quotes it, so quote it yourself in raw SQL.
- No network access, no dependencies besides Yii2.

## Tests

```bash
composer install && vendor/bin/phpunit
```

Licence: MIT. Not legal advice: whether and how long you may keep personal data in these rows is for your counsel.
