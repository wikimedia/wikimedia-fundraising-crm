(function(angular, $, _) {
  "use strict";

  angular.module('wmfFinanceBatch', CRM.angRequires('wmfFinanceBatch'));

  angular.module('wmfFinanceBatch').controller('WmfFinanceBatchCtrl', function($scope) {
    const getFields = () => $scope.afform.getData('Batch1')[0].fields;
    const getExtraFields = () => $scope.afform.getData('extra').fields;

    this.onSubmit = function() {
      const fields = getFields();
      const extraFields = getExtraFields();

      // 'reference' is a plain "extra" field, not tied to an entity, and
      // Afform's required-field validation doesn't reliably apply to those,
      // so it's checked explicitly here instead.
      if (!extraFields.reference) {
        CRM.alert('Reference is a required field.', 'Please resolve these issues', 'error');
        return;
      }

      const diff = (fields['batch_data.settled_donation_amount'] || 0)
        - (fields['batch_data.settled_net_amount'] || 0)
        - (fields['batch_data.settled_fee_amount'] || 0);
      if (Math.abs(diff) > 0.005) {
        CRM.alert('Donation Amount does not equal Net Amount plus Fee Amount.', 'Amounts do not match', 'error');
        return;
      }

      // The form only collects the GatewayAccount id (settlement_gateway_account_id),
      // not its name, so a lookup is needed to build the batch's own `name`
      // (e.g. 'stripe_abc_USD'). This is deliberately built client-side,
      // even though Civi\WMFHook\Data::batchPre() resolves the same name
      // server-side to keep batch_data.settlement_gateway in sync: the user
      // needs to see and copy the exact batch name from the confirmation
      // screen afterwards (to set as settlement_batch_reference during the
      // subsequent import or batch data entry), and building it here is
      // what ensures that's the value actually shown - confirmed by testing
      // that the confirmation message doesn't reliably reflect a
      // hook-only/server-side-built name.
      const accountID = fields['batch_data.settlement_gateway_account_id'];
      const submitWithGatewayName = (gatewayName) => {
        fields.name = [
          gatewayName || '',
          extraFields.reference || '',
          fields['batch_data.settlement_currency'] || '',
        ].join('_');
        $scope.afform.submit();
      };

      if (accountID) {
        CRM.api4('GatewayAccount', 'get', {
          select: ['name'],
          where: [['id', '=', accountID]],
        }).then((result) => submitWithGatewayName(result.length ? result[0].name : ''));
      } else {
        submitWithGatewayName('');
      }
    };
  });

})(angular, CRM.$, CRM._);
