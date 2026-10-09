# symfony_email
(*FIXME: In one or two paragraphs, describe what the extension does and why one would download it. *)

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

## Getting Started

(* FIXME: Where would a new user navigate to get started? What changes would they see? *)

## Known Issues

This extension breaks monolog email sends with current settings as of Oct 2026.
In SMTPMailer we have a comment: Our cert doesn't match the internal hostname -
so we'd need to modify the extension to not verify the peer name, like
SMTPMailer does. Also re-add "symfony_email_dsn" : "smtp://mailcatcher:1025" to
build/wmf_settings_developer.json
