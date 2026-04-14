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
    $scope.unsubscribeContacts = [];
    $scope.unsubscribeEmails = {};
    $scope.nothingToUnsubscribe = true;
    $scope.searchedEmail = '';
    $scope.formVars = {};

    var customFields = {};
    var customFieldsReady = crmApi([
      ['CustomField', 'getvalue', {return: 'id', name: 'opt_in'}],
      ['CustomField', 'getvalue', {return: 'id', name: 'no_direct_mail'}],
    ]).then(function(results) {
      customFields.optIn = 'custom_' + results[0];
      customFields.noDirectMail = 'custom_' + results[1];
    });

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
      customFieldsReady.then(function() { return crmApi('Contact', 'get', {
          email: emailEntered,
          sequential: 1,
          return: ['id', 'display_name', 'contact_type', 'is_opt_out', 'country', 'do_not_sms', customFields.optIn, customFields.noDirectMail],
        },
        messages
      );})
        .then(function (apiResult) {
            var contactResults = apiResult['values'];
            $scope.unsubscribeContacts = apiResult['values'];
            for (var id in contactResults) {
              contactResults[id].opt_out_done = contactResults[id]['is_opt_out'] === "1";
              contactResults[id].do_opt_out = !contactResults[id].opt_out_done;
              contactResults[id].opt_in_done = contactResults[id][customFields.optIn] === "0";
              contactResults[id].do_opt_in = !contactResults[id].opt_in_done;
              if (contactResults[id].country === 'United States') {
                contactResults[id].sms_done = contactResults[id].do_not_sms === "1";
                contactResults[id].do_sms = !contactResults[id].sms_done;
                contactResults[id].direct_mail_done = contactResults[id][customFields.noDirectMail] === "1";
                contactResults[id].do_direct_mail = !contactResults[id].direct_mail_done;
              }
              if (contactResults[id].do_opt_out || contactResults[id].do_opt_in ||
                  contactResults[id].do_sms || contactResults[id].do_direct_mail) {
                $scope.nothingToUnsubscribe = false;
              }
              contactResults[id].url = CRM.url('civicrm/contact/view', {
                'cid': contactResults[id].id,
                'reset': 1
              });
            }
            $scope.unsubscribeContacts = contactResults;
            $scope.hasUSContacts = contactResults.filter(function(c) { return c.country === 'United States'; }).length > 0;
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
        var contact = $scope.unsubscribeContacts[id];
        var params = {};
        if (contact.do_opt_out) params.is_opt_out = 1;
        if (contact.do_opt_in) params[customFields.optIn] = 0;
        if (contact.do_sms) params.do_not_sms = 1;
        if (contact.do_direct_mail) params[customFields.noDirectMail] = 1;
        if (Object.keys(params).length) {
          params.id = contact.id;
          requests.push(['Contact', 'create', params]);
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
            var contact = $scope.unsubscribeContacts[id];
            if (contact.do_opt_out) contact.opt_out_done = true;
            if (contact.do_opt_in) contact.opt_in_done = true;
            if (contact.do_sms) contact.sms_done = true;
            if (contact.do_direct_mail) contact.direct_mail_done = true;
            if (!contact.opt_out_done || !contact.opt_in_done ||
                (contact.country === 'United States' && (!contact.sms_done || !contact.direct_mail_done))) {
              $scope.nothingToUnsubscribe = false;
            }
          }
        }
      );

    };
  });

})(angular, CRM.$, CRM._);
