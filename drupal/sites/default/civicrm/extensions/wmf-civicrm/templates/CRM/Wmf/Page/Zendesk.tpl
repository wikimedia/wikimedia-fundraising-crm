<h3 style="margin: 0px 0px 10px 0px;">Zendesk Tickets (Open)</h3>
{if isset($openTickets) && count($openTickets) > 0}
<ul>
{foreach from=$openTickets item='ticket'}
  <li><a href="{$ticketURLPrefix}{$ticket.id}" target="_blank">{$ticket.subject|nl2br}</a></li>
{/foreach}
</ul>
{else}
  <p>No open tickets</p>
{/if}

<h3 style="margin: 0px 0px 10px 0px;">Zendesk Tickets (Solved or Closed)</h3>
{if isset($closedTickets) && count($closedTickets) > 0}
<ul>
{foreach from=$closedTickets item='ticket'}
  <li><a href="{$ticketURLPrefix}{$ticket.id}" target="_blank">{$ticket.subject|nl2br}</a></li>
{/foreach}
</ul>
{else}
  <p>No resolved tickets</p>
{/if}
