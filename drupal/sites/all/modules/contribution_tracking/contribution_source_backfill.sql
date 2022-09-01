-- Copied over from old ContributionTracking wiki extension
-- https://phabricator.wikimedia.org/diffusion/ECNT/browse/master/patches/patch-contribution_source_table.sql;22cb54fc457b7b7d7aaaa38d86393b65ff5ae809

-- CAREFULLY backfill.  Run this statement manually
-- and verify that we aren’t locking the contribution
-- tracking table, after a few seconds.  Abort if so.
-- Run in limited batches until the coast is clear.
insert ignore into contribution_source
select
  ct.id as contribution_tracking_id,
  substring_index(ct.utm_source, '.', 1) as banner,
  substring_index(substring_index(ct.utm_source, '.', 2), '.', -1) as landing_page,
  substring_index(ct.utm_source, '.', -1) as payment_method
from contribution_tracking ct
       left join contribution_source cs
                 on ct.id = cs.contribution_tracking_id
where
  cs.contribution_tracking_id is null
  and (ct.utm_source is not null
  and (length(ct.utm_source) - length(replace(ct.utm_source, '.', ''))) = 2
  )
limit 1000
;
