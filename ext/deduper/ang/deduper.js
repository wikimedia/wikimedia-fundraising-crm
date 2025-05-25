(function(angular, $, _) {
  // Declare a list of dependencies.
  var app = angular.module('deduper', CRM.angRequires('deduper'));
  app.run(function(editableOptions) {
    editableOptions.theme = 'bs3';
  });

})(angular, CRM.$, CRM._);
