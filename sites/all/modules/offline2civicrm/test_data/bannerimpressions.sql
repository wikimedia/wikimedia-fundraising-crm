insert into pgehres.bannerimpressions (banner, campaign, count)
select
	(select utm_source from drupal.contribution_tracking order by rand() limit 1),
	(select utm_campaign from drupal.contribution_tracking order by rand() limit 1),
	round(rand() * 10000);
