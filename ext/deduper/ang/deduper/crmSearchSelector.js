(function(angular, $, _) {

  /**
   * Place to cache metadata.
   *
   * @type {Array}
   */
  var schema = [];

  /**
   * Directive for handling the search criteria element.
   *
   * Note this code is responsible for adding the options when selecting - eg.
   * if you choose 'Group', 'IN' for criteria the groups are populated in
   * the right hand drop down. It is initiated by the html directive crm-search-selector.
   *
   * This allows the fields for the search to show & hide and to display the right options/widgets.
   *
   * ```
   *  <input ng-model="clause[2]" dedupe-exp-value="{field: clause[0], op: clause[1], isExtra: false}" />
   * ```
   *
   * In that way the values clause[0] &  clause[1] are defined as 'data to watch' and when they are altered
   * this code jumps into gear to adjust the widget appropriately.
   *
   * This has been shamelessly copied & butchered from api v4 explorer. However there is it part of a
   * page controller. Here we are hard-coping /accepting from the template things we have not yet figured
   * out how to genericise
   *  - entity
   *  - field metadata
   *
   * In addition we are using the apiv3 (in the controller) for the field list as we are generating
   * apiv3 parameters, and we are providing UI support for 'Between' which is not really present in the
   * explorer.
   *
   * @todo most of the above + move all the search fields into the template & make the search
   * directive provide the whole search block.
   */
  angular.module('deduper').directive('crmSearchSelector', function() {
    // Cache schema metadata
    return {
      scope: {
        data: '=crmSearchSelector'
      },
      link: function (scope, element, attrs) {
        var ts = scope.ts = CRM.ts('crm-search-selector'),
          // @todo - pass this in...
          // apiv4 uses route params but that doesn't make sense for a re-usable code chunk.
          entity = 'Contact';

        function getField(fieldName) {
          var fieldNames = fieldName.split('.');
          return get(entity, fieldNames);

          function get(entity, fieldNames) {
            if (fieldNames.length === 1) {
              return _.findWhere(schema, {name: fieldNames[0]});
            }
            var comboName = _.findWhere(entityFields(entity), {name: fieldNames[0] + '.' + fieldNames[1]});
            if (comboName) {
              return comboName;
            }
            var linkName = fieldNames.shift(),
              entityLinks = _.findWhere(links, {entity: entity}).links,
              newEntity = _.findWhere(entityLinks, {alias: linkName}).entity;
            return get(newEntity, fieldNames);
          }
        }

        function destroyWidget() {
          var $el = $(element);
          if ($el.is('.crm-form-date-wrapper .crm-hidden-date')) {
            $el.crmDatepicker('destroy');
          }
          if ($el.is('.select2-container + input')) {
            $el.crmEntityRef('destroy');
          }
          $(element).removeData().removeAttr('type').removeAttr('placeholder').show();
        }

        function makeWidget(field, op, isExtra) {
          var $el = $(element),
            dataType = field.data_type;
          if (op === 'IS NULL' || op === 'IS NOT NULL') {
            $el.hide();
            return;
          }
          if (isExtra && op === 'BETWEEN' && op === 'NOT BETWEEN') {
            $el.show();
            return;
          }
          if (dataType === 'Timestamp' || dataType === 'Date') {
            if (_.includes(['=', '!=', '<>', '<', '>=', '<', '<='], op)) {
              $el.crmDatepicker({time: dataType === 'Timestamp'});
            }
          } else if (_.includes(['=', '!=', '<>', 'IN', 'NOT IN'], op)) {
            multi = _.includes(['IN', 'NOT IN'], op);
            if (field.fk_entity) {
              $el.crmEntityRef({entity: field.fk_entity});
            } else if (field.options) {
              $el.addClass('loading').attr('placeholder', ts('- select -')).crmSelect2({allowClear: false, data: [{id: '', text: ''}]});
              var options = [];
              _.each(field.options, function(val, key) {
                options.push({id: key, text: val});
                $el.removeClass('loading').select2({multiple: multi, data: options});
              });
            } else if (dataType === 'Boolean') {
              $el.attr('placeholder', ts('- select -')).crmSelect2({allowClear: false, placeholder: ts('- select -'), data: [
                  {id: '1', text: ts('Yes')},
                  {id: '0', text: ts('No')}
                ]});
            }
          }
        }

        scope.$watchCollection('data', function(data) {
          destroyWidget();
          if (!schema.length) {
            schema = data.field_spec;
          }
          var field = getField(data.field);
          if (field) {
            makeWidget(field, data.op || '=', data.isExtra);
          }
        });
      }
    };
  });


})(angular, CRM.$, CRM._);
