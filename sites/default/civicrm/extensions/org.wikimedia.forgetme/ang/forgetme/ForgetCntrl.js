(function(angular, $, _) {

  angular.module('forgetme').config(function($routeProvider) {
      $routeProvider.when('/forgetme/forget/:id', {
        // If you need to look up data when opening the page, list it out
        // under "resolve".
        controller: 'ForgetmeForgetCntrl',
        templateUrl: '~/forgetme/ForgetCntrl.html',
        resolve: {
          currentContact: function(crmApi, $route) {
            return crmApi('Contact', 'showme', {
              id: $route.current.params.id,
              'sequential' : 1
            });
          }
        }
      });
    }
  );

  // The controller uses *injection*. This default injects a few things:
  //   $scope -- This is the set of variables shared between JS and HTML.
  //   crmApi, crmStatus, crmUiHelp -- These are services provided by civicrm-core.
  //   currentContact -- The current contact, defined above in config().
  angular.module('forgetme').controller('ForgetmeForgetCntrl', function($scope, crmApi, crmStatus, crmUiHelp, currentContact) {
    // The ts() and hs() functions help load strings for this module.
    var ts = $scope.ts = CRM.ts('forgetme');
    var hs = $scope.hs = crmUiHelp({file: 'CRM/forgetme/ForgetCntrl'}); // See: templates/CRM/forgetme/ForgetCntrl.hlp

    // We have currentContact available in JS. We also want to reference it in HTML.
    $scope.currentContact = currentContact.values[0];
    $scope.currentContactViewUrl = CRM.url('civicrm/contact/view', 'cid=' + $scope.currentContact['id']);
    $scope.contactMetadata = currentContact.metadata;
    $scope.forgotten = '';
    $scope.formSubmitted = 0;
    $scope.reference = '';

    $scope.forget = function forget() {
      $scope.formSubmitted = 1;
      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts('Forgetting...'), success: ts('Forgotten')},
        // The save action. Note that crmApi() returns a promise.
        crmApi('Contact', 'forgetme', {
          id: currentContact.id,
          reference: $scope.reference
        }).then(function (result) {
          $scope.forgotten = result['values'];
          crmApi('Contact', 'showme', {
            id: currentContact.id,
            'sequential' : 1
          }).then(function (result) {
            $scope.currentContact = result['values'][0];

          })
        })
      );
    };
  });
})(angular, CRM.$, CRM._);
