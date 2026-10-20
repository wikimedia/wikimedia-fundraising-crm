(function(angular, $, _) {
  "use strict";

  angular.module('wmfMGEngagement', CRM.angRequires('wmfMGEngagement'));

  angular.module('wmfMGEngagement').controller('WmfMGEngagementCtrl', function($scope) {
    const getFields = () => $scope.afform.getData('Activity1')[0]?.fields;

    $scope.$watch(() => getFields()?.['Major_Gifts_Engagement.Expected_Donation'], (amount) => {
      if (getFields()) {
        getFields().subject = amount ? '$' + Math.round(amount).toLocaleString('en-US') : ts('Unknown amount');
      }
    });
  });

})(angular, CRM.$, CRM._);
