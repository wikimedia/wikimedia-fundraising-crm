# Activity Assignee Settings

## What does this extension do?

This module provides three options affecting Activity Assignees when creating Activities via the GUI:

### 1) Limit Assignees to a specified group

Normally an activity can be assigned to any contact resulting in an email being sent to the assignee (if configured).  If the wrong assignee is selected, activity details are sent to the wrong person which could disclose confidential information.

To reduce this risk, this option limits the assignees of any activity to a specified group (static or smart) such as 'staff'.

### 2) Add the current user as an Assignee

Normally the assignee field is blank when adding an Activity.  If the main use of activities is to record people's own actions then always needing to add oneself as the assignee is repetitive.

This option allows the current user to be set as the default assignee.  The default is not enforced and can be removed when creating an Activity.

Note that if you set a group then the user will only be shown on the activity form if they are part of the group.

### 3) Add specified contacts as Assignees

This option lets you add default assignees to every activity.  I'm not sure this is widely useful but someone requested it as part of an integration process.

Note that if you set a group then these contacts will only be shown on the activity form if they are part of the group.

## How do I use the extension?

- Install the extension as normal.
- To configure the settings go to: ```Administer > System Settings > Activity Assignee Settings```
- Select the group of contacts that may be assigned activities
- Choose whether the current user is the default assignee 
- Choose other contacts as default assignees

## Note

These settings only affect activities created through the GUI.  Other ways of creating activities (e.g. API, activity imports etc) are not changed.

When this extension is enabled, the button to swap assignees and target contacts is removed (requires CiviCRM 5.41+).  This avoids situations where assignees are added complying with the limitations but are then swapped with the target contacts.

## Limitations

One group is used to limit all assigness for all activity types.  PR's welcome to allow different groups per activity type.
