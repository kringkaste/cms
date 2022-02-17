<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\base\BlockElementInterface;
use craft\config\GeneralConfig;
use craft\db\Connection;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\MatrixBlock;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\records\Volume;
use craft\records\VolumeFolder;
use DateTime;
use yii\base\Component;
use yii\di\Instance;

/**
 * Garbage Collection service.
 *
 * An instance of the service is available via [[\craft\base\ApplicationTrait::getGc()|`Craft::$app->gc`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.1.0
 */
class Gc extends Component
{
    /**
     * @event Event The event that is triggered when running garbage collection.
     */
    public const EVENT_RUN = 'run';

    /**
     * @var int the probability (parts per million) that garbage collection (GC) should be performed
     * on a request. Defaults to 10, meaning 0.001% chance.
     *
     * This number should be between 0 and 1000000. A value 0 means no GC will be performed at all unless forced.
     */
    public int $probability = 10;

    /**
     * @var bool whether [[hardDelete()]] should delete *all* soft-deleted rows,
     * rather than just the ones that were deleted long enough ago to be ready
     * for hard-deletion per the <config3:softDeleteDuration> config setting.
     */
    public bool $deleteAllTrashed = false;

    /**
     * @var Connection|array|string The database connection to use
     * @since 4.0.0
     */
    public $db = 'db';

    /**
     * @var GeneralConfig
     */
    private GeneralConfig $_generalConfig;

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->db = Instance::ensure($this->db, Connection::class);
        $this->_generalConfig = Craft::$app->getConfig()->getGeneral();
        parent::init();
    }

    /**
     * Possibly runs garbage collection.
     *
     * @param bool $force Whether garbage collection should be forced. If left as `false`, then
     * garbage collection will only run if a random condition passes, factoring in [[probability]].
     */
    public function run(bool $force = false): void
    {
        if (!$force && mt_rand(0, 1000000) >= $this->probability) {
            return;
        }

        Craft::$app->getDrafts()->purgeUnsavedDrafts();
        Craft::$app->getUsers()->purgeExpiredPendingUsers();
        $this->_deleteStaleSessions();
        $this->_deleteStaleAnnouncements();

        // elements should always go first
        $this->hardDeleteElements();

        $this->hardDelete([
            Table::CATEGORYGROUPS,
            Table::ENTRYTYPES,
            Table::FIELDGROUPS,
            Table::SECTIONS,
            Table::TAGGROUPS,
        ]);

        $this->deletePartialElements(Asset::class, Table::ASSETS, 'id');
        $this->deletePartialElements(Category::class, Table::CATEGORIES, 'id');
        $this->deletePartialElements(Entry::class, Table::ENTRIES, 'id');
        $this->deletePartialElements(GlobalSet::class, Table::GLOBALSETS, 'id');
        $this->deletePartialElements(MatrixBlock::class, Table::MATRIXBLOCKS, 'id');
        $this->deletePartialElements(Tag::class, Table::TAGS, 'id');
        $this->deletePartialElements(User::class, Table::USERS, 'id');

        $this->deletePartialElements(Asset::class, Table::CONTENT, 'elementId');
        $this->deletePartialElements(Category::class, Table::CONTENT, 'elementId');
        $this->deletePartialElements(Entry::class, Table::CONTENT, 'elementId');
        $this->deletePartialElements(GlobalSet::class, Table::CONTENT, 'elementId');
        $this->deletePartialElements(Tag::class, Table::CONTENT, 'elementId');
        $this->deletePartialElements(User::class, Table::CONTENT, 'elementId');

        $this->_deleteOrphanedDraftsAndRevisions();
        Craft::$app->getSearch()->deleteOrphanedIndexes();

        // Fire a 'run' event
        if ($this->hasEventHandlers(self::EVENT_RUN)) {
            $this->trigger(self::EVENT_RUN);
        }

        $this->hardDelete([
            Table::STRUCTURES,
            Table::FIELDLAYOUTS,
            Table::SITES,
        ]);

        $this->hardDeleteVolumes();
    }

    /**
     * Hard delete eligible volumes, deleting the folders one by one to avoid nested dependency errors.
     */
    public function hardDeleteVolumes(): void
    {
        if (!$this->_shouldHardDelete()) {
            return;
        }

        $condition = $this->_hardDeleteCondition();

        $volumes = (new Query())->select(['id'])->from([Table::VOLUMES])->where($condition)->all();
        $volumeIds = [];

        foreach ($volumes as $volume) {
            $volumeIds[] = $volume['id'];
        }

        $folders = (new Query())->select(['id', 'path'])->from([Table::VOLUMEFOLDERS])->where(['volumeId' => $volumeIds])->all();
        usort($folders, function($a, $b) {
            return substr_count($a['path'], '/') < substr_count($b['path'], '/');
        });

        foreach ($folders as $folder) {
            VolumeFolder::deleteAll(['id' => $folder['id']]);
        }

        Volume::deleteAll(['id' => $volumeIds]);
    }

    /**
     * Hard-deletes eligible elements.
     *
     * Any soft-deleted block elements which have revisions will be skipped, as their revisions may still be needed by the owner element.
     *
     * @since 4.0.0
     */
    public function hardDeleteElements(): void
    {
        if (!$this->_shouldHardDelete()) {
            return;
        }

        $normalElementTypes = [];
        $blockElementTypes = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
            if (is_subclass_of($elementType, BlockElementInterface::class)) {
                $blockElementTypes[] = $elementType;
            } else {
                $normalElementTypes[] = $elementType;
            }
        }

        if ($normalElementTypes) {
            Db::delete(Table::ELEMENTS, [
                'and',
                $this->_hardDeleteCondition(),
                ['type' => $normalElementTypes],
            ]);
        }

        if ($blockElementTypes) {
            // Only hard-delete block elements that don't have any revisions
            $elementsTable = Table::ELEMENTS;
            $revisionsTable = Table::REVISIONS;
            $params = [];
            $conditionSql = $this->db->getQueryBuilder()->buildCondition([
                'and',
                $this->_hardDeleteCondition('e'),
                [
                    'e.type' => $blockElementTypes,
                    'r.id' => null,
                ]
            ], $params);

            if ($this->db->getIsMysql()) {
                $sql = <<<SQL
DELETE [[e]].* FROM $elementsTable [[e]]
LEFT JOIN $revisionsTable [[r]] ON [[r.canonicalId]] = [[e.id]]
WHERE $conditionSql
SQL;
            } else {
                $sql = <<<SQL
DELETE FROM $elementsTable
USING $elementsTable [[e]]
LEFT JOIN $revisionsTable [[r]] ON [[r.canonicalId]] = [[e.id]]
WHERE
  $elementsTable.[[id]] = [[e.id]] AND $conditionSql
SQL;
            }

            $this->db->createCommand($sql, $params)->execute();
        }
    }

    /**
     * Hard-deletes any rows in the given table(s), that are due for it.
     *
     * @param string|string[] $tables The table(s) to delete rows from. They must have a `dateDeleted` column.
     */
    public function hardDelete($tables): void
    {
        if (!$this->_shouldHardDelete()) {
            return;
        }

        $condition = $this->_hardDeleteCondition();

        if (!is_array($tables)) {
            $tables = [$tables];
        }

        foreach ($tables as $table) {
            Db::delete($table, $condition);
        }
    }

    /**
     * Deletes elements that are missing data in the given element extension table.
     *
     * @param string $elementType The element type
     * @param string $table The extension table name
     * @param string $fk The column name that contains the foreign key to `elements.id`
     * @since 3.6.6
     */
    public function deletePartialElements(string $elementType, string $table, string $fk): void
    {
        $elementsTable = Table::ELEMENTS;

        if ($this->db->getIsMysql()) {
            $sql = <<<SQL
DELETE [[e]].* FROM $elementsTable [[e]]
LEFT JOIN $table [[t]] ON [[t.$fk]] = [[e.id]]
WHERE
  [[e.type]] = :type AND
  [[t.$fk]] IS NULL
SQL;
        } else {
            $sql = <<<SQL
DELETE FROM $elementsTable
USING $elementsTable [[e]]
LEFT JOIN $table [[t]] ON [[t.$fk]] = [[e.id]]
WHERE
  $elementsTable.[[id]] = [[e.id]] AND
  [[e.type]] = :type AND
  [[t.$fk]] IS NULL
SQL;
        }

        $this->db->createCommand($sql, ['type' => $elementType])->execute();
    }

    /**
     * Returns whether we should be hard-deleting soft-deleted objects.
     *
     * @return bool
     */
    private function _shouldHardDelete(): bool
    {
        return $this->_generalConfig->softDeleteDuration || $this->deleteAllTrashed;
    }

    /**
     * Deletes any session rows that have gone stale.
     */
    private function _deleteStaleSessions(): void
    {
        if ($this->_generalConfig->purgeStaleUserSessionDuration === 0) {
            return;
        }

        $interval = DateTimeHelper::secondsToInterval($this->_generalConfig->purgeStaleUserSessionDuration);
        $expire = DateTimeHelper::currentUTCDateTime();
        $pastTime = $expire->sub($interval);

        Db::delete(Table::SESSIONS, ['<', 'dateUpdated', Db::prepareDateForDb($pastTime)]);
    }

    /**
     * Deletes any feature announcement rows that have gone stale.
     *
     */
    private function _deleteStaleAnnouncements(): void
    {
        Db::delete(Table::ANNOUNCEMENTS, ['<', 'dateRead', Db::prepareDateForDb(new DateTime('7 days ago'))]);
    }


    /**
     * Deletes any orphaned rows in the `drafts` and `revisions` tables.
     *
     */
    private function _deleteOrphanedDraftsAndRevisions(): void
    {
        $elementsTable = Table::ELEMENTS;

        foreach (['draftId' => Table::DRAFTS, 'revisionId' => Table::REVISIONS] as $fk => $table) {
            if ($this->db->getIsMysql()) {
                $sql = <<<SQL
DELETE [[t]].* FROM $table [[t]]
LEFT JOIN $elementsTable [[e]] ON [[e.$fk]] = [[t.id]]
WHERE [[e.id]] IS NULL
SQL;
            } else {
                $sql = <<<SQL
DELETE FROM $table
USING $table [[t]]
LEFT JOIN $elementsTable [[e]] ON [[e.$fk]] = [[t.id]]
WHERE
  $table.[[id]] = [[t.id]] AND
  [[e.id]] IS NULL
SQL;
            }

            $this->db->createCommand($sql)->execute();
        }
    }

    /**
     * @return array
     */
    private function _hardDeleteCondition(?string $tableAlias = null): array
    {
        $tableAlias = $tableAlias ? "$tableAlias." : '';
        $condition = ['not', ["{$tableAlias}dateDeleted" => null]];

        if (!$this->deleteAllTrashed) {
            $expire = DateTimeHelper::currentUTCDateTime();
            $interval = DateTimeHelper::secondsToInterval($this->_generalConfig->softDeleteDuration);
            $pastTime = $expire->sub($interval);
            $condition = [
                'and',
                $condition,
                ['<', "{$tableAlias}dateDeleted", Db::prepareDateForDb($pastTime)],
            ];
        }

        return $condition;
    }
}
