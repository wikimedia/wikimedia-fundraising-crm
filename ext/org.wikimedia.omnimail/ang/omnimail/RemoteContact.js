(function(angular, $, _) {
  angular.module('omnimail').config(function($routeProvider) {
    $routeProvider.when('/omnimail/remote-contact', {
      controller: 'OmnimailCtrl',
      controllerAs: '$ctrl',
      templateUrl: '~/omnimail/RemoteContact.html',

      // If you need to look up data when opening the page, list it out
      // under "resolve".
      resolve: {
        remoteContact: function(crmApi4, $route) {
          return crmApi4('Omnicontact', 'get', {
            contactID: $route.current.params.cid
          });
        }
      }
    })
  }).run(
    function($rootScope) {
      // Triggered on problems with "resolve"
      $rootScope.$on('$routeChangeError',
        function(event, toState, toParams, fromState, fromParams, options){
          event.preventDefault();
          alert(fromState.error_message);
        }
      );
    }
  );

  // The controller uses *injection*. This default injects a few things:
  //   $scope -- This is the set of variables shared between JS and HTML.
  //   crmApi, crmStatus, crmUiHelp -- These are services provided by civicrm-core.
  //   myContact -- The current contact, defined above in config().
  angular.module('omnimail').controller('OmnimailCtrl', function($scope, crmApi, crmStatus, crmUiHelp, remoteContact) {
    // The ts() and hs() functions help load strings for this module.
    var ts = $scope.ts = CRM.ts('org.wikimedia.omnimail');
    // Local variable for this controller (needed when inside a callback fn where `this` is not available).
    var ctrl = this;
    // Make remove contact available to html.
    this.remoteContact = remoteContact;
  });

})(angular, CRM.$, CRM._);
