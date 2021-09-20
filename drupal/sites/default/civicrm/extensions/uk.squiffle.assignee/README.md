# Activity Assignee Settings

## What does this extension do?

This module provides two options affecting Activity Assignees when creating Activities via the GUI:

### 1) Limit Assignees to a specified group

Normally an activity can be assigned to any contact usually resulting in an email being sent to the assignee.  If the wrong assignee is selected, activity details are sent to the wrong person which could disclose confidential information.

To reduce this risk, this option limits the assignees of any activity to a specified group (static or smart) such as staff.

### 2) Default assignee is current user

Normally the assignee field is blank when adding an Activity.  If the main use of activities is to record people's own actions then always needing to add oneself as the assignee is repetitive.

This option allows the current user to be set as the default assignee.  The default is not enforced and can be removed when creating an Activity.

## How do I use the extension?

- Install the extension as normal.
- To configure the settings go to: ```Administer > System Settings > Activity Assignee Settings```
- Select the group of contacts that may be assigned activities
- Choose whether the current user is the default assignee 

## Note

These settings only affect activities created through the GUI.  Other ways of creating activities (e.g. API, activity imports etc) are not changed.

When this extension is enabled, the button to swap assignees and target contacts is removed (requires CiviCRM 5.41+).  This avoids situations where assignees are added complying with the limitations but are then swapped with the target contacts.

## Limitations

One group is used to limit all assigness for all activity types.  PR's welcome to allow different groups per activity type.
