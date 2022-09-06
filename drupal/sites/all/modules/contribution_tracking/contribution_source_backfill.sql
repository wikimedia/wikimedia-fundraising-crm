-- Copied over from old ContributionTracking wiki extension
-- https://phabricator.wikimedia.org/diffusion/ECNT/browse/master/patches/patch-contribution_source_table.sql;22cb54fc457b7b7d7aaaa38d86393b65ff5ae809
-- Updated to work with iDEAL rows that have 3 dots
-- Backfill with the queues off.
insert into contribution_source
select
  ct.id as contribution_tracking_id,
  substring_index(ct.utm_source, '.', 1) as banner,
  substring_index(substring_index(ct.utm_source, '.', 2), '.', -1) as landing_page,
  substring_index(substring_index(ct.utm_source, '.', 3), '.', -1) as payment_method
from contribution_tracking ct
       left join contribution_source cs
                 on ct.id = cs.contribution_tracking_id
where
  cs.contribution_tracking_id is null
  and ct.utm_source is not null
  and length(ct.utm_source) - length(replace(ct.utm_source, '.', '')) BETWEEN 2 AND 3
  and ct.utm_source <> '..'
  and ct.utm_source <> '...';
