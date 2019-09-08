So things I'm thinking about 

1) Deduper form

- Merge edit - I'd kinda like the middle screen to be editable (rather than people having to edit the records).
More thought needed by I think upstream code needs some cleanup first.

2) Better conflict resolution - I'd like to call hooks to allow them to provide a 'how do we resolve this in 
aggressive mode'. For our purposes we could just say 'most recent donor first' & it would be easier for people
to 'just click'

3) Upstreaming
 - cleanup & api-ise code upstream, add tests. Remove the ones in this extension.
 - see if we can incorporate xeditable in core.
 
4) Angular testing - learn & do.
