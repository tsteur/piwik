<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\MultiSites;

use Piwik\API\Request;
use Piwik\API\ResponseBuilder;
use Piwik\Config;
use Piwik\Period;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\DataTable\Row\DataTableSummaryRow;
use Piwik\Site;
use Piwik\View;

class Dashboard
{
    /**
     * @param array $request
     * @param string $pattern
     * @param int $limit
     * @return array
     */
    public function getAllWithGroups($request, $period, $date, $segment, $pattern, $limit)
    {
        $segment = $segment ?: false;
        $showColumns = array(
            'nb_visits', 'nb_pageviews', 'revenue'
        );
        $sites = API::getInstance()->getAll($period, $date, $segment, $_restrictSitesToLogin = false,
                                            $enhanced = true, false, $showColumns);
        $sites->deleteRow(DataTable::ID_SUMMARY_ROW);
        $sites->filter(function (DataTable $table) {
            foreach ($table->getRows() as $row) {
                $idSite = $row->getColumn('label');
                $site = Site::getSite($idSite);
                $row->setColumn('label', $site['name']);
                $row->setColumn('main_url', $site['main_url']);
                $row->setColumn('group', $site['group']);
            }
        });

        $numSites = $sites->getRowsCount();
        $totals = array(
            'nb_pageviews' => $sites->getMetadata('total_nb_pageviews'),
            'nb_visits' => $sites->getMetadata('total_nb_visits'),
            'revenue' => $sites->getMetadata('total_revenue'),
        );

        $sitesByGroup = $this->moveSitesHavingAGroupIntoSubtables($sites);

        if ($pattern !== '') {
            // apply search, we need to make sure to always include the parent group the site belongs to
            $this->nestedSearch($sitesByGroup, strtolower($pattern));
            $numSites = $sitesByGroup->getRowsCountRecursive();
        }

        $sitesExpanded = $this->convertDataTableToArray($sitesByGroup, $request);
        $sitesFlat     = $this->makeSitesFlat($sitesExpanded);

        // why do we need to apply a limit again? because we made sitesFlat and it may contain many more sites now
        if ($limit > 0) {
            $sitesFlat = array_slice($sitesFlat, 0, $limit);
        }

        return array(
            'numSites' => $numSites,
            'totals'   => $totals,
            'sites'    => $sitesFlat,
        );
    }

    private function convertDataTableToArray(DataTable $table, $request)
    {
        $request['serialize'] = 0;
        $request['expanded'] = 1;
        $request['totals'] = 0;
        $request['format_metrics'] = 1;

        $responseBuilder = new ResponseBuilder('php', $request);
        $rows = $responseBuilder->getResponse($table, 'MultiSites', 'getAll');

        return $rows;
    }

    private function moveSitesHavingAGroupIntoSubtables(DataTable $sites)
    {
        /** @var DataTableSummaryRow[] $groups */
        $groups = array();

        $sitesByGroup = $sites->getEmptyClone(true);
        $sitesByGroup->disableFilter('ColumnCallbackReplace');
        $sitesByGroup->disableFilter('MetadataCallbackAddMetadata');

        foreach ($sites->getRows() as $index => $site) {

            $group = $site->getColumn('group');

            if (!empty($group) && !array_key_exists($group, $groups)) {
                $row = new DataTableSummaryRow();
                $row->setColumn('label', $group);
                $row->setColumn('isGroup', true);
                $row->setSubtable($sites->getEmptyClone(true));
                $sitesByGroup->addRow($row);

                $groups[$group] = $row;
            }

            if (!empty($group)) {
                $groups[$group]->getSubtable()->addRow($site);
            } else {
                $sitesByGroup->addRow($site);
            }
        }

        foreach ($groups as $group) {
            $group->recalculate();
        }

        return $sitesByGroup;
    }

    private function makeSitesFlat($sites)
    {
        $flatSites = array();
        foreach ($sites as $site) {
            if (!empty($site['subtable'])) {
                $subtable = $site['subtable'];
                unset($site['subtable']);
                $flatSites[] = $site;
                foreach ($subtable as $siteWithinGroup) {
                    $flatSites[] = $siteWithinGroup;
                }
            } else {
                $flatSites[] = $site;
            }
        }

        return $flatSites;
    }

    private function nestedSearch(DataTable $sitesByGroup, $pattern)
    {
        foreach ($sitesByGroup->getRows() as $index => $site) {

            $label = strtolower($site->getColumn('label'));
            $labelMatches = false !== strpos($label, $pattern);

            if ($site->getColumn('isGroup')) {
                $subtable = $site->getSubtable();
                // filter subtable
                $this->nestedSearch($subtable, $pattern);

                if (!$labelMatches && !$subtable->getRowsCount()) {
                    $sitesByGroup->deleteRow($index);
                }

            } else {

                if (!$labelMatches) {
                    $group = $site->getColumn('group');

                    if (!$group || false === strpos(strtolower($group), $pattern)) {
                        $sitesByGroup->deleteRow($index);
                    }
                }
            }
        }
    }
}
