<div class="crm-summary-block">
  <div class="crm-clear crm-inline-block-content">
    <div class="crm-summary-row">
      <div class="crm-label">{ts}Email Prefs Link{/ts}</div>
      <div class="crm-content">
        {ts 1=$expiryDays}(expires in %1 days){/ts}
      </div>
    </div>
    <div class="crm-summary-row">
      <!--No label/content div around this as those make the link overflow the box-->
      <a href="{$preferencesLink}">{$preferencesLink}</a>
    </div>
  </div>
</div>
