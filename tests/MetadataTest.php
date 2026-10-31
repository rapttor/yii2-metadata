<?php

declare(strict_types=1);

namespace rapttor\yii2metadata\tests;

use PHPUnit\Framework\TestCase;
use rapttor\yii2metadata\Metadata;
use rapttor\yii2metadata\Schema;
use Yii;

final class MetadataTest extends TestCase
{
    private function meta(array $props = []): Metadata
    {
        $m = new Metadata($props);
        Yii::$app->set('metadata', $m);
        Schema::create(Yii::$app->db, $m->table);
        Yii::$app->db->createCommand()->delete($m->table)->execute();
        return $m;
    }

    public function testGetSetPatch(): void
    {
        $m = $this->meta();
        $this->assertSame([], $m->get('theme', 'profile', 5));
        $m->set('theme', 'profile', 5, ['mode' => 'dark', 'size' => 14]);
        $this->assertSame('dark', $m->value('theme', 'profile', '5', 'mode'), 'integer and string ids are the same record');
        $m->patch('theme', 'profile', 5, ['mode' => 'light', 'size' => null, 'font' => 'serif']);
        $this->assertSame(['mode' => 'light', 'font' => 'serif'], $m->get('theme', 'profile', 5));
        $this->assertSame('x', $m->value('theme', 'profile', 5, 'missing', 'x'));
    }

    public function testRowsAreSeparateByKeyTableAndId(): void
    {
        $m = $this->meta();
        $m->set('legal', 'profile', 1, ['a' => 1]);
        $m->set('theme', 'profile', 1, ['b' => 2]);
        $m->set('legal', 'profile', 2, ['c' => 3]);
        $m->set('legal', 'project', 1, ['d' => 4]);
        $this->assertSame(['a' => 1], $m->get('legal', 'profile', 1));
        $this->assertSame(['d' => 4], $m->get('legal', 'project', 1));
        $this->assertCount(2, $m->export('profile', 1));
    }

    public function testHistoryKeepsReplacedAndRemovedValues(): void
    {
        $m = $this->meta();
        $m->set('legal', 'profile', 1, ['terms' => ['v' => '1.0'], 'age' => ['v' => '18']]);
        $this->assertSame([], $m->history('legal', 'profile', 1), 'first save has nothing to replace');
        $m->patch('legal', 'profile', 1, ['terms' => ['v' => '1.1']]);
        $m->patch('legal', 'profile', 1, ['age' => null]);
        $m->patch('legal', 'profile', 1, ['terms' => ['v' => '1.1']]); // unchanged: no new revision
        $h = $m->history('legal', 'profile', 1);
        $this->assertCount(2, $h);
        $this->assertSame(['terms', ['v' => '1.0']], [$h[0]['entry'], $h[0]['value']]);
        $this->assertSame('age', $h[1]['entry']);
        $this->assertCount(1, $m->history('legal', 'profile', 1, 'terms'));
        $this->assertSame(3, $m->info('legal', 'profile', 1)['rev']);
    }

    public function testHistoryIsCappedAndCanBeSwitchedOff(): void
    {
        $m = $this->meta(['historyMax' => 3, 'noHistory' => ['theme']]);
        for ($i = 0; $i < 8; $i++) {
            $m->patch('legal', 'profile', 1, ['n' => $i]);
            $m->patch('theme', 'profile', 1, ['n' => $i]);
        }
        $h = $m->history('legal', 'profile', 1);
        $this->assertCount(3, $h);
        $this->assertSame(6, $h[2]['value'], 'newest kept');
        $this->assertSame([], $m->history('theme', 'profile', 1));
    }

    public function testModifyRetriesWhenTheRowChangedMeanwhile(): void
    {
        $m = $this->meta();
        $m->set('legal', 'profile', 1, ['n' => 0]);
        $calls = 0;
        $out = $m->modify('legal', 'profile', 1, function (array $old) use (&$calls, $m): array {
            if (++$calls === 1) { // another request saves between our read and write
                $m->patch('legal', 'profile', 1, ['other' => true]);
            }
            return $old + ['mine' => true];
        });
        $this->assertSame(2, $calls);
        $this->assertSame(['n' => 0, 'other' => true, 'mine' => true], $out, 'neither change is lost');
    }

    public function testGivesUpWhenTheRowKeepsChanging(): void
    {
        $m = $this->meta(['retries' => 2]);
        $m->set('legal', 'profile', 1, ['n' => 0]);
        $this->expectException(\RuntimeException::class);
        $m->modify('legal', 'profile', 1, function (array $old) use ($m): array {
            $m->patch('legal', 'profile', 1, ['x' => microtime()]);
            return $old + ['mine' => true];
        });
    }

    public function testExportAndDelete(): void
    {
        $m = $this->meta();
        $m->set('legal', 'profile', 1, ['a' => 1]);
        $m->patch('legal', 'profile', 1, ['a' => 2]);
        $m->set('theme', 'profile', 1, ['mode' => 'dark']);
        $m->set('legal', 'profile', 2, ['a' => 9]);
        $e = $m->export('profile', 1);
        $this->assertSame(['legal', 'theme'], array_keys($e));
        $this->assertSame(1, $e['legal']['history'][0]['value']);
        $this->assertSame(['theme'], array_keys($m->export('profile', 1, ['theme'])));
        $this->assertTrue($m->delete('theme', 'profile', 1));
        $this->assertFalse($m->delete('theme', 'profile', 1));
        $this->assertSame(1, $m->deleteAll('profile', 1));
        $this->assertSame([], $m->export('profile', 1));
        $this->assertSame(['a' => 9], $m->get('legal', 'profile', 2), 'other records untouched');
    }

    public function testUnicodeAndNestedContentSurvive(): void
    {
        $m = $this->meta();
        $m->set('prefs', 'user', 'a/b', ['ime' => 'Đorđe Šćepanović', 'n' => ['x' => [1, 2, ['y' => true]]]]);
        $this->assertSame('Đorđe Šćepanović', $m->value('prefs', 'user', 'a/b', 'ime'));
        $this->assertSame(true, $m->get('prefs', 'user', 'a/b')['n']['x'][2]['y']);
    }
}
