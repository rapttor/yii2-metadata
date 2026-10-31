<?php

declare(strict_types=1);

namespace rapttor\yii2metadata\migrations;

use rapttor\yii2metadata\Schema;
use Yii;
use yii\db\Migration;

/** Creates the shared metadata table (name from the `metadata` component). Run: php yii migrate --migrationNamespaces=rapttor\\yii2metadata\\migrations */
final class m261101_000001_create_metadata extends Migration
{
    public function safeUp(): void
    {
        Schema::create($this->db, Yii::$app->get('metadata')->table);
    }

    public function safeDown(): void
    {
        $this->dropTable(Yii::$app->get('metadata')->table);
    }
}
