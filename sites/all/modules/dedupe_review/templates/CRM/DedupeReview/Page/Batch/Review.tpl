{include file="CRM/DedupeReview/common.tpl" location="top"}
<div id="dedupe-batch-review">
    {if $numAssigned}
        {ts 1=$numAssigned 2=$myBatchesUrl}You have %1 batches <a href="%2">assigned to you</a> already.{/ts}
    {/if}
    <table class="dedupe-batch-review-table touch-table" id="donor_review_table">
        <tr class="columnheader">
            <th>{ts}Old{/ts}</th>
            <th>{ts}New{/ts}</th>
            <th>{ts}Email{/ts}</th>
            <th>{ts}Name{/ts}</th>
            <th>{ts}Address{/ts}</th>
            <th>{ts}Language{/ts}</th>
            <th>{ts}Contributions{/ts}</th>
            <th>&nbsp;</th>
        </tr>
        {foreach from=$tableRows item=pair}
        <tr id="item-{$pair.itemId}" class="{$pair.class}" data="{$pair.data}">
            <td>{$pair.oldId}</td>
            <td>{$pair.newId}</td>
            <td id="email-{$pair.itemId}" class="diff">{$pair.email}</td>
            <td id="name-{$pair.itemId}" class="diff">{$pair.name}</td>
            <td id="address-{$pair.itemId}" class="diff">{$pair.address}</td>
            <td id="language-{$pair.itemId}" class="{$pair.languageClass}">{$pair.language}</td>
            <td>{$pair.contributions}</td>
            <td class="buttons">
                <input type=radio name="judgement-{$pair.itemId}" id="judgement-exclude-{$pair.itemId}" value="exclude" /><label for="judgement-exclude-{$pair.itemId}">{ts}Exclude{/ts}</label><br />
                <input type=radio name="judgement-{$pair.itemId}" id="judgement-include-{$pair.itemId}"value="include" /><label for="judgement-include-{$pair.itemId}">{ts}Include{/ts}</label><br />
                <input type=radio name="judgement-{$pair.itemId}" id="judgement-rereview-{$pair.itemId}" value="rereview" /><label for="judgement-rereview-{$pair.itemId}">{ts}Rereview{/ts}</label>
            </td>
        </tr>
        {/foreach}
    </table>
    {include file="CRM/common/pager.tpl" location="bottom"}
</div>
