{literal}
<script>
  new ResizeObserver(function () {
    window.parent.postMessage({
      type: 'civicrm-authenticatediframe-height',
      height: Math.ceil(document.documentElement.getBoundingClientRect().height)
    }, '*');
  }).observe(document.documentElement);
</script>
{/literal}
