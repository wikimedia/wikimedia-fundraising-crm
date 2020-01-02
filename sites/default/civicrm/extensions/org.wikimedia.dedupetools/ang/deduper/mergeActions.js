(function(angular, $, _) {
  // "mergeActions" is a basic skeletal directive.
  // Example usage: <div merge-actions="{foo: 1, bar: 2}"></div>
  angular.module('deduper').directive('mergeActions', function() {
    return {
      restrict: 'AE',
      templateUrl: '~/deduper/mergeActions.html',
      scope: {
        mergeActions: '='
      },
      link: function($scope, $el, $attr) {
        var ts = $scope.ts = CRM.ts('deduper');
        $scope.$watch('mergeActions', function(newValue){
          $scope.myOptions = newValue;
        });
      }
    };
  });
})(angular, CRM.$, CRM._);
