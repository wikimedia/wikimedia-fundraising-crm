-- Copied over from old ContributionTracking wiki extension
-- https://phabricator.wikimedia.org/diffusion/ECNT/browse/master/patches/patch-contribution_source_table.sql;22cb54fc457b7b7d7aaaa38d86393b65ff5ae809

-- Create triggers to synchronize changes to the new table.
drop trigger if exists contribution_tracking_insert;
drop trigger if exists contribution_tracking_update;
delimiter //

create trigger contribution_tracking_insert
  after insert
  on contribution_tracking
  for each row
begin
  -- Ensure that the utm_source has exactly two dots.
  if (new.utm_source is not null
    and (length(new.utm_source) - length(replace(new.utm_source, '.', ''))) = 2
    ) then
    -- Split column into its components.
    replace into contribution_source
    set
      contribution_tracking_id = last_insert_id(),
      banner = substring_index(new.utm_source, '.', 1),
      landing_page = substring_index(substring_index(new.utm_source, '.', 2), '.', -1),
      payment_method = substring_index(new.utm_source, '.', -1);
  end if;
end
//

create trigger contribution_tracking_update
  after update
  on contribution_tracking
  for each row
begin
  -- Ensure that the utm_source has exactly two dots.
  if (new.utm_source is not null
    and (length(new.utm_source) - length(replace(new.utm_source, '.', ''))) = 2
    ) then
    -- Split column into its components.
    replace into contribution_source
    set
      contribution_tracking_id = new.id,
      banner = substring_index(new.utm_source, '.', 1),
      landing_page = substring_index(substring_index(new.utm_source, '.', 2), '.', -1),
      payment_method = substring_index(new.utm_source, '.', -1);
  end if;
end
//

-- Note there is no delete on contribution_tracking, hence no third trigger.

delimiter ;
