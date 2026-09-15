<form id="handshake" method="post">
  {include file="CRM/AuthenticatedIframe/HandshakeParams.tpl"}
</form>
{literal}
<script>
  (function () {
    window.addEventListener('message', function (event) {
      // Only the parent is trusted: frame-ancestors restricts it to allowed origins.
      if (event.source !== window.parent || !event.data || !event.data.params) {
        return;
      }
      var form = document.getElementById('handshake');
      Array.prototype.forEach.call(form.elements, function (input) {
        var value = event.data.params[input.name];
        if (typeof value === 'string' || Number.isFinite(value)) {
          input.value = value;
        }
      });
      form.submit();
    });
    window.parent.postMessage({type: 'civicrm-authenticatediframe-ready'}, '*');
  })();
</script>
{/literal}
