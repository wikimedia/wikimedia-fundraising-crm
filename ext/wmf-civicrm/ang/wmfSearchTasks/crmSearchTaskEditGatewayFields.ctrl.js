(function(angular, $) {
  "use strict";

  // Only uses ids[0]: task is restricted to one record via 'number' => '=== 1'
  // in wmf_civicrm_civicrm_searchKitTasks().
  angular.module('wmfSearchTasks').controller('crmSearchTaskEditGatewayFields', function($scope, crmApi4, searchTaskBaseTrait) {
    const ts = $scope.ts = CRM.ts('wmf_civicrm');
    const ctrl = angular.extend(this, $scope.model, searchTaskBaseTrait);

    ctrl.entityTitle = ctrl.getEntityTitle();
    ctrl.fields = [
      {name: 'contribution_extra.gateway', label: ts('Gateway')},
      {name: 'contribution_extra.gateway_txn_id', label: ts('Gateway Transaction ID')},
      {name: 'contribution_extra.backend_processor', label: ts('Backend Processor')},
      {name: 'contribution_extra.backend_processor_txn_id', label: ts('Backend Processor Transaction ID')},
      {name: 'contribution_extra.auth_id', label: ts('Auth ID')},
      {name: 'contribution_extra.capture_id', label: ts('Capture ID')},
    ];
    ctrl.initialValues = {};
    ctrl.values = {};
    ctrl.loaded = false;

    crmApi4('Contribution', 'get', {
      select: ctrl.fields.map((field) => field.name),
      where: [['id', '=', ctrl.ids[0]]],
    }).then((result) => {
      const record = result[0] || {};
      ctrl.fields.forEach((field) => {
        ctrl.initialValues[field.name] = ctrl.values[field.name] = record[field.name] || '';
      });
      ctrl.loaded = true;
    });

    ctrl.save = function() {
      const values = {};
      ctrl.fields.forEach((field) => {
        const value = ctrl.values[field.name] || '';
        if (value !== ctrl.initialValues[field.name]) {
          values[field.name] = value;
        }
      });
      ctrl.start({values: values});
    };

    ctrl.onSuccess = function() {
      CRM.alert(ts('Successfully updated %1.', {1: ctrl.entityTitle}), ts('Saved'), 'success');
      this.close();
    };

    ctrl.onError = function(error) {
      CRM.alert(ts('An error occurred while attempting to update %1.', {1: ctrl.entityTitle}), ts('Error'), 'error');
      this.cancel();
    };

  });
})(angular, CRM.$);
