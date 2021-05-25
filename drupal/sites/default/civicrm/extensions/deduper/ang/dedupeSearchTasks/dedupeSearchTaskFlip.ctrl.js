(function(angular, $, _) {
  "use strict";

  angular.module('dedupeSearchTasks').controller('dedupeSearchTaskFlip', function($scope, dialogService) {
    var ts = $scope.ts = CRM.ts('deduper'),
      model = $scope.model,
      ctrl = this;

    this.entityTitle = model.ids.length === 1 ? model.entityInfo.title : model.entityInfo.title_plural;

    this.cancel = function() {
      dialogService.cancel('crmSearchTask');
    };

    this.flip = function() {
      $('.ui-dialog-titlebar button').hide();
      ctrl.run = {
        select: ['first_name', 'last_name'],
        chain: {update: ['Contact', 'update', {where: [['id', '=', '$id']], values: {'first_name': '$last_name', 'last_name': '$first_name'}}]}
      };
    };

    this.onSuccess = function() {
      CRM.alert(ts('Successfully updated %1 %2.', {1: model.ids.length, 2: ctrl.entityTitle}), ts('Names flipped'), 'success');
      dialogService.close('crmSearchTask');
    };

    this.onError = function() {
      CRM.alert(ts('An error occurred while attempting to update %1 %2.', {1: model.ids.length, 2: ctrl.entityTitle}), ts('Error'), 'error');
      dialogService.close('crmSearchTask');
    };

  });
})(angular, CRM.$, CRM._);
