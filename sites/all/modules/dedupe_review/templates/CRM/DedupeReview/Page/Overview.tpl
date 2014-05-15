{include file="CRM/DedupeReview/common.tpl" location="top"}
<div id="dedupe-overview">
    {if $numAssigned}
        {ts 1=$numAssigned 2=$myBatchesUrl}You have %1 batches <a href="%2">assigned to you</a> already.{/ts}
    {/if}
    <table class="dedupe-overview-table">
        <tr class="columnheader">
            <th>{ts}Recommended action{/ts}</th>
            <th>{ts}Total{/ts}</th>
            <th>{ts}Unassigned{/ts}</th>
            <th>&nbsp;</th>
        </tr>
        {foreach from=$dupe_categories item=dupe_category}
        <tr>
            <td>{$dupe_category.label_link}</td>
            <td>{$dupe_category.total}</td>
            <td>{$dupe_category.unassigned}</td>
            <td>{$dupe_category.links}</td>
        </tr>
        {/foreach}
    </table>
</div>
