<?php

declare(strict_types=1);

namespace rapttor\yii2metadata;

use yii\db\Connection;

/** The metadata table. Used by the migration and by tests; safe to call on an existing install. */
final class Schema
{
    public static function create(Connection $db, string $table = 'rapttor_metadata'): void
    {
        if ($db->getTableSchema($table, true) !== null) {
            return;
        }
        $long = $db->driverName === 'mysql' ? 'longtext' : 'text';
        $db->createCommand()->createTable($table, [
            'id' => 'pk',
            'key' => 'string(64) NOT NULL',
            'remote_table' => 'string(64) NOT NULL',
            'remote_id' => 'string(64) NOT NULL',
            'content' => $long . ' NULL',
            'history' => $long . ' NULL',
            'rev' => 'integer NOT NULL DEFAULT 1',
            'updated_at' => 'integer NOT NULL',
        ])->execute();
        $db->createCommand()->createIndex('idx_' . preg_replace('/\W+/', '_', trim($table, '{}%')) . '_ref', $table, ['key', 'remote_table', 'remote_id'], true)->execute();
        $db->createCommand()->createIndex('idx_' . preg_replace('/\W+/', '_', trim($table, '{}%')) . '_owner', $table, ['remote_table', 'remote_id'])->execute();
    }
}
