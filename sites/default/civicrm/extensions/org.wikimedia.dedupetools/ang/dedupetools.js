(function(angular, $, _) {
  // Declare a list of dependencies.
  var app = angular.module('dedupetools', CRM.angRequires('dedupetools', 'contactBasic', 'conflictBasic'));
  app.run(function(editableOptions) {
    editableOptions.theme = 'bs3';
  });

})(angular, CRM.$, CRM._);
