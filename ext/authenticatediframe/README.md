# authenticatediframe

Allows specific pages be embedded in an authenticated iframe on a
different origin.

Cross-origin iframe embedding of an authenticated page normally fails: the
session cookie doesn't have a `SameSite` value, so it defaults to
`SameSite=Lax` in Chrome/Edge, which browsers withhold on cross-site iframe
loads. Rather than setting the *main* session cookie to `SameSite=None` which
means *any* cross-site request to the whole site is allowed, with CSRF
implications, this extension adds a setting for each route you want to allow
iframe access to from a specific origin and sets a separate cookie per route.
This limits authenticated iframe access to whitelisted routes and origins,
minimizing CSRF risk.

## Requirements and caveats
- Standalone only.
- Menu registration for a route needs
  `<access_callback>Civi\AuthenticatedIframe\AccessGuard::checkMenu</access_callback>`
  This allows this extension to make auth decisions, not core. This means
  access to the route is only available via the extension's permission check.
- ACLs are ignored: this extension allows access based only on the specified
  permissions.
- Safari user experience is poor with default settings, see How it works.
- The page to be iframed must extend `Civi\AuthenticatedIframe\IframePage`.
  Instead of accepting GET query params, a hidden form field is automatically
  added which is populated by a postMessage from the parent page.
  - `getHandshakeParams()` names the params the parent sends.
  - Handshake params must only select what to display, never trigger a change
    as the parent page sets them with no user interaction in the frame.
  - `handlePost()` returns the page's HTML. Read params with
    `$this->getPostParam()`.
  - Forms that post back to the page must include
    `{include file="CRM/AuthenticatedIframe/HandshakeParams.tpl"}`
    in order to include the original POST values.
  - The parent must send the params once the frame indicates that it is
    ready. Values must be strings or numbers; anything else is ignored.

    ```html
    <iframe id="civi" src="https://civicrm.example.org/civicrm/yourroute"></iframe>
    <script>
      const civiOrigin = 'https://civicrm.example.org';
      const frame = document.getElementById('civi');
      window.addEventListener('message', (event) => {
        if (event.origin === civiOrigin && event.source === frame.contentWindow
          && event.data?.type === 'civicrm-authenticatediframe-ready') {
          frame.contentWindow.postMessage({params: {email: 'donor@example.org'}}, civiOrigin);
        }
      });
    </script>
    ```
- Keep CORS on these routes restrictive (no `Access-Control-Allow-Origin`
  for arbitrary/reflected origins) — `frame_ancestors` only handles iframe
  embedding; a `fetch()`/XHR read is controlled by CORS instead, and the
  cookie (`SameSite=None`) would still be attached to such a request, allowing
  bypassing of the `frame_ancestors` check.

## Settings

Set `authenticatediframe_routes` in `civicrm.settings.php` with an array of
`menu route => [frame_ancestors, permissions, ttl_seconds]`. It's
deliberately not a registered setting, so it can't be set via the API, UI or
cv.

```php
$civicrm_setting['domain']['authenticatediframe_routes'] = [
  'civicrm/yourroute' => [
    'frame_ancestors' => 'https://embedder.example.com',
    'permissions' => 'access CiviCRM',
    'ttl_seconds' => 3600,
  ],
];
```

- Path matching is exact.
- `frame_ancestors` and `permissions` are both required; a route missing
  either is denied.
- Use exact origins in `frame_ancestors`: a wildcard lets any matching site
  (e.g. any customer's subdomain of a SaaS host) embed the route.
- `permissions` accepts the same string-or-array shape as
  `CRM_Core_Permission::check()`.
- `ttl_seconds` is optional and defaults to
  `standaloneusers_session_max_lifetime` (in minutes, converted here) if
  omitted.

## How it works

**`Civi\AuthenticatedIframe\LoginCookieSubscriber`**, on successful core
  login, issues an additional cookie per route in the setting that the
  contact currently holds the permissions for. Each cookie is:
  - `Path`-scoped to that one route only.
  - `SameSite=None; Secure` — so, unlike the core session cookie, it is
    sent on a cross-site load of that one route.
  - A self-verifying signed JWT (via `Civi\Crypto\CryptoJwt`), carrying
    the logged-in contact's ID, the route it was issued for, and an expiry.

**`Civi\AuthenticatedIframe\FrameAncestorsSubscriber`** (`civi.invoke.auth`
— this fires before the menu resolves/checks `access_callback`, so the
header is set even on a response `AccessGuard::checkMenu` goes on to
reject), sets `Content-Security-Policy: frame-ancestors <configured
 value>` for any route present in the setting.

**`Civi\AuthenticatedIframe\AccessGuard::checkMenu`**, wired as a route's
menu `access_callback`:
  - If the route itself isn't (properly) configured, or its page doesn't
    extend `IframePage`, it rejects the request outright (403, empty body,
    hard-deny `frame-ancestors 'none'`) rather than returning `FALSE` and
    letting core fall back to showing a login form.
  - If the request is cross-site (per `Sec-Fetch-Site`) but isn't a genuine
    iframe embed (per `Sec-Fetch-Dest`), it's rejected the same way — this
    stops the cookie (`SameSite=None`) from being usable via an `<img>`/
    `<script>` or a cross-site request straight to the URL. Fails open when
    the headers are absent (Safari older than Mar 2023), but `IframePage`
    only does work on POSTs anyway so this is just another defensive layer.
  - If the request is anything other than GET/HEAD, the `Origin` header must
    match the site's own origin (from `CRM_Utils_System::baseURL()`), or it's
    rejected the same way.
  - If the route is configured but the request is unauthenticated and an
    iframe load (per `Sec-Fetch-Dest`), Safari and Firefox might not have
    sent the third-party cookie, so the user must grant Storage Access and/or
    must log in again to set the cookie:
    - Show a page that asks the user to click Connect to
      `requestStorageAccess()` then reload. If the browser refuses (Safari
      does unless the user has used CiviCRM in a normal tab recently), it
      links to the login page in a new tab.
    - An existing Storage Access grant is re-used without a click where the
      browser supports querying the `storage-access` permission (Chrome &
      Firefox). Safari users will have to click for every page load or disable
      the "Prevent cross-site tracking" setting.
    - If the frame already has Storage Access, the user is not logged in or
      their cookie has expired, so it reloads and the user gets core's login
      page.
    - Safari older than 16.4 (March 2023) blocks third-party cookies but
      doesn't send `Sec-Fetch-Dest`, so it never gets the prompt and can't log
      in. Users must turn off "Prevent cross-site tracking" instead.
  - If the request is not authenticated and not in an iframe, core's own
    `permissionDenied()` handles it as normal (showing a login form or access
    denied message).
  - If all is correct, it uses `CRM_Core_Session::useFakeSession()` so
    anything the page writes is attributed to the user. It then returns
    `TRUE`, showing the page (or the handshake page on a GET). On a POST, it
    also extends the iframe-auth cookie's expiry once it's more than halfway
    to expiring (kind of like what standalone does for sessions).

**`Civi\AuthenticatedIframe\IframePage`**, base class for the route's page.
`frame_ancestors` controls display, not requests: any site can make a
logged-in user's browser load the route in a hidden frame without them
noticing (depending on browser and settings). Firewalls and client
certificates don't help, as the request comes from the user's own browser.
A GET that wrote data would be exploitable silently, and a page timing the
load could infer the result of a lookup (e.g. whether an email belongs to a
donor). So:
  - Every GET renders the same handshake page, whatever the query string.
    It sends `civicrm-authenticatediframe-ready` to its parent, accepts
    `{params: …}` only from its parent (which `frame_ancestors` restricts to
    approved origins), puts them in a hidden form and POSTs it back.
  - The page's own code (`handlePost()`) only runs on POSTs, which
    `AccessGuard` rejects unless `Origin` is the site's own origin.
  - The params are read from the POST body only, and assigned for
    `HandshakeParams.tpl` so the page's own forms resend them if needed.

**`Civi\AuthenticatedIframe\LogoutCookieSubscriber`**, on `civi.standalone.logout`
(fired by `Civi\Authx\Standalone::logoutSession()`/`logoutStateless()`): clears every
configured route's cookie.

