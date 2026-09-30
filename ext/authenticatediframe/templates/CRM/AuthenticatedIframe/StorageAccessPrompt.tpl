<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>{ts}Connect to CiviCRM{/ts}</title>
</head>
<body>
  <div id="connect-prompt" hidden>
    <p>{ts}Your browser needs permission to use your CiviCRM login here.{/ts}</p>
    <p><button type="button" id="connect">{ts}Connect to CiviCRM{/ts}</button></p>
  </div>
  <p id="connect-failed" hidden>{ts 1=$loginUrl}Couldn't connect. <a href="%1" target="_blank" rel="noopener">Log in to CiviCRM</a> again in a new tab, then click Connect again.{/ts}</p>
  {literal}
  <script>
    (function () {
      // Re-request as a GET, so a POST isn't re-submitted.
      function reload(params) {
        var url = new URL(location.href);
        Object.keys(params || {}).forEach(function (name) {
          url.searchParams.set(name, params[name]);
        });
        location.replace(url.href);
      }

      function showConnect() {
        document.getElementById('connect-prompt').hidden = false;
      }

      document.getElementById('connect').addEventListener('click', function () {
        document.requestStorageAccess().then(function () {
          reload();
        }, function () {
          document.getElementById('connect-failed').hidden = false;
        });
      });

      // If the API doesn't exist (old browser), reload to login page
      if (!document.hasStorageAccess) {
        reload({authenticatediframe_login: 1});
        return;
      }
      document.hasStorageAccess().then(function (hasAccess) {
        // Cookies aren't blocked, so the user just doesn't have a valid one - load the login page
        if (hasAccess) {
          reload({authenticatediframe_login: 1});
          return;
        }
        // If we already tried to get access without a click, don't try again, show the button.
        var autoTried = new URL(location.href).searchParams.has('authenticatediframe_auto');
        // Or the browser doesn't support the permission.
        if (autoTried || !navigator.permissions) {
          showConnect();
          return;
        }
        navigator.permissions.query({name: 'storage-access'}).then(function (perm) {
          // We don't have the permission, show the button
          if (perm.state !== 'granted') {
            showConnect();
            return;
          }
          // Once granted, access can be re-requested without a click (e.g. Chrome)
          return document.requestStorageAccess().then(function () {
            reload({authenticatediframe_auto: 1});
          });
        }).catch(showConnect);
      }).catch(showConnect);
    })();
  </script>
  {/literal}
</body>
</html>
