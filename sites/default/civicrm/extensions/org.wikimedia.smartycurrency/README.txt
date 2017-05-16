This is a basic CiviCRM extension that allows you to call the function extCurrency in a Smarty template to translate an abbreviation for a currency into a symbol based on the values in the civicrm_currency table.

To translate an amount the syntax is the same as the native CiviCRM function crmMoney, without the additional optional arguments:
````
{$amount|extCurrency:$currency}
````
