/**
 * Model for Multisites Dashboard aka All Websites Dashboard.
 */
(function () {
    angular.module('piwikApp').factory('multisitesDashboardModel', multisitesDashboardModel);

    multisitesDashboardModel.$inject = ['piwikApi', '$filter', '$timeout'];

    function multisitesDashboardModel(piwikApi, $filter, $timeout) {

        // those sites are going to be displayed
        var model = {
            sites        : [],
            isLoading    : false,
            pageSize     : 25,
            currentPage  : 0,
            totalVisits  : '?',
            totalActions : '?',
            totalRevenue : '?',
            searchTerm   : '',
            lastVisits   : '?',
            lastVisitsDate : '?',
            updateWebsitesList: updateWebsitesList,
            getNumberOfFilteredSites: getNumberOfFilteredSites,
            getNumberOfPages: getNumberOfPages,
            getCurrentPagingOffsetStart: getCurrentPagingOffsetStart,
            getCurrentPagingOffsetEnd: getCurrentPagingOffsetEnd,
            previousPage: previousPage,
            nextPage: nextPage,
            searchSite: searchSite,
            fetchAllSites: fetchAllSites
        };

        fetchPreviousSummary();

        return model;

        function onError () {
            model.errorLoadingSites = true;
            model.sites = [];
        }

        function updateWebsitesList(processedReport) {
            if (!processedReport) {
                onError();
                return;
            }

            var allSites = processedReport.reportData;
            var reportMetadata = processedReport.reportMetadata;
            angular.forEach(allSites, function (site, index) {
                site.idsite   = reportMetadata[index].idsite;
                site.group    = reportMetadata[index].group;
                site.main_url = reportMetadata[index].main_url;
                // casting evolution to int fixes sorting, see: https://github.com/piwik/piwik/issues/4885
                site.visits_evolution    = parseInt(site.visits_evolution, 10);
                site.pageviews_evolution = parseInt(site.pageviews_evolution, 10);
                site.revenue_evolution   = parseInt(site.revenue_evolution, 10);
            });

            model.totalActions = processedReport.reportTotal.nb_pageviews;
            model.totalVisits  = processedReport.reportTotal.nb_visits;
            model.totalRevenue = processedReport.reportTotal.revenue;
            model.sites = allSites;
        }

        function getNumberOfFilteredSites () {
            return 1000; // todo
        }

        function getNumberOfPages() {
            return Math.ceil(getNumberOfFilteredSites() / model.pageSize - 1);
        }

        function getCurrentPagingOffsetStart() {
            return Math.ceil(model.currentPage * model.pageSize);
        }

        function getCurrentPagingOffsetEnd() {
            var end = getCurrentPagingOffsetStart() + parseInt(model.pageSize, 10);
            var max = getNumberOfFilteredSites();
            if (end > max) {
                end = max;
            }
            return parseInt(end, 10);
        }

        function previousPage() {
            model.currentPage = model.currentPage - 1;
            fetchAllSites();
        }

        function nextPage() {
            model.currentPage = model.currentPage + 1;
            fetchAllSites();
        }

        function searchSite (term) {
            model.searchTerm  = term;
            model.currentPage = 0;
            fetchAllSites();
        }

        function fetchPreviousSummary () {
            piwikApi.fetch({
                method: 'API.getLastDate'
            }).then(function (response) {
                if (response && response.value) {
                    return response.value;
                }
            }).then(function (lastDate) {
                if (!lastDate) {
                    return;
                }

                model.lastVisitsDate = lastDate;

                return piwikApi.fetch({
                    method: 'API.getProcessedReport',
                    apiModule: 'MultiSites',
                    apiAction: 'getAllWithGroups',
                    hideMetricsDoc: '1',
                    filter_limit: '0',
                    showColumns: 'label,nb_visits',
                    date: lastDate
                });
            }).then(function (response) {
                if (response && response.reportTotal) {
                    model.lastVisits = response.reportTotal.nb_visits;
                }
            });
        }

        function fetchAllSites(refreshInterval) {

            if (model.isLoading) {
                piwikApi.abort();
            }

            model.isLoading = true;
            model.errorLoadingSites = false;

            var params = {
                method: 'API.getProcessedReport',
                apiModule: 'MultiSites',
                apiAction: 'getAllWithGroups',
                hideMetricsDoc: '1',
                filter_limit: model.pageSize,
                filter_offset: getCurrentPagingOffsetStart(),
                showColumns: 'label,nb_visits,nb_pageviews,visits_evolution,pageviews_evolution,revenue_evolution,nb_actions,revenue'
            };

            if (model.searchTerm) {
                model.pattern = model.searchTerm;
            }

            return piwikApi.fetch(params).then(function (response) {
                updateWebsitesList(response);
            }, onError)['finally'](function () {
                model.isLoading = false;

                if (refreshInterval && refreshInterval > 0) {
                    $timeout(function () {
                        fetchAllSites(refreshInterval);
                    }, refreshInterval * 1000);
                }
            });
        }
    }
})();
