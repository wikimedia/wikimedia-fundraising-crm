<div id="crm-relblock-content" class="crm-inline-edit"  {literal}data-edit-params='{"cid": {/literal}{$contactId}{literal}, "class_name": "CRM_Relationshipblock_Form_Inline_RelationshipBlock"}'{/literal}>
  <div class="crm-clear crm-inline-block-content" title="{ts escape='html'}Edit info{/ts}">
    <div class="crm-edit-help"><span class="crm-i fa-pencil"></span>{ts}Edit info{/ts}</div>
    <div class="crm-clear crm-inline-block-content">
      <div class="crm-summary-row" >
        {if $existingRelationships}
          {foreach from=$existingRelationships item=existingRelationship}
            <div class="crm-label">{$existingRelationship.relationship_type}</div>
            <div class="crm-content">{$existingRelationship.relation_display_name}</div>
          {/foreach}
        {else}
          <div class="crm-label">{$keyRelationshipLabel}</div>
          <div class="crm-content"></div>
        {/if}
      </div>
    </div>
  </div>
</div>
