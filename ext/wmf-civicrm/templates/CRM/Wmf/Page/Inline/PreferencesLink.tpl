<div>
  <div class="crm-summary-block">
    <div class="crm-clear crm-inline-block-content">
      <div class="crm-summary-row">
        <div class="crm-label">{ts}Donor Links{/ts}</div>
        <div class="crm-content">
          {ts 1=$expiryDays}(expire in %1 days){/ts}
        </div>
      </div>
      <div class="crm-summary-row">
        <a style="text-decoration: underline; margin:0 0.5em" target="_blank" rel="noopener" href="{$preferencesLink}">Email Preferences</a>
        <a style="text-decoration: underline; margin:0 0.5em" target="_blank" rel="noopener" href="{$donorPortalLink}">Donor Portal</a>{if $recurringUpgradeLink}<br/>
        <a style="text-decoration: underline; margin:0 0.5em" target="_blank" rel="noopener" href="{$recurringUpgradeLink}">Recurring Upgrade</a>{/if}
      </div>
      {if $unsubLink}
      <div class="crm-summary-row">
        <a style="text-decoration: underline; margin:0 0.5em" href="{$unsubLink}">Internal Unsubscribe</a>
      </div>
      {/if}
    </div>
  </div>
</div>
