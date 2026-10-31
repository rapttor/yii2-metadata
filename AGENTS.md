# Instructions for AI coding agents: rapttor/yii2-metadata

A small Yii2 component (`Yii::$app->metadata`) that keeps JSON per record in one shared table: `key` + `remote_table` + `remote_id` -> `content`, `history`, `rev`. Read README.md for the API.

## When to use it in a host app

- Per-user (or per-project) settings and state that do not deserve their own table: theme, notification choices, onboarding flags, consents (`rapttor/yii2-legal` uses `key=legal`, `remote_table=profile`).
- Do **not** use it for data you must query or join on (use real columns), for secrets, or for large blobs.

## Integrating

1. `composer require rapttor/yii2-metadata`; register the `metadata` component in web **and** console config; add `rapttor\yii2metadata\migrations` to `migrationNamespaces`; run `php yii migrate`. Other Rapttor apps on the same database share the table: do not create a second one.
2. Pick one `key` per feature (`theme`, `legal`, ...) and never reuse another feature's key. `remote_table` is the kind of record (`profile`), `remote_id` its id.
3. Read with `get`/`value`, write with `patch` (a few entries) or `modify` (read-modify-write). Never read, change and `set` the whole content yourself: you will overwrite a concurrent change.
4. **Account deletion**: call `Yii::$app->metadata->deleteAll('profile', $id)` in the code that deletes the account, unless a legal duty makes you keep a row (then keep only what the duty needs). **Data export / access requests**: include `Yii::$app->metadata->export('profile', $id)`.
5. For keys where past values are useless (UI preferences) list them in `noHistory` so history does not grow.
6. Keys with legal weight (`legal`) must keep their history: do not list them in `noHistory`, and do not lower `historyMax` below what the record-keeping rules for that data require.

## Rules

- Content is a JSON object; top-level entries are the unit of history. Do not put secrets or passwords in it.
- Do not edit rows with raw SQL (it skips `rev` and history). Do not delete history to "clean up" consent records.
- Always go through the component so `rev` is checked.

## Changing this package

- PHP >= 8.2, `declare(strict_types=1)`, PSR-4 `rapttor\yii2metadata\`, no dependencies beyond Yii2. Every behaviour change needs a test in `tests/MetadataTest.php`. Run:

```bash
composer install && vendor/bin/phpunit
```
- SemVer tags `vX.Y.Z`; update CHANGELOG.md; Packagist updates from the tag. The table layout is a contract shared with other packages (`rapttor/yii2-legal`): a layout change is a MAJOR release with a migration.
