# Contributing

Thank you for considering contributing to The Island theme!

(This document needs updating following The Fork).

## Things to know before you start

- Please read the [README](https://github.com/compucorp/org.civicrm.shoreditch/blob/staging/README.md) to get an overview of what the theme is and how it works.
- The [org.civicrm.styleguide](https://github.com/civicrm/org.civicrm.styleguide/) extension is a very useful companion to the theme, please make sure to have it installed and enabled locally when working on Shoreditch.

## Reporting an issue (bug report or suggest an improvement)

https://lab.civicrm.org/extensions/theisland/-/issues

Please make sure to provide:

* Shoreditch, CiviCRM and CMS versions
* Steps to reproduce
* Screenshots (if the bug relates to styling issues)

If you want to suggest an enhancement, please make sure to provide:

* A clear description of the suggested enhancement
* Reason why it would be beneficial for the theme

## Code contributions

If you'd like to contribute to the project, please make sure to familiarize with the [coding documentation](CODING.md) and with how to appropriately [test](TESTING.md) your style changes before submitting your contribution.

### Git commit messages guidelines

* Reference any issues or pull requests that you think is useful to mention
* Keep it clear and concise (look at the git log for examples)
* Do not commit the min files (`bootstrap.css`, `custom-civicrm.css`) in any of your commits, as any commit should include source files (.scss) only. The min files will be updated before a release.

### Submitting a PR

* Open a PR against the `main` branch.
* In the PR give a description of what you changed and why, and attach before & after screenshots to show the effects of your changes.

Currently you are also required, for any work done on the style, to attach a screenshot of the BackstopJS report (see [here](TESTING.md)), showing how many tests passed, how many failed and which ones.

