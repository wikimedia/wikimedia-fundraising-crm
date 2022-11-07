{block name=head}
{literal}
    <style>
        html.js fieldset.collapsed {
            border: none;
            border-top: 2px groove rgb(192, 192, 192) !important;
            padding: 0 20px 0 20px;
            }
        html.js fieldset.collapse-processed, fieldset.crm-damaged-message {
          border: 2px groove rgb(192, 192, 192) !important;
          padding: 0 20px 0 20px;
          font-size: 1rem;
        }
        .damaged-form .crm-damaged-error, .fieldset-wrapper {
            color: inherit;
        }
        .damaged-form .crm-damaged-error p {
            font-size: 14px;
        }
        .damaged-form h5, .damaged-form a {
            font-size: 20px;
            margin: 0;
        }
        legend {
          padding-left: 20px;
        }

    </style>
{/literal}
{/block}
{* this template is used for adding/editing entities  located in forms*}
<div class="crm-block damaged-form crm-form-block crm-{$entityInClassFormat}-form-block">
  {if $action eq 8}
    <div class="messages status no-popup">
      {icon icon="fa-info-circle"}{/icon}
      {$deleteMessage|escape}
    </div>
  {else}
  <fieldset class="collapsible form-wrapper crm-damaged-error collapse-processed" id="edit-trace">
    <legend><a onclick="toggle('crm-damaged-error')" href="#" class="form-link">Failure reason:</a></legend>
  <div class="crm-damaged-error">
    <p>{$error|escape}</p>
</div>
</fieldset>
  <fieldset class="collapsible form-wrapper crm-damaged-trace collapse-processed" id="edit-trace">
<legend>
    <a onclick="toggle('crm-damaged-trace')" href="#" class="form-link">{ts}Stack trace:
{/ts}</a>
</legend>
    <div class="fieldset-wrapper" style="display: block;"><p>{$trace|purify}</p></div>
</fieldset>
 <fieldset class="crm-damaged-message">
    <legend>
        <h5>Message:</h5>
    </legend>
     <table class="form-layout-compressed">
      {foreach from=$messageFields item=fieldSpec}
        {assign var=fieldName value=$fieldSpec.name}
        <tr class="crm-{$entityInClassFormat}-form-block-{$fieldName}">
          {include file="CRM/Core/Form/Field.tpl"}
        </tr>
      {/foreach}
    </table>
    {include file="CRM/common/customDataBlock.tpl"}
 </fieldset>
  {/if}
  <div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="bottom"}</div>
</div>
{literal}
 <script type="text/javascript">

   toggle = function(className) {
 {/literal}
 {literal}
      let parent = document.querySelector('.'+className);
      let containsCollapsedClassed = parent.classList.contains('collapsed');
      if (containsCollapsedClassed) {
        parent.classList.replace('collapsed', 'collapse-processed');
        parent.querySelector('div').style.display = "block"
      } else {
        parent.classList.replace('collapse-processed', 'collapsed');
        parent.querySelector('div').style.display = "none"
      }
  }

 </script>
 {/literal}