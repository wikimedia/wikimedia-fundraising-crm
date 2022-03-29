(function(angular, $, _) {

  angular.module('omnimail').config(function($routeProvider) {
      $routeProvider.when('/omnimail/groupsync/', {
        controller: 'OmnimailomniGroupSync',
        controllerAs: '$ctrl',
        templateUrl: '~/omnimail/omniGroupSync.html',
      });
    }
  );

  // The controller uses *injection*. This default injects a few things:
  //   $scope -- This is the set of variables shared between JS and HTML.
  angular.module('omnimail').controller('OmnimailomniGroupSync', function($scope, $routeParams, crmApi4, $window) {
    // Make routing params available to the html.
    $scope.routeParams = $routeParams;

    $scope.omniGroupSync = function (id) {
      crmApi4('Omnigroup', 'push', {
        'groupID' : id,
      }).then(function (data) {
        $window.location.href = '/civicrm/queue/runner?reset=1&qrid=omni-sync-group-' + id;
      });
    }
  });


})(angular, CRM.$, CRM._);
