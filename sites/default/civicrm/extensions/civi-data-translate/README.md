# civi-data-translate

This extension does 2 things

1) Generically it provides a methodology for intervening with apiv4 calls to provide language-appropriate
results. These could apply to any field - eg.

```
\Civi\Api4\Strings::create()
   ->setValues([
     'entity_id' => 3,
     'entity_table' => 'civicrm_grant',
     'entity_field' => 'rationale',
     'language' => 'fr_FR',
     'string' => 'raison d'être',
   '])->execute();
```

When the grant is retrieved using api v4 the value above will be returned
if the language is French (and French is not the site default language - eg)

```
$grant = \Civi\Api4\Grant::get()
  ->setLanguage('fr_FR')
   ->setSelect(['rationale'])
   ->setWhere(['id', '=', 3])
   ->execute();
```

This will work for any field in any table but the expectation is that it would be used more for
configuration-type fields. Compared to enabling multilingual it is able to support many more languages
but it is more narrowly implemented at this point.

I have not tested it with multilingual enabled & I expect it would likely get into a precedence fight
for languages supported by both, but possibly would allow for some fields to have additional language versions
over and above the enabled languages.

2) Specifically this provides support for MessageTemplates to be saved, fetched and rendered with the fields
for msg_html, msg_text and msg_subject in different languages.

For example - for saving

```
    MessageTemplate::create()->setValues([
      'workflow_name' => 'my_custom_tpl',
      'msg_text' => 'Hi {contact.first_name}. Your email is {contact.email} and your recurring amount is {contributionRecur.amount}',
      'is_default' => TRUE,
    ])->setLanguage('en_NZ')->execute();
```

Will create a new MessageTemplate. The msg_text will be saved to both civicrm_message_template.msg_text and to
civicrm_strings.string (for the specific field) because it is a create and there is no existing entry. However,
a later update like

```
MessageTemplate::update()
  ->addWhere('id', '=', $template['id'])
  ->setValues(['msg_html' => 'Hi {contact.first_name}. Your email is {contact.email} and your recurring amount is {contributionRecur.amount}'])
  ->setLanguage('fr_FR')->execute()->first();
```

will  add the msg_html string to the civicrm_strings (assuming fr_FR is not the default language) and not update the
value in civicrm_message_templates. The assumption is that once the entity exists the default language would be used
if it was desirable to update the string for that language.

When the template is retrieved with an API4 get a check will be made for override strings for the given language
and they will be returned instead. If the string does not exist the default value is used. This is the same
as in the generic use case at the top of this file.

The intervention in the Api4 create, update and save actions only works for MessageTemplate at the moment and
relies on a hard-coded list. Later maybe this could be extended or extendable but that would need some thought.

If https://lab.civicrm.org/dev/translation/-/issues/46 is agreed it is expected the existing MessageTemplate form
could be used with a url parameter to determine the language for retrieval and saving.

**Rendering Message Templates**

Message templates can be rendered, selecting the language-appropriate strings through
the Message.render api. The example below uses js as would make sense in preview mode.

```
CRM.api4('Message', 'render', {
  workflowName: "contribution_offline_receipt",
  entity: "Contribution",
  entityIDs: [1, 2, 3],
  language: 'fr_FR',
}).then(function(results) {
 ...
});
```

In the process of rendering the following happens
1) The relevant entities are retrieved using the checkPermissions setting from
the Message.render api. If checkPermissions is true any non-permitted entities will be
skipped.
2) The retrieved values and the retrieved template are passed through the tokenProcessor. The token
processor is able to swap out tokens like {contact.first_name} and {contact.email_greeting} along with
any tokens specific to the entity which has been retrieved. For example when sending an email
for a recurring contribution {contributionRecur.amount} {contributionRecur.installments} etc are all
swapped out.

Note that the focus at this stage has been on limited tokens for contributionRecur and contact
and providing formatted or label tokens has not been looked at yet.
Support for other entities is currently limited by them not being listed as 'options'.
Also, currently language is set per api-call. It could be retrieved from the contact in future.

Also note that smarty level rendering and sending/ pdfing the templates has been excluded.
I believe the rendering is important by itself - e.g for retrieving via js & previewing
and that by focussing on civi tokens here another api could wrap this api, also parsing
through a templating language (Smarty / twig/ Dr Seuss verse etc), possibly with different permissions.

**Some thoughts about formatting**
 - probably some generic options should be handled
 e.g.
 ```
  {contributionRecur.contribution_status_id}
  {contributionRecur.contribution_status_id:name}
  {contributionRecur.contribution_status_id:label}
```

Also I think all money fields should probably support formatting varietals. e.g
 ```
  {contributionRecur.amount}
  {contributionRecur.amount:formatted}
```
With the formatting being derived from a library using the currency and
 locale. See https://lab.civicrm.org/dev/translation/-/issues/48

**Some thoughts about the Entity**

Generally workflow templates have a base entity. That isn't stored anywhere and in this
implementation that base entity is simply passed in. However, it feels like that should be
'known' from the choice of template.
 The non-standard option value system gives a good 'hint' but it doesn't distinguish between Contribution and
ContributionRecur. While it IS possible to determine the latter from the former it's not
clear that it should be necessary. On the flip side we could resolve any ContributionRecur
tokens if we know the contribution id. It seems we need to know both what the based entity is
and, if offering choices of tokens in the UI, what entities we should offer tokens for.

**Other thoughts**
The underlying token problem we have is that we have workflow templates to be used when
a workflow is triggered that are tightly coupled to quickform forms assigning variables.
For example the offline contribution template uses
```
 {$formValues.total_amount|crmMoney:$currency}
```
Ideally it would use
```
 {contribution.total_amount:formatted}
```
And once we have consistent tokens we would work to deprecate the $formValues.total_amount
As we move away from quickform this becomes important. Currently the MessageTemplate::send
function does it's own version of handling {contact.first_name} type CiviCRM tokens but
not other entities. The ideal would be to have reliable entity-relevant tokens available so we
can change
```
{ts}Pledge Received{/ts}: {$create_date|truncate:10:''|crmDate}
{ts}Total Pledge Amount{/ts}: {$total_pledge_amount|crmMoney:$currency}
```
to
```
{ts}Pledge Received{/ts}: {pledge.create_date:formatted}
{ts}Total Pledge Amount{/ts}: {pledge.total_amount:formatted}
```

If the template 'knew' it was a pledge template then pledge-relevant tokens
could be offered in the token UI (a whole nother canoworms)

If we had merged the Message.render api into core I would have investigated calling
Message.render rather than Message.get when we retrieve the template in
CRM_Core_BAO_MessageTemplate::sendTemplate. It would have taken a bit of testing
but if that had worked we would have had fairly simple intervention to put
in place the framework to start switching the tokens with the templates to entity-specific
 CiviCRM tokens rather than quickform-specific random tokens.

**Links**
The background to this was a discussion about how we would ideally store message_templates on a per
language basis without using the existing multilingual (which only scales to half-a-dozen languages)

Discussion notes are here:
https://pad.riseup.net/p/yoqWgVlcBIEwKWh7cob0


The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.2+
* CiviCRM 5.26

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl civi-data-translate@https://github.com/FIXME/civi-data-translate/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/civi-data-translate.git
cv en civi_data_translate
```

## Usage

At this stage this extension is only usable by developers or where the v4
API is being called with setLanguage in play

## Known Issues

