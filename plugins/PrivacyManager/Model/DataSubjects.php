<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\PrivacyManager\Model;

use Piwik\Columns\Dimension;
use Piwik\Columns\Join\ActionNameJoin;
use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Metrics\Formatter;
use Piwik\Piwik;
use Piwik\Plugin\LogTablesProvider;
use Piwik\Tracker\LogTable;
use Piwik\Tracker\PageUrl;

class DataSubjects
{
    /**
     * @var LogTablesProvider
     */
    private $logTablesProvider;

    public function __construct(LogTablesProvider $logTablesProvider)
    {
        $this->logTablesProvider = $logTablesProvider;
    }

    public function deleteDataSubjects($visits)
    {
        $logTables = $this->logTablesProvider->getAllLogTables();
        $logTables = $this->sortLogTablesToEnsureDataErasureFromAllTablesIsPossible($logTables);

        if (empty($visits)) {
            return array();
        }

        $results = array();

        /**
         * Lets you delete data subjects to make your plugin GDPR compliant.
         * This can be useful if you have developed a plugin which stores any data for visits but doesn't
         * use any core logic to store this data. If core API's are used, for example log tables, then the data may
         * be deleted automatically.
         *
         * **Example**
         *
         *     public function deleteDataSubjects(&$result, $visitsToDelete)
         *     {
         *         $numDeletes = $this->deleteVisits($visitsToDelete)
         *         $result['myplugin'] = $numDeletes;
         *     }
         *
         * @param array &$results An array storing the result of how much data was deleted for .
         * @param array &$visits An array with multiple visit entries containing an idvisit and idsite each. The data
         *                       for these visits is requested to be deleted.
         */
        Piwik::postEvent('PrivacyManager.deleteDataSubjects', array(&$results, $visits));

        foreach ($logTables as $logTable) {
            $logTableName = $logTable->getName();
            if ($logTableName === 'log_action') {
                continue; // will be deleted via cron job automatically if the action is no longer in use
            }

            $from = array($logTableName);
            $tableToSelect = $this->findNeededTables($logTable, $from);

            if (!$tableToSelect) {
                throw new \Exception('Cannot join table ' . $logTable->getName());
            }

            list($where, $bind) = $this->visitsToWhereAndBind($tableToSelect, $visits);

            $sql = "DELETE $logTableName FROM " . $this->makeFromStatement($from) . " WHERE $where";

            $result = Db::query($sql, $bind)->rowCount();

            $results[$logTableName] = $result;
        }

        krsort($results); // make sure test results are always in same order

        return $results;
    }

    /**
     * @param LogTable[] $logTables
     * @return LogTable[]
     */
    private function sortLogTablesToEnsureDataErasureFromAllTablesIsPossible($logTables)
    {
        // we need to make sure to delete eg first entries from a table log_form_field before log_form...
        // otherwise when deleting log_form entries first, the log_form_field entries that belong to a requested visit
        // cannot be deleted anymore as the links would be all gone. We need to make sure to delete from log_visit last
        // and log_link_visit_action second to last.
        usort($logTables, function ($a, $b) {
            /** @var LogTable $a */
            /** @var LogTable $b */
            $aName = $a->getName();
            $bName = $b->getName();
            if ($bName === 'log_visit') {
                return -1;
            } else if ($aName === 'log_visit') {
                return 1;
            } else if ($bName === 'log_link_visit_action') {
                return -1;
            } else if ($aName === 'log_link_visit_action') {
                return 1;
            }

            $aWays = $a->getWaysToJoinToOtherLogTables();
            foreach ($aWays as $table => $column) {
                if ($table === $bName) {
                    return -1;
                }
            }

            $bWays = $b->getWaysToJoinToOtherLogTables();
            foreach ($bWays as $table => $column) {
                if ($table === $aName) {
                    return 1;
                }
            }

            if ($bWays && !$aWays) {
                return 1;
            }

            if (!$bWays && $aWays) {
                return -1;
            }

            return 0;
        });
        return $logTables;
    }

    public function exportDataSubjects($visits)
    {
        if (empty($visits)) {
            return array();
        }

        $logTables = $this->logTablesProvider->getAllLogTables();
        $logTables = $this->sortLogTablesToEnsureDataErasureFromAllTablesIsPossible($logTables);
        $logTables = array_reverse($logTables); // we want to list most important tables first
        /** @var LogTable[] $logTables */

        $dimensions = Dimension::getAllDimensions();

        $results = array();

        foreach ($logTables as $logTable) {
            $logTableName = $logTable->getName();
            if ('log_action' === $logTableName) {
                continue; // we export these entries further below
            }

            $from = array($logTableName);
            $tableToSelect = $this->findNeededTables($logTable, $from);

            if (!$tableToSelect) {
                // cannot join this table automatically, we do not fail as this would break the feature entirely
                // when eg not all third party plugins are updated to latest version etc
                continue;
            }

            list($where, $bind) = $this->visitsToWhereAndBind($tableToSelect, $visits);

            $select = array();
            $cols = DbHelper::getTableColumns(Common::prefixTable($logTableName));
            ksort($cols); // make sure test results will be always in same order

            $binaryFields = array();
            $dimensionPerCol = array();
            foreach ($cols as $col => $config) {
                foreach ($dimensions as $dimension) {
                    if ($dimension->getDbTableName() === $logTableName && $dimension->getColumnName() === $col) {
                        if ($dimension->getType() === Dimension::TYPE_BINARY) {
                            $binaryFields[] = $col;
                        }
                        $dimensionPerCol[$col] = $dimension;
                        break;
                    }
                }
                if (!empty($config['Type']) && strpos(strtolower($config['Type']), 'binary') !== false) {
                    $binaryFields[] = $col;
                }
                $select[] = sprintf('`%s`.`%s`', $logTableName, $col);
            }
            if (!isset($cols['idsite'])) {
                $select[] = sprintf('`%s`.`idsite`', $tableToSelect);
            }
            $binaryFields = array_unique($binaryFields);
            $select = implode(',', $select);

            $sql = "SELECT $select FROM " . $this->makeFromStatement($from) . ' WHERE ' . $where;

            $idFields = $logTable->getIdColumn();
            if (!empty($idFields)) {
                if (!is_array($idFields)) {
                    $idFields = array($idFields);
                }
                $sql .= ' ORDER BY ';
                foreach ($idFields as $field) {
                    $sql .= " `$logTableName`.`$field`,";
                }
                $sql = rtrim($sql, ',');
            }

            $result = Db::fetchAll($sql, $bind);

            $numResults = count($result);
            for ($index = 0; $index < $numResults; $index++) {
                foreach ($binaryFields as $binaryField) {
                    if (isset($result[$index][$binaryField])) {
                        $result[$index][$binaryField] = bin2hex($result[$index][$binaryField]);
                    }
                }
                foreach ($result[$index] as $rowColumn => $rowValue) {
                    if (isset($dimensionPerCol[$rowColumn])) {
                        $result[$index][$rowColumn] = $dimensionPerCol[$rowColumn]->formatValue($rowValue, $result[$index]['idsite'], new Formatter());
                    } else if (!empty($rowValue)) {
                        // we try to auto detect uncompressed values so plugins have to do less themselves. makes it a bit slower but should be fine
                        $testValue = @gzuncompress($rowValue);
                        if ($testValue !== false) {
                            $result[$index][$rowColumn] = $testValue;
                        }
                    }
                }
            }

            $results[$logTableName] = $result;
        }

        foreach ($dimensions as $dimension) {
            $join = $dimension->getDbColumnJoin();
            $dimensionColumn = $dimension->getColumnName();
            $dimensionTable = $dimension->getDbTableName();
            $dimensionLogTable = $this->logTablesProvider->getLogTable($dimensionTable);

            if ($join && $join instanceof ActionNameJoin && $dimensionColumn && $dimensionTable && $dimensionLogTable && $dimensionLogTable->getColumnToJoinOnIdVisit()) {
                $from = array('log_action', array('table' => $dimensionTable, 'joinOn' => "log_action.idaction = `$dimensionTable`.`$dimensionColumn`"));

                $tableToSelect = $this->findNeededTables($dimensionLogTable, $from);
                list($where, $bind) = $this->visitsToWhereAndBind($tableToSelect, $visits);
                $from = $this->makeFromStatement($from);

                $sql = "SELECT log_action.idaction, log_action.name, log_action.url_prefix FROM $from WHERE $where";

                $result = Db::fetchAll($sql, $bind);
                if (!empty($result)) {
                    foreach ($result as $index => $val) {
                        if (isset($val['url_prefix'])) {
                            $result[$index]['name'] = PageUrl::reconstructNormalizedUrl($val['name'], $val['url_prefix']);
                        }

                        unset($result[$index]['url_prefix']);
                    }

                    $result = array_values(array_unique($result, SORT_REGULAR));
                    usort($result, function ($a1, $a2) {
                        return $a1['idaction'] > $a2['idaction'] ? 1 : -1;
                    });
                    $results['log_action_' . $dimensionTable.'_' . $dimensionColumn] = $result;
                }
            }
        }

        /**
         * Lets you enrich the data export for one or multiple data subjects to make your plugin GDPR compliant.
         * This can be useful if you have developed a plugin which stores any data for visits but doesn't
         * use any core logic to store this data. If core API's are used, for example log tables, then the data may
         * be exported automatically.
         *
         * **Example**
         *
         *     public function exportDataSubjects(&export, $visitsToExport)
         *     {
         *         $export['myplugin'] = array();
         *         foreach($visitsToExport as $visit) {
         *              $export['myplugin'][] = 'exported data';
         *         }
         *     }
         *
         * @param array &$results An array containing the exported data subjects.
         * @param array &$visits An array with multiple visit entries containing an idvisit and idsite each. The data
         *                       for these visits is requested to be exported.
         */
        Piwik::postEvent('PrivacyManager.exportDataSubjects', array(&$results, $visits));

        krsort($results); // make sure test results are always in same order

        return $results;
    }

    private function findNeededTables(LogTable $logTable, &$from)
    {
        $logTableName = $logTable->getName();

        if ($logTable->getColumnToJoinOnIdVisit()) {
            $tableToSelect = 'log_visit';
            if ($logTableName !== 'log_visit') {
                $from[] = array('table' => 'log_visit', 'joinOn' => sprintf('%s.%s = %s.%s', $logTableName, $logTable->getColumnToJoinOnIdVisit(), 'log_visit', 'idvisit'));
            }
        } elseif ($logTable->getColumnToJoinOnIdAction()) {
            $tableToSelect = 'log_link_visit_action';
            if ($logTableName !== 'log_link_visit_action') {
                $from[] = array('table' => 'log_link_visit_action', 'joinOn' => sprintf('%s.%s = %s.%s', $logTableName, $logTable->getColumnToJoinOnIdAction(), 'log_link_visit_action', 'idaction_url'));
            }
        } else {
            $tableToSelect = $this->joinNonCoreTable($logTable, $from);
        }

        return $tableToSelect;
    }

    private function makeFromStatement($from)
    {
        $firstTable = array_shift($from);
        $fromStatement = Common::prefixTable($firstTable) . ' ' . $firstTable;
        foreach ($from as $tbl) {
            if (is_array($tbl)) {
                $fromStatement .= ' LEFT JOIN ' . Common::prefixTable($tbl['table']) . ' ' . $tbl['table'] . ' ON ' . $tbl['joinOn'] . ' ';
            } else {
                $fromStatement .= Common::prefixTable($firstTable) . ' ' . $firstTable;
            }
        }
        return $fromStatement;
    }

    private function visitsToWhereAndBind($tableToSelect, $visits)
    {
        $where = array();
        $bind = array();
        foreach ($visits as $visit) {
            $where[] = '(' . $tableToSelect . '.idsite = ? AND ' . $tableToSelect . '.idvisit = ?)';
            $bind[] = $visit['idsite'];
            $bind[] = $visit['idvisit'];
        }
        $where = implode(' OR ', $where);

        return array($where, $bind);
    }

    private function joinNonCoreTable(LogTable $logTable, &$from)
    {
        $logTableName = $logTable->getName();
        $nonCoreTables = $logTable->getWaysToJoinToOtherLogTables();

        if (empty($nonCoreTables)) {
            return;
        }

        foreach ($nonCoreTables as $tableName => $joinColumn) {
            $joinTable = $this->logTablesProvider->getLogTable($tableName);

            if ($joinTable->getColumnToJoinOnIdVisit()) {
                $from[] = array(
                    'table' => $joinTable->getName(),
                    'joinOn' => sprintf('%s.%s = %s.%s', $logTableName, $joinColumn, $joinTable->getName(), $joinColumn)
                );
                if ($joinTable->getName() !== 'log_visit') {
                    $from[] = array(
                        'table' => 'log_visit',
                        'joinOn' => sprintf('%s.%s = %s.%s', $joinTable->getName(), $joinTable->getColumnToJoinOnIdVisit(), 'log_visit', $joinTable->getColumnToJoinOnIdVisit())
                    );
                }
                $tableToSelect = 'log_visit';
                return $tableToSelect;
            } else {
                $subFroms = array();
                $tableToSelect = $this->joinNonCoreTable($joinTable, $subFroms);
                if ($tableToSelect) {
                    $from[] = array(
                        'table' => $joinTable->getName(),
                        'joinOn' => sprintf('%s.%s = %s.%s', $logTableName, $joinColumn, $joinTable->getName(), $joinColumn)
                    );
                    foreach ($subFroms as $subFrom) {
                        $from[] = $subFrom;
                    }
                    return $tableToSelect;
                }
            }
        }

    }

}
