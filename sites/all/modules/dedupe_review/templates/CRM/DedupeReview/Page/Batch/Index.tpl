{include file="CRM/DedupeReview/common.tpl" location="top"}
<div id="dedupe-batch-index">
    <table class="dedupe-batch-index-table">
        <tr class="columnheader">
            <th>{ts}Batch{/ts}</th>
            <th>{ts}Total{/ts}</th>
            <th>{ts}Completed?{/ts}</th>
        </tr>
        {foreach from=$tableRows item=batch}
        <tr>
            <td>{$batch.batchLink}</td>
            <td>{$batch.total}</td>
            <td>{$batch.completed}</td>
        </tr>
        {/foreach}
    </table>
</div>
