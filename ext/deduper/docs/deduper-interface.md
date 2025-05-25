## The deduper interface

![Deduper Screen](images/Deduper.png?raw=true "Deduper screen")

The deduper interface allows you to use criteria to find contacts to dedupe and
to dedupe them within the screen, or to re-direct to the legacy screen with those criteria.

Note that the `Safe Merge` button will apply the same resolvers as an automated script
will, and not merge any records that are considered to be conflicted. After clicking
Safe Merge it will either merge or display any conflicts. Note that
if you choose to save a name pair at this point then automated dedupes
will always resolve that name pair in future.

![Deduper Screen](images/lukeNamePair.gif?raw=true "Saving a name pair")

## Finding contacts to merge

It is worth taking a moment to understand what is happening under the hood
when you look in your database for duplicates.

The criteria you set up, including the limit you set allow the deduper to
find your search contact set. The deduper determines who this set is and then
it has to build up a grid of matches. This dedupe-match-grid contains each field in your
dedupe rule, the value for each contact in your search contact set and
the id of every contact in the entire database who matches it.

So if your Search Contact Set has 50 contacts in it and the dedupe rule
only specifies email address it is likely to find relatively few matches on those 50
email addresses. If, however, your dedupe rule is first name and last name then
for every 'Kate' in your 50 contacts it will build a grid matching Kate to
every other Kate in your database, and do the same for Kate's last name. Just
building the grid for this one contact could be larger than for 50 contacts
with the more unique email field. If you start adding in criteria like 'state'
Dedupe Match Grid will match all your Search Contact Set contacts in California
with every other contact in your database in California.

For this reason the `limit` is an important part of the Search Criteria - by
keeping the size of the Search Contact Set manageable the resulting Dedupe Match
Grid will also be manageable. However, the `limit` can be confusing to use. If you have
a database of 200 contacts, all called Kate and you configure your Search Contact Set
to find contacts with a first name of Kate with a limit of 50 it will build a Search Contact
Set of Kates 1-50 and then a Dedupe Match Grid of those 50 Kates against all 200 contacts
Although this will find 9,950 matches (ie each of those 50
Kates will match the 199 other Kates in the database giving 50 *199 = 9950) it will not find that Kate 83 matches Kate 133
- because neither of those are in the Search Contact Set.

How do you get to the rest of the matches? Well, If you dedupe a few contacts then you will
start to see a few new Kates enter your Search Contact Set - just because the deletion of
Kate 49 means Kate 51 is now in the first 50 Kates. However, a more reliable way is to
include Contact ID in your search criteria. So if you search the first time for the
first 50 Kates, you can dedupe those contacts and then search for the first 50 Kates with
an ID greater than 50. Of course in a real database the ids don't line up so neatly and you
might need to play around with limits and ID ranges to ensure that all the Kates in your
database have a turn in the Search Contact Set.


![img.png](images/limit.png)
