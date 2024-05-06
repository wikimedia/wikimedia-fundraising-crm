(function (angular, $, _) {

  angular.module('unsubscribeemail').config(function ($routeProvider) {
      $routeProvider.when('/email/unsubscribe', {
        controller: 'UnsubscribeemailUnsubCntrl',
        templateUrl: '~/unsubscribeemail/UnsubCntrl.html',
      });
    }
  );

  // The controller uses *injection*. This default injects a few things:
  //   $scope -- This is the set of variables shared between JS and HTML.
  //   crmApi, crmStatus, crmUiHelp -- These are services provided by civicrm-core .
  //   myContact -- The current contact, defined above in config().
  angular.module('unsubscribeemail').controller('UnsubscribeemailUnsubCntrl', function ($scope, crmApi, crmStatus, crmUiHelp) {
    // The ts() and hs() functions help load strings for this module.
    var ts = $scope.ts = CRM.ts('unsubscribeemail');
    var hs = $scope.hs = crmUiHelp({file: 'CRM/unsubscribeemail/UnsubCntrl'}); // See: templates/CRM/unsubscribeemail/UnsubCntrl.hlp
    $scope.unsubscribeContacts = {};
    $scope.unsubscribeEmails = {};
    $scope.nothingToUnsubscribe = true;
    $scope.searchedEmail = '';
    $scope.formVars = {};

    $scope.find = function find() {
      $scope.nothingToUnsubscribe = true;
      var emailEntered = $scope.formVars.enteredEmail;
      $scope.searchedEmail = emailEntered ;
      if ($scope.unsubscribeContacts.length) {
        var messages = {start: ts('Refreshing results ...'), success: ts('Refresh complete')};
      }
      else {
        var messages = {start: ts('Finding matches...'), success: ts('Search complete')}
      }
      crmApi('Contact', 'get', {
          email: emailEntered,
          sequential: 1,
          return: 'id, display_name, contact_type, is_opt_out',
        },
        messages
      )
        .then(function (apiResult) {
            var contactResults = apiResult['values'];
            $scope.unsubscribeContacts = apiResult['values'];
            for (var id in contactResults) {
              if (contactResults[id]['is_opt_out'] === '0') {
                contactResults[id].do_opt_out = true;
                $scope.nothingToUnsubscribe = false;
              }
              else {
                contactResults[id].do_opt_out = false;
              }
              contactResults[id].url = CRM.url('civicrm/contact/view', {
                'cid': contactResults[id].id,
                'reset': 1
              });
            }
            $scope.unsubscribeContacts = contactResults;
          }
        );
      $scope.unsubscribeEmails = {};

      crmApi('Email', 'get', {
        email: emailEntered,
        is_primary: 0,
        sequential: 1,
        'return': ['id', 'is_bulkmail', 'contact_id', 'email', 'contact_id.display_name'],
      }, messages)
        .then(function (apiEmailResult) {
          var emailResults = apiEmailResult['values'];
          for (var id in emailResults) {
            emailResults[id].contact_id_display_name = emailResults[id]['contact_id.display_name'];
            emailResults[id].url = CRM.url('civicrm/contact/view', {
              'cid': emailResults[id].contact_id,
              'reset': 1
            });
            if (emailResults[id]['is_bulkmail'] > 0) {
              emailResults[id].do_opt_out = true;
              $scope.nothingToUnsubscribe = false;
            }
            else {
              emailResults[id].do_opt_out = false;
            }
          }
          $scope.unsubscribeEmails = emailResults;
        });
    };

    $scope.unsubscribe = function unsubscribe() {
      var requests = [];
      for (var id in $scope.unsubscribeContacts) {
        if ($scope.unsubscribeContacts[id].do_opt_out) {
          requests.push(['Contact', 'create', {
            id: $scope.unsubscribeContacts[id].id,
            is_opt_out: 1,
          }]);
        }
      }
      for (var id in $scope.unsubscribeEmails) {
        if ($scope.unsubscribeEmails[id].do_opt_out) {
          requests.push(['Email', 'create', {
            id: $scope.unsubscribeEmails[id].id,
            is_bulkmail: 0,
          }]);
        }
      }
      crmApi(requests, {success: ts('Unsubscribed')}).then(function () {
          $scope.nothingToUnsubscribe = true;
          for (var id in $scope.unsubscribeEmails) {
            if ($scope.unsubscribeEmails[id]['do_opt_out'] == 1) {
              $scope.unsubscribeEmails[id]['is_bulkmail'] = 0;
              $scope.unsubscribeEmails[id]['do_opt_out'] = 0;
              $scope.unsubscribeEmails[id]['opt_out_actioned'] = 1;
            }
            else {
              $scope.nothingToUnsubscribe = false;
            }
          }
          for (var id in $scope.unsubscribeContacts) {
            if ($scope.unsubscribeContacts[id]['do_opt_out'] == 1) {
              $scope.unsubscribeContacts[id]['is_opt_out'] = 1;
              $scope.unsubscribeContacts[id]['do_opt_out'] = 0;
              $scope.unsubscribeContacts[id]['opt_out_actioned'] = 1;
            }
            else {
              $scope.nothingToUnsubscribe = false;
            }
          }
        }
      );

    };
  });

})(angular, CRM.$, CRM._);
