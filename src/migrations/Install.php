<?php

declare(strict_types=1);

namespace justinholtweb\library\migrations;

use craft\db\Migration;
use craft\db\Table;
use justinholtweb\library\records\CollectionAssetRecord;
use justinholtweb\library\records\CollectionRecord;
use justinholtweb\library\records\HashRecord;
use justinholtweb\library\records\UploadRuleRecord;
use justinholtweb\library\records\UsageRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Child tables first — every one of them hangs off either `library_collections` or
        // `elements`, and dropping a parent out from under a live foreign key fails on MySQL.
        $this->dropTableIfExists(HashRecord::TABLE);
        $this->dropTableIfExists(UsageRecord::TABLE);
        $this->dropTableIfExists(CollectionAssetRecord::TABLE);
        $this->dropTableIfExists(UploadRuleRecord::TABLE);
        $this->dropTableIfExists(CollectionRecord::TABLE);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(CollectionRecord::TABLE, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),

            // Collections nest one level deep in the UI but arbitrarily deep in the data, so a
            // future settings change never has to migrate rows.
            'parentId' => $this->integer(),

            'description' => $this->text(),

            // A CSS colour token from Craft's own palette, used for the chip in the asset index.
            'color' => $this->string(32),

            'sortOrder' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(CollectionAssetRecord::TABLE, [
            'id' => $this->primaryKey(),
            'collectionId' => $this->integer()->notNull(),
            'assetId' => $this->integer()->notNull(),
            'sortOrder' => $this->integer()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(UploadRuleRecord::TABLE, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->unsigned()->notNull()->defaultValue(0),

            // All four match columns are optional; a rule with none of them set matches every
            // upload, which is how "tag everything as Unsorted" is expressed.
            'volumeId' => $this->integer(),
            'folderPattern' => $this->string(),
            'kinds' => $this->text(),
            'filenamePattern' => $this->string(),

            'collectionIds' => $this->text(),
            'altSource' => $this->string(16),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(UsageRecord::TABLE, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'elementType' => $this->string()->notNull(),

            // Informational only. It is deliberately outside the unique key: one row per
            // (asset, element, source) keeps "used in N places" counting places, not fields.
            'siteId' => $this->integer(),
            'fieldHandle' => $this->string(),

            'source' => $this->string(16)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(HashRecord::TABLE, [
            'assetId' => $this->integer()->notNull(),

            // Kept alongside the hash so a changed file is detected without re-reading it, and so
            // the duplicate finder can shortlist by size before hashing anything.
            'size' => $this->bigInteger(),

            'hash' => $this->string(40),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[assetId]])',
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, CollectionRecord::TABLE, ['handle'], true);
        $this->createIndex(null, CollectionRecord::TABLE, ['parentId'], false);

        $this->createIndex(null, CollectionAssetRecord::TABLE, ['collectionId', 'assetId'], true);
        $this->createIndex(null, CollectionAssetRecord::TABLE, ['assetId'], false);

        $this->createIndex(null, UploadRuleRecord::TABLE, ['sortOrder'], false);
        $this->createIndex(null, UploadRuleRecord::TABLE, ['volumeId'], false);

        $this->createIndex(null, UsageRecord::TABLE, ['assetId', 'elementId', 'source'], true);
        $this->createIndex(null, UsageRecord::TABLE, ['elementId'], false);
        $this->createIndex(null, UsageRecord::TABLE, ['assetId'], false);

        $this->createIndex(null, HashRecord::TABLE, ['hash'], false);
        $this->createIndex(null, HashRecord::TABLE, ['size'], false);
    }

    private function addForeignKeys(): void
    {
        // Self-reference: deleting a parent collection promotes its children to the top level
        // rather than taking them with it.
        $this->addForeignKey(null, CollectionRecord::TABLE, ['parentId'], CollectionRecord::TABLE, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, CollectionAssetRecord::TABLE, ['collectionId'], CollectionRecord::TABLE, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, CollectionAssetRecord::TABLE, ['assetId'], Table::ELEMENTS, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, UploadRuleRecord::TABLE, ['volumeId'], Table::VOLUMES, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, UsageRecord::TABLE, ['assetId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, UsageRecord::TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, UsageRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, HashRecord::TABLE, ['assetId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
    }
}
