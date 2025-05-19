<div id="changeLog" class="view-content">
  <h3 style="margin: 0px 0px 10px 0px;">Open Tickets</h3>
  {if isset($openTickets) && count($openTickets) > 0}
    <div class="form-item">
      <table>
        <tr class="columnheader">
          <th>{ts}Ticket #{/ts}</th>
          <th>{ts}Created{/ts}</th>
          <th>{ts}Subject{/ts}</th>
          <th>{ts}Status{/ts}</th>
          <th>{ts}Priority{/ts}</th>
          <th>{ts}Updated{/ts}</th>
        </tr>
        {foreach from=$openTickets item='ticket'}
          <tr class="{cycle values="odd-row,even-row"}">
            <td><a href="{$ticketURLPrefix}{$ticket.id}" target="_blank">#{$ticket.id}</a></td>
            <td>{$ticket.created_at|crmDate}</td>
            <td>{$ticket.subject|nl2br}</td>
            <td>{$ticket.status|capitalize}</td>
            <td>{$ticket.priority|capitalize}</td>
            <td>{$ticket.updated_at|crmDate}</td>
          </tr>
        {/foreach}
      </table>
    </div>
  {else}
    <div class="messages status no-popup">
      {ts}No open tickets{/ts}
    </div>
  {/if}

  <h3 style="margin: 0px 0px 10px 0px;">Resolved Tickets</h3>
  {if isset($closedTickets) && count($closedTickets) > 0}
    <div class="form-item">
      <table>
        <tr class="columnheader">
          <th>{ts}Ticket #{/ts}</th>
          <th>{ts}Created{/ts}</th>
          <th>{ts}Subject{/ts}</th>
          <th>{ts}Status{/ts}</th>
          <th>{ts}Priority{/ts}</th>
          <th>{ts}Updated{/ts}</th>
        </tr>
        {foreach from=$closedTickets item='ticket'}
          <tr class="{cycle values="odd-row,even-row"}">
            <td><a href="{$ticketURLPrefix}{$ticket.id}" target="_blank">#{$ticket.id}</a></td>
            <td>{$ticket.created_at|crmDate}</td>
            <td>{$ticket.subject|nl2br}</td>
            <td>{$ticket.status|capitalize}</td>
            <td>{$ticket.priority|capitalize}</td>
            <td>{$ticket.updated_at|crmDate}</td>
          </tr>
        {/foreach}
      </table>
    </div>
  {else}
    <div class="messages status no-popup">
      {ts}No resolved tickets{/ts}
    </div>
  {/if}
</div>
