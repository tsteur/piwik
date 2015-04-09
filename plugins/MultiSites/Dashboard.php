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
        /** @var DataTable $sites */
        $sites = Request::processRequest('MultiSites.getAll', array(
            'format_metrics' => 0,
            'totals' => 0,
            'disable_generic_filters' => '1'
        ), array(
            'enhanced' => '1',
            'period' => $period,
            'date' => $date,
            'segment' => $segment ?: false
        ));
        $sites->deleteRow(DataTable::ID_SUMMARY_ROW);

        $numSites = $sites->getRowsCount();
        $totals   = array(
            'nb_pageviews' => $sites->getMetadata('total_nb_pageviews'),
            'nb_visits' => $sites->getMetadata('total_nb_visits'),
            'revenue' => $sites->getMetadata('total_revenue'),
        );

        $sitesByGroup = $this->moveSitesHavingAGroupIntoSubtables($sites);
        $sitesByGroup->disableFilter('Sort');

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

        foreach ($sites->getRowsWithoutSummaryRow() as $index => $site) {

            $group = $site->getMetadata('group');

            if (!empty($group) && !array_key_exists($group, $groups)) {
                $row = new DataTableSummaryRow();
                $row->setColumns(array('label' => $group));
                $row->setMetadata('isGroup', true);
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
        foreach ($sitesByGroup->getRowsWithoutSummaryRow() as $index => $site) {

            $label = strtolower($site->getColumn('label'));
            $group = strtolower($site->getColumn('group'));

            if ($site->getMetadata('isGroup')) {
                $subtable = $site->getSubtable();
                // filter subtable
                $this->nestedSearch($subtable, $pattern);

                if (!$subtable->getRowsCount() && false === strpos($label, $pattern)) {
                    $sitesByGroup->deleteRow($index);
                }

            } elseif (false === strpos($label, $pattern) && (!$group || false === strpos($group, $pattern))) {
                $sitesByGroup->deleteRow($index);
            }
        }
    }
}
