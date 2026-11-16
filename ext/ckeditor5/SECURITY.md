# Security inventory

This inventory records the bundled versions and known advisories reviewed on
2026-10-27. It is deliberately separate from `npm audit`: elFinder is vendored
PHP/JavaScript and is not represented in an npm lockfile.

## Version baseline

Before the 2026 modernization, the extension actually shipped CKEditor 5
41.0.0 and elFinder 2.1.53. The CKEditor 27.1.0 URL formerly present in build
metadata did not identify the committed runtime.

The extension now ships CKEditor 5 49.0.1 and elFinder 2.1.70. The shared
CKEditor build lockfile passed `npm audit` with zero known vulnerabilities on
2026-10-27.

## CKEditor 5

| Advisory | Affected old runtime | Applicability | Current status |
| --- | --- | --- | --- |
| [CVE-2024-45613 / GHSA-rgg8-g5x8-wr9v](https://github.com/ckeditor/ckeditor5/security/advisories/GHSA-rgg8-g5x8-wr9v) | Yes: 41.0.0 is in the affected 40.0.0–43.1.0 range. | Conditional XSS requiring Block Toolbar plus HTML Embed or unsafe General HTML Support. The bundled build did not include Block Toolbar. | Fixed in 48.5.1 and later; this release ships 49.0.1. |
| [CVE-2026-28343 / GHSA-jrqm-vmqc-gm93](https://github.com/ckeditor/ckeditor5/security/advisories/GHSA-jrqm-vmqc-gm93) | Yes: versions before 47.6.0 are affected. | Conditional XSS when General HTML Support is configured to accept unsafe markup. The plugin was bundled, but this extension did not ship a permissive `htmlSupport.allow` configuration; a site-supplied custom configuration could have changed that. | Fixed in 48.5.1 and later; this release ships 49.0.1. |
| [GHSA-v6mg-96c6-gmpq](https://github.com/ckeditor/ckeditor5/security/advisories/GHSA-v6mg-96c6-gmpq) | Yes: 41.0.0 predates the fix. | Conditional XSS requiring General HTML Support configured to allow object elements. The extension does not allow object elements in its built-in rulesets. | Fixed in 48.5.1 and later; this release ships 49.0.1. |

CKEditor 5 49.0.1 also fixes three advisories that do not affect this extension:

- [GHSA-2x29-fwvw-5g7x](https://github.com/ckeditor/ckeditor5/security/advisories/GHSA-2x29-fwvw-5g7x) requires the premium Track Changes plugin, which is not bundled.
- [GHSA-xh7q-269v-94j2](https://github.com/ckeditor/ckeditor5/security/advisories/GHSA-xh7q-269v-94j2) affects the premium Track Changes final-content preview, which is not bundled.
- [GHSA-vjh8-qw64-q282](https://github.com/ckeditor/ckeditor5/security/advisories/GHSA-vjh8-qw64-q282) requires CKEditor AI, which is not bundled.

Earlier advisories reviewed but not applicable to the old 41.0.0 build:

- CVE-2022-31175 affects versions before 35.0.1.
- CVE-2025-58064 starts at 44.2.0.
- GHSA-rh54-vffm-5fvp affects versions from 45.0.0 through 48.5.0 and does not
  include either the old 41.0.0 runtime or the 49.0.0 runtime.
- CVE-2025-25299 concerns the premium real-time collaboration package, which is
  not shipped by this extension.

## elFinder

| Advisory | Affected old runtime | Current status |
| --- | --- | --- |
| [CVE-2021-23394 / GHSA-qm58-cvvm-c5qr](https://github.com/Studio-42/elFinder/security/advisories/GHSA-qm58-cvvm-c5qr) | Yes: 2.1.53 is at or below 2.1.57. Unsafe `.phar` upload filtering could lead to RCE. | Fixed upstream in 2.1.58; the extension ships 2.1.70. |
| [CVE-2021-32682 / GHSA-wph3-44rj-92pr](https://github.com/Studio-42/elFinder/security/advisories/GHSA-wph3-44rj-92pr) | Yes: 2.1.53 is at or below 2.1.58. Multiple flaws could lead to RCE. | Fixed upstream in 2.1.59; the extension ships 2.1.70. |
| [CVE-2023-35840 / GHSA-wm5g-p99q-66g4](https://github.com/Studio-42/elFinder/security/advisories/GHSA-wm5g-p99q-66g4) | Yes: versions before 2.1.62 are affected by LocalFileSystem path traversal. | Fixed upstream in 2.1.62; the extension ships 2.1.70. |
| [CVE-2024-38909 / GHSA-3h9f-mm2x-4j58](https://github.com/advisories/GHSA-3h9f-mm2x-4j58) | Yes: the advisory marks versions through 2.1.64 affected by unsafe cross-volume copying. | The advisory lists no upstream patched version. The extension uses only its image and trash roots, disables archive operations, and restricts uploads to selected image MIME types. |
| [CVE-2025-0818 / GHSA-fhmh-jw4r-2xp9](https://github.com/advisories/GHSA-fhmh-jw4r-2xp9) | Yes: the advisory describes elFinder 2.1.64 and earlier. Directory traversal could allow arbitrary file deletion in exposed integrations. | elFinder's changelog records the fix in 2.1.66; the extension ships 2.1.70. |
| [CVE-2026-41247 / GHSA-8q4h-8crm-5cvc](https://github.com/Studio-42/elFinder/security/advisories/GHSA-8q4h-8crm-5cvc) | Yes: versions before 2.1.67 are affected by command injection when resize uses the ImageMagick CLI backend. | Fixed upstream in 2.1.67; the extension ships 2.1.70. |
| [CVE-2026-44521 / GHSA-c3gj-q88f-7hqj](https://github.com/Studio-42/elFinder/security/advisories/GHSA-c3gj-q88f-7hqj) | The vendored code was affected because 2.1.53 is at or below 2.1.67, but this extension does not configure the vulnerable MySQL volume driver. | Fixed upstream in 2.1.68; the extension ships 2.1.70. |
| [CVE-2026-81889 / GHSA-8x3q-jpjh-qh5c](https://github.com/Studio-42/elFinder/security/advisories/GHSA-8x3q-jpjh-qh5c) | Upstream confirmed 2.1.69 and did not determine the earliest affected version. The vulnerable URL-upload fallback exists in the old lineage. | elFinder 2.1.70 requires cURL for URL uploads. This extension additionally rejects all server-side URL uploads with `urlUploadFilter`. |
| [CVE-2026-81890 / GHSA-9hjf-w35w-6vx2](https://github.com/Studio-42/elFinder/security/advisories/GHSA-9hjf-w35w-6vx2) | Upstream confirmed 2.1.69. Older connector code also lacked the new netmount CSRF protection. | elFinder 2.1.70 protects `netmount` with CSRF validation. This extension also does not register the FTP netmount driver. |
| [CVE-2026-81891 / GHSA-gxmj-r5rf-ggwq](https://github.com/Studio-42/elFinder/security/advisories/GHSA-gxmj-r5rf-ggwq) | Upstream confirmed 2.1.69. The affected ZIP extraction MIME-normalization path was also present in the previously vendored code. | elFinder 2.1.70 normalizes extracted-file MIME types. This extension disables archive extraction and does not allow ZIP uploads. |

## Integration hardening

The connector requires `access CiviCRM`. Direct uploads additionally require
the portable CiviCRM `upload CKEditor 5 images` permission, while browsing
existing images requires `access uploaded files`. CiviCRM administrators
inherit the upload permission. A user with browse access but no upload
permission receives a read-only elFinder volume. The connector is configured
with:

- a strict image MIME allow-list (`bmp`, `gif`, `jpeg`, `png`, `webp`, `ico`);
- disabled `mkfile`, `archive`, `extract`, `edit`, and `netmount` commands;
- no FTP netmount registration;
- no server-side URL uploads;
- LocalFileSystem and Trash volumes only;
- independent server-side authorization for direct uploads and file browsing.

These controls reduce exposure but do not replace future review of upstream
CKEditor and elFinder advisories.
