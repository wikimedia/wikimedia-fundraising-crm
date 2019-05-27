So things I'm thinking about 

1) Deduper form
- Tile retrieval- Currently the form just loads 25 contacts into the data model and displays 2 as 
dedupe tiles at once (with one backfilling after each one is removed). Currently this stops after
25 - not great. I think ideally we would retrieve a certain number of rows into the data-model (25 seems
too few I guess - maybe 100) and more would be retrieved as those are removed. Also the choice of the
number of rows & tiles could be exposed

- Later option I kind of imaging that people would look at each tile, do something with it & it would
disappear and the next one would arise. I suspect I might need a 'later' button or something
in this flow. That would mean people could whizz through the easy ones & they would wind
up with the ones needing some research left. I'm not quite sure the mechanics of this
Would I just push to the end of the rows in the data-model? Remove from the prevnext_cache?

- Pagination - I feel like people will want to see more than just 2 tiles at a tile & will want to scroll through
a set - not sure if they would just toggle between tile display & row display (angular makes that easy). Looking into 
https://ng-bootstrap.github.io/#/components/pagination/examples#customization

- Contact edit - the forms permit contact editing but you have to click save. I'm not loving that but need to 
figure out how to be different with afform.

- Merge edit - I'd kinda like the middle screen to be editable (rather than people having to edit the records).
More thought needed by I think upstream code needs some cleanup first.

2) Better conflict resolution - I'd like to call hooks to allow them to provide a 'how do we resolve this in 
aggressive mode'. For our purposes we could just say 'most recent donor first' & it would be easier for people
to 'just click'

3) Upstreaming
 - cleanup & api-ise code upstream, add tests. Remove the ones in this extension.
 - see if we can incorporate xeditable in core.
 
4) Angular testing - learn & do.
