CREATE TABLE IF NOT EXISTS {pr_db}{public_reporting_hours} (
    datehour datetime NOT NULL,
    country char(2),
    total decimal(14,2) unsigned NOT NULL DEFAULT '0.00',
    count int(11) NOT NULL DEFAULT '0',
    average decimal(11,2) unsigned NOT NULL DEFAULT '0.00',
    maximum decimal(11,2) unsigned NOT NULL DEFAULT '0.00',
    -- note that other tables use non-native insert_timestamp
    insert_timestamp datetime NOT NULL,
    KEY (datehour),
    KEY (country),
    KEY (count),
    UNIQUE KEY (datehour, country)
);
