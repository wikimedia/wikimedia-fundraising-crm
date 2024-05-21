<p>{ts 1=$lastUpdated}Last updated on %1{/ts}</p>
<table>
  <thead>
    <tr>
      <th>{ts}Currency{/ts}</th><th>{ts}Value in USD{/ts}</th>
    </tr>
  </thead>
  <tbody>
  {foreach from=$rates item=rate}
    <tr>
      <td>{$rate.currency}</td><td>{$rate.valueInUSD}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
