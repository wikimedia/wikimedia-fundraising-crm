(function(angular, $, _) {

  // Cache list of entities
  var entities = [];

  angular.module('dedupetools').config(function($routeProvider) {
      $routeProvider.when('/dupefinder/:api4entity?', {
        controller: 'DedupetoolsdupefindCntrl',
        controllerAs : 'deduperCntrl',
        templateUrl: '~/dedupetools/dupefindCntrl.html',
        title: 'Dedupe url generator',

        // If you need to look up data when opening the page, list it out
        // under "resolve".
        resolve: {
          contactFields: function(crmApi) {
            return crmApi('Contact', 'getfields', {
              action: 'get',
              api_action: 'get',
              options: {'get_options':'all'}
            });
          },
          ruleGroups: function(crmApi) {
            return crmApi('ruleGroup', 'get', {
            });
          }
        }
      });
    }
  );

  // The controller uses *injection*. This default injects a few things:
  //   $scope -- This is the set of variables shared between JS and HTML.
  //   crmApi, crmStatus, crmUiHelp -- These are services provided by civicrm-core.


//   myContact -- The current contact, defined above in config().
  angular.module('dedupetools').controller('DedupetoolsdupefindCntrl', function($scope, $routeParams, $timeout, crmApi, crmStatus, crmUiHelp, crmApi4, contactFields, ruleGroups) {
    // Main angular function.
    // The ts() and hs() functions help load strings for this module.
    var ts = $scope.ts = CRM.ts('dedupetools');
    var hs = $scope.hs = crmUiHelp({file: 'CRM/dedupetools/dupefindCntrl'});// See: templates/CRM/dedupetools/dupefindCntrl.hlp
    var vm = this;
    $scope.operators = arrayToSelect2([
      '=',
      '<=',
      '>=',
      '>',
      '<',
      "<>",
      "!=",
      'LIKE',
      "NOT LIKE",
      'IN',
      'NOT IN',
      'BETWEEN',
      'NOT BETWEEN',
      'IS NOT NULL',
      'IS NULL'
    ]);
    $scope.entities = entities;
    // We have myContact available in JS. We also want to reference it in HTML.
    $scope.contactFields = schema = contactFields['values'];
    var fieldList = [];
    _.each(contactFields['values'], function(spec) {
      fieldList.push({id : [spec.name], text : spec.title});
    });
    $scope.criteria = [];
    $scope.fieldList = fieldList;
    $scope.limit = 1000;
    $scope.newClause = null;
    $scope.ruleGroups = [];
    $scope.mergedCount = 0;
    $scope.skippedCount = 0;
    $scope.foundCount = 0;
    $scope.exceptedCount = 0;
    $scope.duplicatePairs = [];
    $scope.pagedPairs = [];
    $scope.hasSuppressedPairs = false;
    $scope.contactsToMerge = [];
    $scope.currentPage = 1;
    $scope.hasSearched = false;
    $scope.contactURL = CRM.url('civicrm/contact/view', $.param({'reset': 1, 'cid' : ''}));
    $scope.mergeURL = CRM.url('civicrm/contact/merge', $.param({'reset': 1, 'action' : 'update'}));
    $scope.isRowMerging = false;
    // Number of matches to fetch at once..
    // We might expose this.
    $scope.numberMatchesToFetch = 250;
    $scope.tilesToShow = 4;
    vm.showTiles = true;

    _.each(ruleGroups['values'], function(spec) {
      $scope.ruleGroups .push({id : spec.id, text : spec.contact_type + ' (' + spec.used + ') ' + spec.title});
      if (spec.contact_type === 'Individual' && spec.used === 'Unsupervised') {
        $scope.ruleGroupID = spec.id;
      }
    });
    $scope.hasMerged = false;
    $scope.isMerging = false;

    $scope.entity = $routeParams.api4entity;

    $scope.$watch('newClause', function(newValue, oldValue) {
      var field = newValue;
      $timeout(function() {
        if (field) {
          $scope.criteria.push([newValue, '=', '', '']);
          $scope.newClause = null;
        }
      });
    });
    var delayInMs = 2000;
    $scope.$watch('criteria', function(values) {
      // Remove empty values
      _.each(values, function(clause, index) {
        if (typeof clause !== 'undefined' && !clause[0]) {
          values.splice(index, 1);
        }
      });
      $timeout.cancel(timeoutPromise);
      timeoutPromise = $timeout(function() {
        $scope.hasMerged = false;
        writeUrl();
      }, delayInMs);
    }, true);

    $scope.$watch('ruleGroupID', function() {
      writeUrl();
    });

    $scope.$watch('limit', function() {
      writeUrl();
    });

    $scope.$watchCollection(
      "duplicatePairs",
      function(newValue, oldValue, vm ) {
        if (newValue !== oldValue) {
          if ($scope.currentPage === 1) {
            $scope.pagedPairs = newValue;
          }
          else {
            updatePagedPairs(newValue, $scope.currentPage);
          }
        }
      }
    );

    writeUrl();


    /**
     * Format the chosen criteria into a json string.
     *
     * @returns {*}
     */
    function formatCriteria() {
      var contactCriteria = {};
      var startCriteria = {};
      _.each($scope.criteria, function (criterion) {
        if (criterion[1] === '=') {
          contactCriteria[criterion[0]] = criterion[2];
        }
        else if (criterion[1] === 'BETWEEN' || criterion[1] === 'NOT BETWEEN') {
          contactCriteria[criterion[0]] = {};
          contactCriteria[criterion[0]][criterion[1]] = [criterion[2], criterion[3]];
        }
        else {
          contactCriteria[criterion[0]] = {};
          contactCriteria[criterion[0]][criterion[1]] = criterion[2];
        }

      });
      if (JSON.stringify(contactCriteria) === JSON.stringify(startCriteria)) {
        // Stick with an empty array to reflect what would happen on the core dedupe screen. This gets
        // us the same cachekey
        return {};
      }
      return {'contact': contactCriteria};
    }

    $scope.pageChanged = function(duplicatePairs, newPage) {
      $scope.currentPage = newPage;
      updatePagedPairs(duplicatePairs, newPage);
    };

    function updatePagedPairs(duplicatePairs, currentPage) {
      var rowStartIndex = ((currentPage - 1) * $scope.tilesToShow);
      // Add an extra one because
      var rowsNeededCount = rowStartIndex + $scope.tilesToShow;
      if (duplicatePairs.length >= rowsNeededCount) {
        // We have enough rows loaded -just select it.
        $scope.pagedPairs = duplicatePairs.slice(rowStartIndex, (rowStartIndex + $scope.tilesToShow));
      }
      else if ($scope.numberMatchesToFetch === rowsNeededCount) {
        $scope.pagedPairs = duplicatePairs.slice(rowStartIndex, duplicatePairs.length);
      }
      else if ($scope.foundCount > (rowStartIndex)) {
        // Refresh our loaded rows. We refresh if there are more rows to get than the row number
        // of the first number on the page but we also check if the numberMatchesToFetch has
        // already been set to our needed amount to avoid a loop when the amount is between the
        // 2 numbers or just stuff I haven't thought of. Loops are bad. Even fruit loops. They are full of sugar
        // and not much like fruit at all.
        // Ideally we would pass an offset here rather than just get the new amount but
        // I'm not confident the underlying mechanics of offset work well enough yet
        // and this is probably an edge usage at this stage..
        $scope.numberMatchesToFetch = rowsNeededCount;
        $scope.getDuplicates();
      }
    }

    function getConflicts(to_keep_id, to_remove_id,contactCriteria, pair) {
      crmApi('Contact', 'get_merge_conflicts', {
        'rule_group_id': $scope.ruleGroupID,
        'search_limit' : $scope.limit,
        'criteria': contactCriteria,
        'to_remove_id' : to_remove_id,
        'to_keep_id' : to_keep_id
      }).then(function (data) {
          pair['safe'] = data.values['safe'];
        }
      );
    }

    function updateUrl(contactCriteria) {
      $scope.url = CRM.url('civicrm/contact/dedupefind', $.param({
        'reset': 1,
        'action': 'update',
        'rgid': $scope.ruleGroupID,
        'limit': $scope.limit,
        'context': 'conflicts',
        'criteria': JSON.stringify(contactCriteria)
      }));
    }

    var timeoutPromise;
    function writeUrl() {
      var contactCriteria = formatCriteria();
      $scope.hasSearched = false;
      $scope.exceptedCount = 0;
      // We could do this second but maybe the next bit is slow...
      updateUrl(contactCriteria);
    }

    $scope.forceMerge = function (mainID, otherID, currentPage) {
      $scope.currentPage = currentPage;
      merge(mainID, otherID, 'aggressive');
    };
    $scope.retryMerge = function retryMerge(mainID, otherID, pair, currentPage) {
      $scope.currentPage = currentPage;
      merge(mainID, otherID, 'safe', pair);
    };
    $scope.dedupeException = function dedupeException(mainID, otherID, currentPage) {
      $scope.currentPage = currentPage;
      $scope.isRowMerging = true;
      crmApi('Exception', 'create', {
        'contact_id1' : mainID,
        'contact_id2' : otherID
      }).then(function (data) {
        $scope.isRowMerging = false;
          removeMergedMatch(mainID, otherID);
      });
    };

    /**
     * Move a pair out of the current set of matches.
     *
     * Currently this will just come back when the underlying data is refreshed.
     */
    $scope.delayPair = function delayPair(mainID, otherID, currentPage) {
      $scope.currentPage = currentPage;
      $scope.hasSuppressedPairs = true;
      removeMergedMatch(mainID, otherID);
    };

    $scope.notDuplicates = function notDuplicates() {
      $scope.isMerging = true;
      $scope.duplicatePairs = [];
      crmApi('Merge', 'mark_duplicate_exception', {
        'rule_group_id': $scope.ruleGroupID,
        'search_limit' : $scope.limit,
        'criteria': formatCriteria(),
      }).then (function(result) {
        $scope.foundCount = 0;
        $scope.exceptedCount = result.count;
        $scope.isMerging = false;
        $scope.duplicatePairs = [];
      });
    };

    /**
     * Merge two contacts.
     *
     * @param to_keep_id
     *  Contact ID to be kept
     * @param to_remove_id
     *  Contact ID to be deleted by merge.
     * @param mode
     *   safe or aggressive
     * @param pair
     */
    function merge(to_keep_id, to_remove_id, mode, pair) {
      $scope.isRowMerging = true;
      crmApi('Contact', 'merge', {
        'to_keep_id' : to_keep_id,
        'to_remove_id' : to_remove_id,
        'mode' : mode
      }).then(function (data) {
        $scope.isRowMerging = false;
        if (data['values']['merged'].length === 1) {
          removeMergedContact(to_remove_id);
          $scope.mergedCount++;
        }
        else {
          if (typeof pair !== 'undefined') {
            getConflicts(to_keep_id, to_remove_id, formatCriteria(), pair);
          }
        }
      });
    }

    function updateFoundCount() {
      crmApi('Merge', 'getcount', {
        'rule_group_id': $scope.ruleGroupID,
        'search_limit' : $scope.limit,
        'criteria': formatCriteria()
      }).then(function (data) {
        $scope.foundCount = data.result;
      });
    }

    function removeMergedContact(id) {
      _.each($scope.duplicatePairs, function(pair, index) {
        if (typeof(pair) !== 'undefined' && (pair['dstID'] === id || pair['srcID'] === id)) {
          $scope.duplicatePairs.splice(index, 1);
        }
      });
      updateFoundCount();
    }

    function removeMergedMatch(id, id2) {
      _.each($scope.duplicatePairs, function(pair, index) {
        if (typeof(pair) !== 'undefined' && pair['dstID'] === id && pair['srcID'] === id2 ) {
          $scope.duplicatePairs.splice(index, 1);
        }
      });
      updateFoundCount();
    }

    $scope.batchMerge = function () {
      $scope.isMerging = true;
      $scope.duplicatePairs = [];
      crmApi('Job', 'process_batch_merge', {
        'rule_group_id' : $scope.ruleGroupID,
        'search_limit' : $scope.limit,
        'criteria' : formatCriteria()
      }).then(function (data) {
        $scope.isMerging = false;
        $scope.mergedCount = data['values']['merged'].length;
        $scope.skippedCount = data['values']['skipped'].length;
        $scope.hasMerged = true;
        $scope.getDuplicates();
      });
    };

    $scope.getDuplicates = function (is_force_reload) {
      $scope.duplicatePairs = [];
      $scope.isSearching = true;
      $scope.hasSuppressedPairs = false;
      $scope.exceptedCount = 0;
      crmApi('Dedupe', 'getduplicates', {
        'rule_group_id' : $scope.ruleGroupID,
        'options': {'limit' : $scope.numberMatchesToFetch},
        'search_limit' : $scope.limit,
        'criteria' : formatCriteria(),
        'is_force_new_search' : is_force_reload
      }).then(function (data) {
        $scope.duplicatePairs = vm.duplicatePairs = data['values'];
        $scope.hasSearched = true;
        $scope.isSearching = false;
        crmApi('Merge', 'getcount', {
          'rule_group_id' : $scope.ruleGroupID,
          'search_limit' : $scope.limit,
          'criteria' : formatCriteria()
        }).then(function (data) {
          $scope.foundCount = data.result;
        });
      });
    };
  });


  // Turn a flat array into a select2 array
  function arrayToSelect2(array) {
    var out = [];
    _.each(array, function(item) {
      out.push({id: item, text: item});
    });
    return out;
  }

})(angular, CRM.$, CRM._);
