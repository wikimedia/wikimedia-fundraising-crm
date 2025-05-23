# monolog

Monolog logging extension.

This extension provides more nuanced, extendable PSR3 logging implementations.
There are a wide range of monolog extensions and options available including logging
to slack/hipchat/mattermost, various issue trackers and various log aggregators
- see

https://seldaek.github.io/monolog/doc/02-handlers-formatters-processors.html#third-party-packages
https://github.com/Seldaek/monolog/wiki/Third-Party-Packages.

However, only a few are implemented at this stage. I have not included options
to specify the log file name or directory through the UI at this stage as
I want to ensure any security implications are considered before exposing that.
Anything with security
implications to think through (eg. being able to rename or relocate the log
files) has not been included in this initial version.

Key concepts
-
When something is logged to CiviCRM the call looks like

```
  Civi::log()->debug();
```

From 5.38 onwards the use of 'channels' are encouraged - ie

```
  Civi::log('ipn')->error();
```

If the ipn channel has not been defined it will fall back to the default channel.
This allows the possibility that different logging providers can be
configured for different channels. Monolog allows one or more logging providers
to attach to each channel. In each case you need to specify the minimum logging
severity to deal with and whether the handler is the final handler.

For example if you had decided to log all logging output to a file but
for fatal errors you wanted them to go to syslog and phpmailer* but not
to the file you would attach the normal logging handler to the channel
with an alert level of 'debug' (the lowest possible) and also configure
configure the syslog and phpmailer* handlers with the minimum alert for
to 'error' and set whichever has highest weight to be the final.

* - note the phpmailer handler is not included at this stage - I
  removed it to work through some composer issues.

## Configuration
Out of the box 4 loggers are enabled and attached to the default
/ fallback channel

1) log_file default logger, active, *default*. This should behave the same
as the normal CiviCRM logger with the exception that will delete older
   log files once there are 10 * 250MB logs in the directory. The
   size and number can be configured
2) daily_log_file, inactive. This is a potential alternative if you would
prefer a new log file each day. The number of days to keep files for
   is 30 under the default configuration. It is expect you would disable
   the log_file logger if you enable the daily_log_file
3) firephp, active. This logger kicks if in 1) the logged in user has 'view debug output'
permission AND the login user has a [firephp browser extension](https://firephp.org/)
   installed. If so the debug output will appear in the firephp tab
   in the user's browser.
4) syslog, active. Configured to miniumum level of 'error' this
logs to the syslog service


The extension is licensed under [MIT](LICENSE.txt).

## Requirements

* PHP v7.2+
* CiviCRM 5.38+

## Installation (CLI, Git)

**IMPORTANT**: You need to run `composer install` from within this extension's folder after downloading in order to get monolog installed properly.

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://lab.civicrm.org/extensions/monolog.git
cd monolog
composer install
cv en monolog
```

## Getting Started

**IMPORTANT**: You need to run `composer install` from within this extension's folder after downloading in order to get monolog installed properly.

1. Go to Administer - System Settings - Debugging and Error Handling.
2. There'll be a new field where you can specify the folder path to where you want logs stored. (In real life will want this to be more configurable, e.g. network locations, different locations per channel, different output formats, etc... It should maybe be configured via a config file in the filesystem.)

## Known Issues

If this code is disabled or otherwise becomes 'missing' then the
templates_c directory must be cleared.
