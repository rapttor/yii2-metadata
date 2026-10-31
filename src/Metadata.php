<?php

declare(strict_types=1);

namespace rapttor\yii2metadata;

use Yii;
use yii\base\Component;
use yii\db\IntegrityException;
use yii\db\Query;

/**
 * Per-record metadata as JSON in one shared table. One row is addressed by three things:
 *
 *  - `key`: what the data is for (`legal`, `theme`, ...), chosen by the feature that owns it
 *  - `remote_table`: the kind of record it belongs to (`profile`, `user`, `project`, ...)
 *  - `remote_id`: the record's id (a string, so any id type works)
 *
 * `content` is the current state: a JSON object. `history` keeps the earlier value of every top-level entry of `content` that
 * changed or was removed, newest last. Every save checks and raises `rev`, so two requests changing the same row cannot
 * silently overwrite each other (the loser re-reads and re-applies its change).
 *
 * Register as the application component `metadata`:
 *
 *     'components' => ['metadata' => ['class' => rapttor\yii2metadata\Metadata::class]],
 */
class Metadata extends Component
{
    /** Name of the application's database component. */
    public string $db = 'db';
    public string $table = 'rapttor_metadata';
    /** Most history entries kept per row (oldest dropped first). */
    public int $historyMax = 200;
    /** Keys for which no history is kept (for example `theme`, where the past values mean nothing). */
    public array $noHistory = [];
    /** How often a save is retried when another request changed the row meanwhile. */
    public int $retries = 5;

    /** The whole current content of a row, or [] when it does not exist. @return array<string,mixed> */
    public function get(string $key, string $remoteTable, int|string $remoteId): array
    {
        $row = $this->row($key, $remoteTable, (string)$remoteId);
        return $row === null ? [] : $this->decode($row['content']);
    }

    /** One top-level entry of the content, or $default. */
    public function value(string $key, string $remoteTable, int|string $remoteId, string $entry, mixed $default = null): mixed
    {
        return $this->get($key, $remoteTable, $remoteId)[$entry] ?? $default;
    }

    /** Replace the whole content. @return array<string,mixed> the content as saved */
    public function set(string $key, string $remoteTable, int|string $remoteId, array $content): array
    {
        return $this->modify($key, $remoteTable, $remoteId, static fn(array $old): array => $content);
    }

    /**
     * Change some top-level entries and leave the others. A `null` value removes the entry.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed> the content as saved
     */
    public function patch(string $key, string $remoteTable, int|string $remoteId, array $changes): array
    {
        return $this->modify($key, $remoteTable, $remoteId, static function (array $old) use ($changes): array {
            foreach ($changes as $entry => $value) {
                if ($value === null) {
                    unset($old[$entry]);
                } else {
                    $old[$entry] = $value;
                }
            }
            return $old;
        });
    }

    /**
     * Read, change and save a row atomically. $change gets the current content and returns the new content; it may run again
     * if another request saved first, so it must not have side effects.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $change
     * @return array<string,mixed> the content as saved
     */
    public function modify(string $key, string $remoteTable, int|string $remoteId, callable $change): array
    {
        $remoteId = (string)$remoteId;
        $db = $this->connection();
        for ($try = 0; $try < $this->retries; $try++) {
            $row = $this->row($key, $remoteTable, $remoteId);
            $old = $row === null ? [] : $this->decode($row['content']);
            $new = $change($old);
            $now = time();
            if ($row === null) {
                try {
                    $db->createCommand()->insert($this->table, [
                        'key' => $key, 'remote_table' => $remoteTable, 'remote_id' => $remoteId,
                        'content' => $this->encode($new), 'history' => '[]', 'rev' => 1, 'updated_at' => $now,
                    ])->execute();
                    return $new;
                } catch (IntegrityException) { // someone created it first: apply the change to their row
                    continue;
                }
            }
            if ($new == $old) {
                return $old;
            }
            $history = $this->decode($row['history']);
            if (!in_array($key, $this->noHistory, true)) {
                foreach ($old as $entry => $value) {
                    if (!array_key_exists($entry, $new) || $new[$entry] != $value) {
                        $history[] = ['entry' => (string)$entry, 'rev' => (int)$row['rev'], 'replaced_at' => $now, 'value' => $value];
                    }
                }
                $history = array_slice($history, -$this->historyMax);
            }
            $n = $db->createCommand()->update($this->table, [
                'content' => $this->encode($new), 'history' => json_encode($history, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'rev' => (int)$row['rev'] + 1, 'updated_at' => $now,
            ], ['id' => $row['id'], 'rev' => $row['rev']])->execute();
            if ($n === 1) {
                return $new;
            }
        }
        throw new \RuntimeException("Could not save metadata $key/$remoteTable/$remoteId: the row keeps changing.");
    }

    /**
     * Earlier values, oldest first: ['entry' => name, 'rev' => revision it was replaced in, 'replaced_at' => unix time, 'value' => old value].
     *
     * @param string|null $entry only this top-level entry
     * @return list<array<string,mixed>>
     */
    public function history(string $key, string $remoteTable, int|string $remoteId, ?string $entry = null): array
    {
        $row = $this->row($key, $remoteTable, (string)$remoteId);
        $h = $row === null ? [] : $this->decode($row['history']);
        return $entry === null ? array_values($h) : array_values(array_filter($h, static fn(array $e): bool => ($e['entry'] ?? null) === $entry));
    }

    /** Revision counter and last change time of a row, or null when it does not exist. @return array{rev:int,updated_at:int}|null */
    public function info(string $key, string $remoteTable, int|string $remoteId): ?array
    {
        $row = $this->row($key, $remoteTable, (string)$remoteId);
        return $row === null ? null : ['rev' => (int)$row['rev'], 'updated_at' => (int)$row['updated_at']];
    }

    /** Delete one row (content and history). */
    public function delete(string $key, string $remoteTable, int|string $remoteId): bool
    {
        return $this->connection()->createCommand()->delete($this->table, ['key' => $key, 'remote_table' => $remoteTable, 'remote_id' => (string)$remoteId])->execute() > 0;
    }

    /** Delete every row of a record, whatever feature owns it (call when the account is deleted). @return int rows deleted */
    public function deleteAll(string $remoteTable, int|string $remoteId): int
    {
        return $this->connection()->createCommand()->delete($this->table, ['remote_table' => $remoteTable, 'remote_id' => (string)$remoteId])->execute();
    }

    /**
     * Everything stored for a record, for a data export. Keys listed in $only (or all).
     *
     * @param list<string>|null $only
     * @return array<string,array{content:array<string,mixed>,history:list<array<string,mixed>>,rev:int,updated_at:int}> key => row
     */
    public function export(string $remoteTable, int|string $remoteId, ?array $only = null): array
    {
        $q = (new Query())->from($this->table)->where(['remote_table' => $remoteTable, 'remote_id' => (string)$remoteId])->orderBy('key');
        if ($only !== null) {
            $q->andWhere(['key' => $only]);
        }
        $out = [];
        foreach ($q->all($this->connection()) as $r) {
            $out[$r['key']] = ['content' => $this->decode($r['content']), 'history' => array_values($this->decode($r['history'])), 'rev' => (int)$r['rev'], 'updated_at' => (int)$r['updated_at']];
        }
        return $out;
    }

    private function connection(): \yii\db\Connection
    {
        return Yii::$app->get($this->db);
    }

    /** @return array<string,mixed>|null */
    private function row(string $key, string $remoteTable, string $remoteId): ?array
    {
        $r = (new Query())->from($this->table)->where(['key' => $key, 'remote_table' => $remoteTable, 'remote_id' => $remoteId])->one($this->connection());
        return $r === false ? null : $r;
    }

    /** @return array<mixed> */
    private function decode(?string $json): array
    {
        $v = $json === null || $json === '' ? [] : json_decode($json, true);
        return is_array($v) ? $v : [];
    }

    private function encode(array $v): string
    {
        return json_encode($v === [] ? new \stdClass() : $v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
