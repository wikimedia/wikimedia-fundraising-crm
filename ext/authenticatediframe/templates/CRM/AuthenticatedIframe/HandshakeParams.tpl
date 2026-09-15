{foreach from=$handshakeParams key=name item=value}
  <input type="hidden" name="{$name|escape}" value="{$value|escape}">
{/foreach}
