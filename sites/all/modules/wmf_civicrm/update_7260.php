<?php

function _wmf_civicrm_update_7260_update_preferred_language() {
  $queries = array();
  $queries[] = 'DROP TABLE IF EXISTS temp_civicrm_contact';
  $queries[] = 'DROP TABLE IF EXISTS temp_contribution_tracking';
  $queries[] = 'DROP TABLE IF EXISTS temp_contribution';

  /**
   * Create a copy of the id & language fields in civicrm_contact
   * We do this because the civicrm_contact field is not indexed on
   *  preferred_language. We also add a column to store our calculations.
   */
  $queries[] = '
    CREATE TABLE `temp_civicrm_contact` (
    `id` int(10) unsigned NOT NULL COMMENT \'Unique Contact ID\',
    `preferred_language` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
    `calculated_preferred_language` varchar(12) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT \'From tracking table computation.\',
    PRIMARY KEY (`id`),
    KEY `index_preferred_language` (`preferred_language`),
    KEY `index_calculated_preferred_language` (`calculated_preferred_language`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci';

  // Query OK, 15568101 rows affected (16 min 47.84 sec)
  // (will be less on live by number since merged.
  $queries[] = '
    INSERT INTO temp_civicrm_contact (id, preferred_language)
    SELECT id, preferred_language
    FROM civicrm_contact
    WHERE is_deleted = 0
    ORDER by id ASC
  ';

  /**
   * Create a temp version of the temp_contribution_tracking table.
   *
   * We might not need to do this if the index line above stays in but
   * it gives us a chance to filter out the invalid ones too for easier visuals.
   */
  $queries[] = '
    CREATE TABLE `temp_contribution_tracking` (
    `id` int(10) unsigned NOT NULL,
    `contribution_id` int(10) unsigned DEFAULT NULL,
    `language` varchar(8) DEFAULT NULL,
    `country` varchar(2) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `language` (`language`),
    KEY `contribution_id` (`contribution_id`),
    KEY `country` (`country`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8
  ';

  /**
   * Exclude dodgey strings & null values.
   *
   * regex might look cleaner but it bypasses indexing....
   *
   * (the dodgey nl string affects one contact who has a better string already).
   *
   *  Query OK, 15686401 rows affected (8 min 26.67 sec)
   */
  $queries[] = "
    INSERT INTO temp_contribution_tracking
    SELECT id, contribution_id, language, country
    FROM drupal.contribution_tracking
    WHERE language IS NOT NULL
      AND language <> ''
      AND language NOT LIKE '?%'
      AND language NOT LIKE '\"% '
      AND language NOT LIKE '\'%'
      AND language NOT LIKE '!%'
      AND language NOT LIKE '\%%'
      AND language NOT LIKE '(%'
      AND language NOT LIKE '*%'
      AND language NOT LIKE '.%'
      AND language NOT LIKE '-%'
      AND language NOT LIKE '0%'
      AND language NOT LIKE '1%'
      AND language NOT LIKE '2%'
      AND language NOT LIKE '3%'
      AND language NOT LIKE '4%'
      AND language NOT LIKE '5%'
      AND language NOT LIKE '6%'
      AND language NOT LIKE '7%'
      AND language NOT LIKE '8%'
      AND language NOT LIKE '9%'
      AND language NOT LIKE 'Donat%'
      AND language NOT LIKE 'http%'
      AND language <> 'enÂ¢Â´cy'
      AND language <> 'nl¤cy=EU'
      AND language <> 'simple'
      AND language <> 'en¤cy=JP'
      AND language <> 'en¤cy=US'
      AND language <> '<central'
      AND contribution_id IS NOT NULL;
  ";

  /**
   * Create a table of latest contributions.
   *
   * We need to do 2 queries to get the id of the latest contribution
   * 1 to get the latest receive_date per contact, then one to match it with the
   * id. It would be unusual but not impossible for the latest receive_date not
   * to be the same as the highest id.
   * we could use a sub-query instead of a 2 step query. No strong reason not
   * to except simple queries can be easier to read
   */
  $queries[] = "
    CREATE TABLE `temp_contribution` (
    `contact_id` int(10) unsigned NOT NULL COMMENT 'FK to Contact ID',
    `id` int(10) DEFAULT NULL,
    `latest_receive_date` datetime DEFAULT NULL COMMENT 'when was gift received',
    KEY `index_contact_id` (`contact_id`),
    KEY `index_id` (`id`),
    KEY `index_lates_receive_date` (`latest_receive_date`)
  ) ENGINE=InnoDB DEFAULT  CHARSET=utf8
  ";

  /**
   * The inner join means we only get contributions with useful language info
   *  we are thus getting 'the most recent contribution with seemingly valid language info'
   * Query OK, 15302517 rows affected (7 min 59.01 sec)
   */
  $queries[] = "
    INSERT INTO temp_contribution (contact_id, latest_receive_date)
    SELECT contact_id, max(receive_date)
    FROM civicrm_contribution contrib
    INNER JOIN temp_contribution_tracking ct ON ct.contribution_id = contrib.id
    GROUP BY contact_id
  ";

  // Query OK, 15302517 rows affected (18 min 3.67 sec)
  $queries[] = "
    UPDATE temp_contribution t
    LEFT JOIN civicrm_contribution c
    ON c.contact_id = t.contact_id
    AND c.receive_date = t.latest_receive_date
    SET t.id = c.id
  ";

  // Calculate language from contribution_tracking where possible.
  // Query OK, 15302427 rows affected (15 min 49.00 sec)
  $queries[] = '
    UPDATE temp_civicrm_contact c
    INNER JOIN temp_contribution contribution ON contribution.contact_id = c.id
    INNER JOIN temp_contribution_tracking ct ON ct.contribution_id = contribution.id
    SET calculated_preferred_language = ct.language
  ';

  /**
   * Fill in NULL ones for which we have language information.
   *
   * There are relatively few of these
   *
   * SELECT calculated_preferred_language, count(*) , c.id
   * FROM civicrm_contact c
   * INNER JOIN temp_civicrm_contact t ON t.id = c.id
   * WHERE c.preferred_language IS NULL
   * AND calculated_preferred_language IS NOT NULL
   * +-------------------------------+----------+----------+
   * | calculated_preferred_language | count(*) | id       |
   *  +-------------------------------+----------+----------+
   * | de                            |       19 |    36649 |
   * | en                            |      314 |      525 |
   * | es                            |        7 |  6724749 |
   * | fr                            |        6 |   357808 |
   * | gu                            |        1 | 11321183 |
   * | it                            |        3 |    31660 |
   * | nb                            |        1 |    89072 |
   * | nl                            |        4 |  3949033 |
   * | ru                            |        2 |    43469 |
   * | zh                            |        1 | 11244268 |
   *
   * We get a much higher number if we remove strings like '_' first (per staging)
   */
  $queries[] = '
    UPDATE civicrm_contact c
    LEFT JOIN temp_civicrm_contact t
    ON c.id = t.id
    SET c.preferred_language = t.calculated_preferred_language
    WHERE c.preferred_language IS NULL
    AND calculated_preferred_language IS NOT NULL
  ';

  /**
   *
   * OK this is tricky! There is quite a number of cases where an actual language change
   * would take place. However, the total number of addresses affected is probably less than 10k
   * - the list below is blown out total-wise by Chinese variants.
   *
   * So we have a specific change for many of the below ones - spot checking I see
   * 15139091 looks to be a manual merge & edit.
   *
   *
   * SELECT calculated_preferred_language, c.preferred_language, count(*) , c.id  as sample_contact_id, max(c.id),
   * length(calculated_preferred_language) as length_calc_lang
   * FROM dev_civicrm.civicrm_contact c
   * INNER JOIN temp_civicrm_contact t ON t.id = c.id
   * WHERE c.preferred_language <> REPLACE(calculated_preferred_language, '-', '_')
   * AND calculated_preferred_language IS NOT NULL AND c.preferred_language NOT LIKE '\_%'
   * AND calculated_preferred_language <> LEFT(c.preferred_language, 2)
   * GROUP BY calculated_preferred_language, c.preferred_language
   * ORDER BY length(calculated_preferred_language);
  +-------------------------------+--------------------+----------+-------------------+-----------+------------------+
  | calculated_preferred_language | preferred_language | count(*) | sample_contact_id | max(c.id) | length_calc_lang |
  +-------------------------------+--------------------+----------+-------------------+-----------+------------------+
  | it                            | en_IT              |      266 |            305756 |  11337906 |                2 |
  | zh                            | sq_US              |        1 |           1120638 |   1120638 |                2 |
  | bat-smg                       | en_BE              |        1 |           1467768 |   1467768 |                7 |
  | en-hant                       | en_US              |        1 |           6162026 |   6162026 |                7 |
  | bat-smg                       | ba_US              |        1 |           2051003 |   2051003 |                7 |
  | zh-hant                       | en                 |        2 |           5085751 |   5263652 |                7 |
  | zh-hant                       | en_US              |       16 |               500 |   6189930 |                7 |
  | fiu-vro                       | fi_EE              |        1 |            140463 |    140463 |                7 |
  | en                            | de_IT              |        1 |           2285011 |   2285011 |                2 |
  | sv                            | en_US              |        4 |            246920 |  11431508 |                2 |
  | en                            | pl_ES              |        1 |           3853645 |   3853645 |                2 |
  | ur                            | en_US              |        1 |           4417318 |   4417318 |                2 |
  | en                            | lv_LV              |        1 |          15570445 |  15570445 |                2 |
  | es                            | ja_JP              |        1 |           2238371 |   2238371 |                2 |
  | fr                            | en_US              |      776 |             91418 |  15643789 |                2 |
  | he                            | en_US              |        6 |            174487 |  15645191 |                2 |
  | it                            | en_BE              |        1 |           3396675 |   3396675 |                2 |
  | nl                            | en_IE              |        1 |           2416722 |   2416722 |                2 |
  | pl                            | en                 |        1 |           5025939 |   5025939 |                2 |
  | nds-nl                        | nd_NL              |        4 |           2034897 |   3678088 |                6 |
  | es                            | en_HN              |        1 |           2230050 |   2230050 |                2 |
  | fa                            | en_SE              |        2 |           1165173 |   2059374 |                2 |
  | fr                            | en_KW              |        1 |           6391031 |   6391031 |                2 |
  | it                            | en_BG              |        1 |           1621003 |   1621003 |                2 |
  | ko                            | en_SG              |        1 |           1434936 |   1434936 |                2 |
  | af                            | en_ZA              |        2 |           1323634 |   1465615 |                2 |
  | pl                            | en_SE              |        1 |           1469264 |   1469264 |                2 |
  | de                            | en_AU              |        3 |           3833221 |   4314423 |                2 |
  | ru                            | en_LV              |       23 |           1320439 |   4512776 |                2 |
  | en                            | it_IT              |       42 |            434547 |  14778936 |                2 |
  | ru                            | en_AM              |        1 |           1611197 |   1611197 |                2 |
  | en                            | de_OM              |        1 |           1977521 |   1977521 |                2 |
  | sk                            | en_AT              |        1 |           3778933 |   3778933 |                2 |
  | en                            | it_GB              |        2 |           2685043 |   3988115 |                2 |
  | tr                            | en_TR              |       78 |            658332 |   6428016 |                2 |
  | en                            | af_ZA              |        1 |           4443631 |   4443631 |                2 |
  | es                            | en_EC              |        2 |           1982334 |   5606607 |                2 |
  | et                            | en_EE              |        7 |           1321203 |   5024048 |                2 |
  | fr                            | en_AU              |        1 |           5110415 |   5110415 |                2 |
  | kk                            | en_KZ              |        2 |           1536977 |   1722422 |                2 |
  | nb                            | en_NO              |       47 |           3775806 |  12033554 |                2 |
  | pl                            | en_ZA              |        1 |           1330664 |   1330664 |                2 |
  | ca                            | en_US              |        2 |           2241483 |  10970587 |                2 |
  | de                            | fi_FI              |        1 |           3701033 |   3701033 |                2 |
  | ru                            | en_BE              |        3 |           1316594 |   5844382 |                2 |
  | en                            | fr_BE              |        3 |            286622 |   4376269 |                2 |
  | ru                            | en_LT              |        2 |           1609455 |   3801893 |                2 |
  | en                            | fr_HU              |        1 |           1829616 |   1829616 |                2 |
  | sh                            | en_HR              |        2 |           1609661 |   1609817 |                2 |
  | en                            | kn_US              |        1 |           2591106 |   2591106 |                2 |
  | th                            | en_TH              |       13 |           2458603 |   6453689 |                2 |
  | en                            | de_SK              |        1 |           4131730 |   4131730 |                2 |
  | es                            | pt_US              |        1 |           1852900 |   1852900 |                2 |
  | es                            | en_RS              |        1 |           6093334 |   6093334 |                2 |
  | fr                            | en_AE              |        1 |           4200172 |   4200172 |                2 |
  | id                            | en_NO              |        1 |           3732371 |   3732371 |                2 |
  | jv                            | en_SR              |        1 |           1322756 |   1322756 |                2 |
  | pl                            | en_PL              |      302 |           1315195 |   6531196 |                2 |
  | ca                            | es_ES              |        5 |           1310504 |  15261238 |                2 |
  | de                            | en_LU              |        1 |           2031729 |   2031729 |                2 |
  | ru                            | en_KZ              |      111 |           1275471 |   6351717 |                2 |
  | en                            | nl_NL              |       15 |            215149 |  15139091 |                2 |
  | ru                            | en_MY              |        1 |           1538295 |   1538295 |                2 |
  | en                            | cs_CZ              |       27 |           1736681 |   6955512 |                2 |
  | en                            | es_CL              |        8 |           2494718 |  15445098 |                2 |
  | te                            | en_US              |        1 |           1465397 |   1465397 |                2 |
  | en                            | da_BE              |        1 |           4073750 |   4073750 |                2 |
  | es                            | en_MX              |      183 |           1668076 |   6427449 |                2 |
  | es                            | en                 |        6 |           5046334 |   6224823 |                2 |
  | fr                            | en_IT              |        3 |           4041155 |   4992523 |                2 |
  | hu                            | en_HU              |       36 |           3687389 |   6559103 |                2 |
  | ja                            | en_RU              |        1 |           6133345 |   6133345 |                2 |
  | bn                            | en_IN              |        1 |           1471036 |   1471036 |                2 |
  | de                            | en_KR              |        1 |           1954140 |   1954140 |                2 |
  | ru                            | en_UA              |      216 |           1128847 |   6367062 |                2 |
  | ru                            | en_EG              |        2 |           1533139 |   1723691 |                2 |
  | en                            | es_AR              |       17 |           1563943 |  10964706 |                2 |
  | en                            | no_AU              |        1 |           2413924 |   2413924 |                2 |
  | ta                            | en_IN              |        6 |           1322931 |   6609101 |                2 |
  | en                            | ru_KZ              |        2 |           4010812 |   4792171 |                2 |
  | es                            | en_AR              |      131 |           1615206 |  15542918 |                2 |
  | es                            | en_CN              |        1 |           4207383 |   4207383 |                2 |
  | fr                            | en_JP              |        1 |           3868805 |   3868805 |                2 |
  | ja                            | en_TH              |        1 |           4340887 |   4340887 |                2 |
  | mr                            | en_QA              |        1 |           1778581 |   1778581 |                2 |
  | bg                            | en_CZ              |        1 |           1329229 |   1329229 |                2 |
  | de                            | en_AT              |       32 |           1752232 |   7262463 |                2 |
  | ro                            | en_US              |        1 |           4959315 |   4959315 |                2 |
  | el                            | en_AU              |        1 |           1660158 |   1660158 |                2 |
  | ru                            | en_TM              |        1 |           1470490 |   1470490 |                2 |
  | en                            | te_IN              |        1 |           1374488 |   1374488 |                2 |
  | sc                            | en_CN              |        1 |          10955649 |  10955649 |                2 |
  | en                            | sk_SK              |        1 |           2342722 |   2342722 |                2 |
  | sv                            | en_DK              |        2 |           6424581 |   6431964 |                2 |
  | en                            | vi_VN              |        1 |           3996267 |   3996267 |                2 |
  | es                            | en_FR              |        1 |           4044375 |   4044375 |                2 |
  | fr                            | es_ES              |        1 |           2368509 |   2368509 |                2 |
  | hr                            | en_HR              |       17 |           1204802 |   6323478 |                2 |
  | ja                            | it_IT              |        1 |           1949921 |   1949921 |                2 |
  | ml                            | en_IN              |        2 |           1275388 |   1723468 |                2 |
  | ar                            | en_                |        2 |           3839978 |   3889401 |                2 |
  | no                            | en_US              |        1 |           5019331 |   5019331 |                2 |
  | pt                            | en_                |        2 |           3910664 |   4034625 |                2 |
  | de                            | en_HK              |        1 |            603604 |    603604 |                2 |
  | el                            | en_US              |        3 |            283698 |   5059759 |                2 |
  | ru                            | en_BG              |        2 |           1467939 |   1469257 |                2 |
  | en                            | sv_SE              |       59 |           1323348 |  11320921 |                2 |
  | ru                            | en_CH              |        3 |           5609447 |   5844146 |                2 |
  | en                            | ru_BR              |        1 |           2330303 |   2330303 |                2 |
  | sv                            | it_LU              |        1 |           4115016 |   4115016 |                2 |
  | en                            | it_BR              |        1 |           3927262 |   3927262 |                2 |
  | vi                            | en_                |        1 |           3910667 |   3910667 |                2 |
  | es                            | en_CL              |       21 |           1538446 |   6437401 |                2 |
  | es                            | sv_CL              |        1 |           3840492 |   3840492 |                2 |
  | fr                            | en_MA              |        4 |           1722051 |   2265300 |                2 |
  | hi                            | en_TH              |        1 |           3966862 |   3966862 |                2 |
  | ja                            | en_US              |       35 |            219141 |  11256063 |                2 |
  | ar                            | en_SA              |        8 |           1482575 |   4151751 |                2 |
  | no                            | en_NO              |       39 |           1668788 |   6578929 |                2 |
  | pt                            | en_BR              |       24 |           1481798 |   4643751 |                2 |
  | da                            | en                 |        1 |           6115698 |   6115698 |                2 |
  | de                            | en_CA              |        1 |          10660052 |  10660052 |                2 |
  | ru                            | en_IS              |        2 |           1463963 |   2020239 |                2 |
  | en                            | he_IL              |       29 |           1306041 |  15644720 |                2 |
  | ru                            | en_ZA              |        1 |           4890819 |   4890819 |                2 |
  | en                            | ru_PL              |        1 |           2322263 |   2322263 |                2 |
  | sv                            | en_FI              |        3 |           1854622 |   5911052 |                2 |
  | en                            | sv_ES              |        1 |           3896795 |   3896795 |                2 |
  | es                            | en_                |       22 |           3724326 |   4906385 |                2 |
  | fr                            | en_CA              |       16 |           1600445 |  14518725 |                2 |
  | he                            | ru_IL              |        1 |           4077032 |   4077032 |                2 |
  | it                            | pt_BR              |        1 |           4031948 |   4031948 |                2 |
  | lt                            | en_SE              |        2 |           1611039 |   1659993 |                2 |
  | ar                            | en_EG              |        4 |           1122067 |   2507263 |                2 |
  | da                            | en_US              |        5 |           1479090 |  11431637 |                2 |
  | de                            | en                 |        1 |           5085764 |   5085764 |                2 |
  | ru                            | en_CA              |        8 |           1330361 |   5074897 |                2 |
  | en                            | pl_AE              |        1 |           1219309 |   1219309 |                2 |
  | ru                            | tr_TR              |        1 |           3898714 |   3898714 |                2 |
  | cs                            | de_CZ              |        1 |           4138157 |   4138157 |                2 |
  | de                            | cs_CZ              |        1 |           4685530 |   4685530 |                2 |
  | ru                            | en_FI              |        3 |           1328378 |   6328812 |                2 |
  | en                            | ru_US              |        3 |            925471 |   4127734 |                2 |
  | ru                            | en_SK              |        1 |           2002968 |   2002968 |                2 |
  | en                            | es_UY              |        5 |           2094144 |   4208772 |                2 |
  | sr                            | en_RS              |        7 |           1331322 |   1538963 |                2 |
  | en                            | lt_LT              |        1 |           3789744 |   3789744 |                2 |
  | uk                            | en_RU              |        1 |           1742575 |   1742575 |                2 |
  | en                            | ru_SE              |        1 |           6421333 |   6421333 |                2 |
  | es                            | en_BE              |        1 |           2234231 |   2234231 |                2 |
  | fi                            | de_AT              |        1 |           2506791 |   2506791 |                2 |
  | it                            | en_GR              |        2 |           2266571 |   6393706 |                2 |
  | la                            | en_US              |        1 |           1465169 |   1465169 |                2 |
  | nl                            | en_NL              |       21 |           1609432 |   6966066 |                2 |
  | be                            | en_BY              |        1 |           1545106 |   1545106 |                2 |
  | pl                            | en_NL              |        1 |           1723064 |   1723064 |                2 |
  | cs                            | en_IE              |        1 |           1880529 |   1880529 |                2 |
  | de                            | zh_CN              |        2 |           4341210 |   4890651 |                2 |
  | ru                            | en_CY              |        6 |           1324406 |   1927775 |                2 |
  | en                            | fr_US              |        4 |            784987 |   2136123 |                2 |
  | ru                            | en_SH              |        1 |           1741023 |   1741023 |                2 |
  | en                            | sl_SI              |        1 |           2031965 |   2031965 |                2 |
  | sl                            | en_US              |        1 |           5014595 |   5014595 |                2 |
  | en                            | ru_UA              |        9 |           3544497 |   6359204 |                2 |
  | uk                            | en_UA              |       36 |           1322055 |   7185925 |                2 |
  | en                            | ja_AU              |        1 |           5523361 |   5523361 |                2 |
  | es                            | en_DO              |        5 |           2232633 |   5204477 |                2 |
  | fa                            | en_AE              |        3 |           3908747 |   4192905 |                2 |
  | fr                            | nl_NL              |        1 |          11803532 |  11803532 |                2 |
  | it                            | en_ID              |        1 |           1901494 |   1901494 |                2 |
  | ko                            | en_JP              |        1 |           1961745 |   1961745 |                2 |
  | nl                            | en_ES              |        1 |            705495 |    705495 |                2 |
  | pl                            | en_GB              |        1 |           1538096 |   1538096 |                2 |
  | cs                            | en_CZ              |      268 |           1275061 |   6763906 |                2 |
  | de                            | en_IN              |        1 |           3860605 |   3860605 |                2 |
  | ru                            | en_IT              |        3 |           1321247 |   1470149 |                2 |
  | en                            | th_TH              |        9 |            574444 |   6455959 |                2 |
  | ru                            | en_MD              |        2 |           1689431 |   1689591 |                2 |
  | en                            | uk_UA              |        7 |           2014360 |   3885890 |                2 |
  | sk                            | en_IE              |        1 |           4049209 |   4049209 |                2 |
  | en                            | fr_GB              |        3 |           2785057 |   4792321 |                2 |
  | tr                            | en_PL              |        1 |           4740243 |   4740243 |                2 |
  | en                            | sv_FI              |        1 |           4714373 |   4714373 |                2 |
  | es                            | en_HR              |        1 |           2053914 |   2053914 |                2 |
  | fa                            | en_US              |        2 |           1118775 |   3788173 |                2 |
  | fr                            | en_TR              |        1 |           6338176 |   6338176 |                2 |
  | it                            | en_CA              |        1 |           1563699 |   1563699 |                2 |
  | ko                            | en_US              |       10 |           1297342 |  15550233 |                2 |
  | pl                            | en_ES              |        1 |           1468123 |   1468123 |                2 |
  | ch                            | en_SG              |        1 |           1044693 |   1044693 |                2 |
  | de                            | en_NO              |        2 |           3764384 |   5787597 |                2 |
  | ru                            | en_BY              |       42 |           1319413 |   1991391 |                2 |
  | en                            | ja_JP              |       16 |            433543 |  14781323 |                2 |
  | ru                            | en_CN              |        1 |           1610476 |   1610476 |                2 |
  | en                            | es_PA              |        1 |           1956041 |   1956041 |                2 |
  | sk                            | en_CZ              |        2 |           1329018 |   1535749 |                2 |
  | en                            | es_AU              |        1 |           2637895 |   2637895 |                2 |
  | en                            | ru_IT              |        1 |           4241708 |   4241708 |                2 |
  | es                            | en_VE              |        8 |           1957955 |   4922728 |                2 |
  | es                            | de_DE              |        1 |          15065397 |  15065397 |                2 |
  | fr                            | en_UY              |        1 |           4561490 |   4561490 |                2 |
  | is                            | en_IS              |        6 |           1324037 |   4103318 |                2 |
  | nb                            | no_NO              |        7 |            575422 |   7042944 |                2 |
  | pl                            | en_CA              |        2 |           1330406 |   1661567 |                2 |
  | ca                            | en_AD              |        1 |           1464938 |   1464938 |                2 |
  | de                            | en_LI              |        1 |           3377000 |   3377000 |                2 |
  | ru                            | en_AU              |        3 |           1316288 |   1661710 |                2 |
  | en                            | zh_US              |       12 |            255537 |  13195012 |                2 |
  | ru                            | en_LU              |        1 |           1608351 |   1608351 |                2 |
  | en                            | ru_KR              |        1 |           1759103 |   1759103 |                2 |
  | sh                            | en_ME              |        2 |           1538932 |   1690780 |                2 |
  | en                            | hr_HR              |        3 |           2553766 |   4577529 |                2 |
  | te                            | en_QA              |        1 |           2256542 |   2256542 |                2 |
  | en                            | el_AE              |        1 |           4119560 |   4119560 |                2 |
  | es                            | en_PE              |       29 |           1748914 |   6420186 |                2 |
  | es                            | en_GB              |        4 |           5349966 |   6209363 |                2 |
  | fr                            | en_CH              |        1 |           4107011 |   4107011 |                2 |
  | id                            | en_ID              |       35 |           1321306 |   6458402 |                2 |
  | pl                            | en_NZ              |        1 |           1289993 |   1289993 |                2 |
  | bs                            | en_HR              |        2 |           1467728 |   1722002 |                2 |
  | de                            | en_IT              |        4 |           1978211 |   4085158 |                2 |
  | ru                            | en_GE              |        7 |           1267864 |   1779191 |                2 |
  | en                            | pt_BR              |       45 |            120515 |   4235916 |                2 |
  | ru                            | en_JP              |        1 |           1537903 |   1537903 |                2 |
  | en                            | fi_FI              |       21 |           1591161 |   6370537 |                2 |
  | en                            | sk_TR              |        1 |           2488796 |   2488796 |                2 |
  | ta                            | en_SA              |        1 |           6351289 |   6351289 |                2 |
  | en                            | nb_NO              |        5 |           4055534 |  11175949 |                2 |
  | es                            | en_UY              |       17 |           1663987 |   6389847 |                2 |
  | es                            | pt_CO              |        1 |           4902775 |   4902775 |                2 |
  | fr                            | it_IT              |        1 |           3905307 |   3905307 |                2 |
  | hu                            | en_US              |        7 |           1855417 |  11426775 |                2 |
  | ja                            | en                 |        4 |           5021772 |   5862879 |                2 |
  | bg                            | en_BE              |        1 |           1779169 |   1779169 |                2 |
  | de                            | en_BE              |        3 |           1880277 |   4097983 |                2 |
  | ru                            | en_US              |       81 |            439843 |  11426348 |                2 |
  | el                            | en_ES              |        1 |           1806617 |   1806617 |                2 |
  | ru                            | en_UZ              |        3 |           1472759 |   1609852 |                2 |
  | en                            | pt_PT              |        7 |           1534214 |   4426882 |                2 |
  | en                            | no_NO              |       12 |           2369166 |   4023024 |                2 |
  | ta                            | en_US              |        1 |            760622 |    760622 |                2 |
  | en                            | zh_HK              |        5 |           4001945 |   6400007 |                2 |
  | es                            | en_CA              |        2 |           4125294 |   5071635 |                2 |
  | fr                            | en_                |        5 |           3825055 |   4738031 |                2 |
  | hr                            | en_SE              |        1 |           1666127 |   1666127 |                2 |
  | ja                            | en_FI              |        1 |           4246655 |   4246655 |                2 |
  | mr                            | en_IN              |        1 |           1659790 |   1659790 |                2 |
  | ar                            | en_QA              |        2 |           3949170 |   4033769 |                2 |
  | bg                            | en_BG              |       67 |           1275363 |   6375444 |                2 |
  | de                            | es_MX              |        1 |           1312733 |   1312733 |                2 |
  | ro                            | en_RO              |       13 |           1687748 |   6395177 |                2 |
  | el                            | en_CY              |        4 |           1321480 |   1741643 |                2 |
  | ru                            | en_SE              |        4 |           1470044 |   6016568 |                2 |
  | en                            | ar_AE              |        1 |           1339713 |   1339713 |                2 |
  | en                            | nl_BE              |        3 |           2337687 |   3716766 |                2 |
  | sv                            | en                 |        1 |           5862848 |   5862848 |                2 |
  | en                            | zh_DK              |        1 |           3975832 |   3975832 |                2 |
  | es                            | en_CO              |       37 |           1612119 |   6360852 |                2 |
  | es                            | en_SA              |        1 |           3940723 |   3940723 |                2 |
  | fr                            | nl_BE              |        1 |           2086656 |   2086656 |                2 |
  | ja                            | en_KR              |        2 |           1621318 |   3905323 |                2 |
  | mk                            | en_                |        1 |           3944988 |   3944988 |                2 |
  | ar                            | en_KW              |        2 |           2003857 |   4343553 |                2 |
  | no                            | nb_NO              |        4 |           4173408 |  12047371 |                2 |
  | pt                            | en_BZ              |        1 |           1675746 |   1675746 |                2 |
  | de                            | en_US              |      144 |            132950 |  11431903 |                2 |
  | de                            | es_CA              |        1 |          14873392 |  14873392 |                2 |
  | ru                            | en_PL              |        4 |           1465897 |   5843700 |                2 |
  | en                            | ko_KR              |       29 |           1322325 |   6514170 |                2 |
  | ru                            | en                 |        1 |           5128860 |   5128860 |                2 |
  | en                            | ru_PT              |        1 |           2329112 |   2329112 |                2 |
  | sv                            | de_SE              |        1 |           4016407 |   4016407 |                2 |
  | en                            | bg_BG              |        9 |           3908169 |   4735740 |                2 |
  | vi                            | en_CA              |        1 |           1534268 |   1534268 |                2 |
  | es                            | en_ES              |      165 |            496636 |  10901786 |                2 |
  | es                            | fr_CL              |        1 |           3839348 |   3839348 |                2 |
  | fr                            | en_LB              |        1 |           1672297 |   1672297 |                2 |
  | hi                            | en_IN              |        1 |           2323731 |   2323731 |                2 |
  | it                            | en_HU              |        1 |           4990907 |   4990907 |                2 |
  | ar                            | en_CN              |        1 |           1478805 |   1478805 |                2 |
  | nn                            | en_US              |        2 |           4995334 |   5023830 |                2 |
  | pt                            | en_US              |        7 |            506414 |   5034518 |                2 |
  | da                            | en_NO              |        1 |           4236574 |   4236574 |                2 |
  | de                            | hr_HR              |        1 |           6388632 |   6388632 |                2 |
  | ru                            | tr_AZ              |        1 |           1443062 |   1443062 |                2 |
  | en                            | zh_CN              |       48 |           1242577 |   7413913 |                2 |
  | ru                            | en_IN              |        1 |           4196432 |   4196432 |                2 |
  | en                            | es_IT              |        2 |           2298576 |   3955364 |                2 |
  | sv                            | en_IL              |        1 |           1825660 |   1825660 |                2 |
  | en                            | ms_MY              |        1 |           3894252 |   3894252 |                2 |
  | es                            | en_CH              |        1 |           2240544 |   2240544 |                2 |
  | fr                            | en_QA              |        1 |           1240244 |   1240244 |                2 |
  | he                            | en_IL              |       41 |           1549863 |  15640884 |                2 |
  | it                            | en_AR              |        1 |           3815886 |   3815886 |                2 |
  | lt                            | en_NO              |        1 |           1472870 |   1472870 |                2 |
  | ar                            | en_US              |        3 |           1100047 |   4168780 |                2 |
  | nl                            | en_NZ              |        1 |           7064346 |   7064346 |                2 |
  | da                            | en_DK              |       60 |           1179553 |   6888196 |                2 |
  | de                            | en_TW              |        1 |           4967994 |   4967994 |                2 |
  | ru                            | en_BR              |        1 |           1329899 |   1329899 |                2 |
  | en                            | ru_RU              |       47 |           1173273 |  13097179 |                2 |
  | ru                            | en_                |       10 |           3729208 |   4868241 |                2 |
  | en                            | es_PE              |        4 |           2282419 |   4067389 |                2 |
  | sr                            | it_IT              |        1 |           4228384 |   4228384 |                2 |
  | en                            | nl_SE              |        1 |           3853551 |   3853551 |                2 |
  | uk                            | fr_FR              |        1 |           4890053 |   4890053 |                2 |
  | en                            | it_US              |        1 |          11840929 |  11840929 |                2 |
  | es                            | ru_RU              |        1 |           2237724 |   2237724 |                2 |
  | it                            | en_ES              |        1 |           2767767 |   2767767 |                2 |
  | nl                            | en_GR              |        1 |           1966938 |   1966938 |                2 |
  | pl                            | sv_SE              |        1 |           4860726 |   4860726 |                2 |
  | cs                            | en_GB              |        1 |           2434587 |   2434587 |                2 |
  | de                            | en_CL              |        1 |           4668047 |   4668047 |                2 |
  | ru                            | en_KR              |        4 |           1327923 |   1723880 |                2 |
  | en                            | nl_DE              |        1 |            833548 |    833548 |                2 |
  | ru                            | fr_BY              |        1 |           1987741 |   1987741 |                2 |
  | en                            | ar_ER              |        1 |           2075825 |   2075825 |                2 |
  | sr                            | en_BA              |        1 |           1329333 |   1329333 |                2 |
  | en                            | de_RO              |        1 |           3710452 |   3710452 |                2 |
  | uk                            | ru_UA              |       15 |           1600690 |   6351730 |                2 |
  | en                            | ru_IL              |        1 |           6386015 |   6386015 |                2 |
  | es                            | sv_ES              |        1 |           2233602 |   2233602 |                2 |
  | fi                            | en_FI              |       68 |           1557674 |   6385809 |                2 |
  | it                            | en_FR              |        3 |           2045670 |   4723890 |                2 |
  | ko                            | en_                |        2 |           3766665 |   4847271 |                2 |
  | nl                            | en_BE              |       15 |           1196433 |   7318860 |                2 |
  | pl                            | en_IL              |        1 |           1665754 |   1665754 |                2 |
  | cs                            | sk_SK              |        1 |           1573824 |   1573824 |                2 |
  | de                            | en_NZ              |        1 |           4302216 |   4302216 |                2 |
  | ru                            | en_NL              |        3 |           1324237 |   1742475 |                2 |
  | en                            | ja_US              |        2 |            754526 |  14844322 |                2 |
  | ru                            | en_ME              |        1 |           1721919 |   1721919 |                2 |
  | en                            | zh_TW              |       17 |           2021800 |   6515842 |                2 |
  | sl                            | en_SI              |        2 |           2073045 |   6003433 |                2 |
  | en                            | zh_GB              |        2 |           3543327 |   4597916 |                2 |
  | uk                            | en_AE              |        1 |           1319554 |   1319554 |                2 |
  | en                            | fi_US              |        1 |           5311109 |   5311109 |                2 |
  | zh                            | en_US              |        6 |           1393771 |  11431314 |                2 |
  | es                            | ca_ES              |       12 |           2232267 |  15344206 |                2 |
  | fa                            | en_MY              |        2 |           1195116 |   1740578 |                2 |
  | fr                            | en_DK              |        1 |           9621423 |   9621423 |                2 |
  | it                            | en_IN              |        1 |           1628601 |   1628601 |                2 |
  | ko                            | en_KR              |       51 |           1680337 |   6564867 |                2 |
  | pl                            | en_NO              |        4 |           1469416 |   1611118 |                2 |
  | cs                            | en_US              |        7 |            117279 |  11430972 |                2 |
  | de                            | en_CH              |        4 |           3840698 |   5599825 |                2 |
  | ru                            | en_AZ              |        5 |           1321014 |   1806437 |                2 |
  | en                            | fr_FR              |        8 |            439090 |  11878698 |                2 |
  | ru                            | en_RO              |        2 |           1660585 |   2330306 |                2 |
  | en                            | id_ID              |        5 |           2000735 |   6425172 |                2 |
  | sk                            | hu_CZ              |        1 |           3871856 |   3871856 |                2 |
  | en                            | zh_CA              |        4 |           2764945 |   4802570 |                2 |
  | tr                            | uk_UA              |        1 |           4138591 |   4138591 |                2 |
  | en                            | hu_RO              |        1 |           4673944 |   4673944 |                2 |
  | es                            | en_PY              |        1 |           2012057 |   2012057 |                2 |
  | eu                            | en_ES              |        2 |           1468278 |   1537922 |                2 |
  | fr                            | en_NL              |        1 |           5892448 |   5892448 |                2 |
  | it                            | en_US              |      221 |            413615 |  11431912 |                2 |
  | kn                            | en_IN              |        1 |           1606699 |   1606699 |                2 |
  | pl                            | en_BE              |        1 |           1465104 |   1465104 |                2 |
  | ca                            | en_IN              |        1 |           6753090 |   6753090 |                2 |
  | de                            | en_                |       13 |           3742077 |   4820632 |                2 |
  | ru                            | en_IL              |       52 |           1316827 |   5535248 |                2 |
  | en                            | de_US              |        3 |            428052 |   2619183 |                2 |
  | ru                            | en_SG              |        1 |           1609664 |   1609664 |                2 |
  | en                            | es_CO              |       10 |           1901329 |   6393521 |                2 |
  | sk                            | en_SK              |       27 |           1316650 |   6366820 |                2 |
  | en                            | zh_AU              |        2 |           2633829 |   4856663 |                2 |
  | th                            | en_NO              |        1 |           5108470 |   5108470 |                2 |
  | en                            | es_VE              |        1 |           4173120 |   4173120 |                2 |
  | es                            | en_KR              |        1 |           1955535 |   1955535 |                2 |
  | es                            | en_NI              |        1 |           6358866 |   6358866 |                2 |
  | fr                            | en_PL              |        2 |           4400865 |   4554854 |                2 |
  | ka                            | en_GE              |        7 |           1327566 |   1611063 |                2 |
  | pl                            | en_DK              |        3 |           1329449 |   1806450 |                2 |
  | ca                            | en_ES              |       45 |           1320555 |  10975280 |                2 |
  | de                            | en_RO              |        1 |           2034617 |   2034617 |                2 |
  | ru                            | en_CZ              |       10 |           1315377 |   6249788 |                2 |
  | en                            | es_US              |        6 |            250876 |  13353643 |                2 |
  | ru                            | en_VN              |        1 |           1607774 |   1607774 |                2 |
  | en                            | es_MX              |       36 |           1744224 |  15559058 |                2 |
  | sd                            | en_RU              |        1 |           1275354 |   1275354 |                2 |
  | en                            | fr_PT              |        1 |           2520533 |   2520533 |                2 |
  | te                            | en_IN              |        2 |           1478890 |   1779084 |                2 |
  | en                            | zh_MY              |        1 |           4095061 |   4095061 |                2 |
  | es                            | en_CR              |       13 |           1702438 |   6340170 |                2 |
  | es                            | en_SE              |        3 |           5260627 |   6893735 |                2 |
  | fr                            | sv_GB              |        1 |           4062636 |   4062636 |                2 |
  | hu                            | en_GB              |        1 |           5895621 |   5895621 |                2 |
  | ja                            | en_NZ              |        1 |           6711952 |   6711952 |                2 |
  | pl                            | en_US              |       17 |           1112604 |  11431513 |                2 |
  | bo                            | en_US              |        1 |           1323288 |   1323288 |                2 |
  | de                            | en_ES              |        3 |           1958992 |  14870808 |                2 |
  | ru                            | en_RU              |     5190 |           1137793 |   6399431 |                2 |
  | en                            | de_DE              |        4 |            104180 |   6521219 |                2 |
  | ru                            | en_AE              |        2 |           1537317 |   1609507 |                2 |
  | en                            | ro_RO              |       10 |           1581428 |   4740484 |                2 |
  | en                            | es_BO              |        1 |           2422538 |   2422538 |                2 |
  | ta                            | en_AE              |        1 |           4144532 |   4144532 |                2 |
  | en                            | hu_LT              |        1 |           4019919 |   4019919 |                2 |
  | es                            | en_IT              |        3 |           1654177 |   3888593 |                2 |
  | es                            | en_NO              |        1 |           4338080 |   4338080 |                2 |
  | fr                            | en_GB              |        3 |           3877672 |   6607328 |                2 |
  | hu                            | en_IE              |        1 |           1817870 |   1817870 |                2 |
  | ja                            | en_DZ              |        1 |           4501019 |   4501019 |                2 |
  | ms                            | en_MY              |        1 |           1531709 |   1531709 |                2 |
  | pa                            | en_IN              |        1 |           2241736 |   2241736 |                2 |
  | bg                            | en_US              |        1 |           1610432 |   1610432 |                2 |
  | de                            | en_DE              |       15 |           1793973 |   6813490 |                2 |
  | ro                            | en_IE              |        1 |           5003291 |   5003291 |                2 |
  | el                            | en_AT              |        1 |           1720126 |   1720126 |                2 |
  | ru                            | en_DE              |        3 |           1471364 |   5844924 |                2 |
  | en                            | da_NO              |        1 |           1521794 |   1521794 |                2 |
  | en                            | et_EE              |        3 |           2347870 |   3969985 |                2 |
  | sv                            | no_NO              |        1 |           6772001 |   6772001 |                2 |
  | en                            | fr_TR              |        1 |           3998989 |   3998989 |                2 |
  | es                            | sv_SE              |        1 |           4073053 |   4073053 |                2 |
  | fr                            | en_ES              |        2 |           3821051 |   6849377 |                2 |
  | hr                            | en_RS              |        1 |           1469228 |   1469228 |                2 |
  | ja                            | en_                |       11 |           3708658 |   4140385 |                2 |
  | ml                            | en_US              |        1 |           1742250 |   1742250 |                2 |
  | ar                            | en_IT              |        1 |           3895345 |   3895345 |                2 |
  | no                            | en_SE              |        1 |           6338383 |   6338383 |                2 |
  | de                            | en_GB              |        4 |           1193336 |  14873918 |                2 |
  | el                            | en_GR              |       58 |           1274834 |   6342677 |                2 |
  | ru                            | en_ES              |        5 |           1469470 |   5368114 |                2 |
  | en                            | de_AT              |       13 |           1323701 |   6971100 |                2 |
  | ru                            | en_HR              |        1 |           6330255 |   6330255 |                2 |
  | en                            | pl_PL              |       39 |           2333322 |   6368576 |                2 |
  | sv                            | en_NO              |        1 |           4577629 |   4577629 |                2 |
  | en                            | ru_IN              |        1 |           3944304 |   3944304 |                2 |
  | es                            | en_GT              |        4 |           1580300 |   2239865 |                2 |
  | es                            | en_BR              |        2 |           3848354 |   3888449 |                2 |
  | fr                            | en_BE              |       26 |           1746793 |   6627662 |                2 |
  | hi                            | en_AE              |        1 |           4785500 |   4785500 |                2 |
  | ja                            | en_JP              |      159 |            225922 |  15083689 |                2 |
  | mi                            | en_NZ              |        1 |           1539097 |   1539097 |                2 |
  | ar                            | en_PS              |        1 |           1825791 |   1825791 |                2 |
  | no                            | en_                |        1 |           3926532 |   3926532 |                2 |
  | pt                            | en_PT              |       10 |           1625589 |   4201064 |                2 |
  | da                            | en_NL              |        1 |           6897989 |   6897989 |                2 |
  | de                            | es_AR              |        1 |          14870967 |  14870967 |                2 |
  | ru                            | en_FR              |        1 |           1464233 |   1464233 |                2 |
  | en                            | ru_LV              |        2 |           1310526 |   2321514 |                2 |
  | ru                            | en_TH              |        1 |           4994982 |   4994982 |                2 |
  | en                            | el_GR              |       10 |           2327140 |   4735838 |                2 |
  | sv                            | en_TH              |        1 |           2103705 |   2103705 |                2 |
  | en                            | de_ES              |        1 |           3898529 |   3898529 |                2 |
  | vi                            | en_VN              |       15 |           1343216 |   4050565 |                2 |
  | es                            | en_US              |      111 |             58297 |  15556967 |                2 |
  | es                            | he_IL              |        1 |           3811981 |   3811981 |                2 |
  | fr                            | en_TN              |        1 |           1670338 |   1670338 |                2 |
  | hi                            | en_US              |        1 |           1768486 |   1768486 |                2 |
  | it                            | en_                |        1 |           4124128 |   4124128 |                2 |
  | lv                            | en_LV              |       10 |           1327141 |   1779325 |                2 |
  | ar                            | en_AE              |        3 |           1467808 |   4054857 |                2 |
  | nn                            | en_NO              |       10 |           1328738 |   5043306 |                2 |
  | da                            | en_MX              |        1 |           3830752 |   3830752 |                2 |
  | de                            | en_FR              |        1 |           6336221 |   6336221 |                2 |
  | ru                            | uk_UA              |       23 |           1399127 |  15640486 |                2 |
  | en                            | hu_HU              |       18 |           1239325 |  11200738 |                2 |
  | ru                            | en_GB              |        3 |           3917547 |   6337982 |                2 |
  | en                            | es_GT              |        2 |           2290631 |   4858462 |                2 |
  | sv                            | en_SE              |      203 |            481160 |   7056088 |                2 |
  | en                            | it_RO              |        1 |           3871474 |   3871474 |                2 |
  | es                            | en_AU              |        3 |           2239210 |  14955433 |                2 |
  | fr                            | en_FR              |       34 |           1064294 |  12022592 |                2 |
  | he                            | en_SG              |        1 |           1448397 |   1448397 |                2 |
  | it                            | en_GB              |        3 |           3718337 |   4975869 |                2 |
  | lt                            | en_LT              |       14 |           1316777 |   6130798 |                2 |
  | ar                            | en_GB              |        2 |            956670 |   1067071 |                2 |
  | nl                            | en_                |        2 |           4020016 |   4415715 |                2 |
  | pl                            | en_FI              |        1 |           6360865 |   6360865 |                2 |
  | cs                            | en_AU              |        1 |           5080690 |   5080690 |                2 |
  | de                            | en_HR              |        1 |           4867197 |   4867197 |                2 |
  | ru                            | en_EE              |       14 |           1328595 |   6369364 |                2 |
  | en                            | es_ES              |       38 |            977353 |  15501798 |                2 |
  | ru                            | en_NZ              |        1 |           2467162 |   2467162 |                2 |
  | en                            | es_CR              |        1 |           2095811 |   2095811 |                2 |
  | sr                            | en_ME              |        2 |           1468257 |   1469622 |                2 |
  | en                            | es_DO              |        1 |           3805321 |   3805321 |                2 |
  | uk                            | ru_RU              |        1 |           4016992 |   4016992 |                2 |
  | en                            | ca_ES              |       11 |           6445153 |  10954820 |                2 |
  | es                            | en_PR              |        2 |           2237581 |   2238933 |                2 |
  | fi                            | en_US              |        4 |           4466062 |   5390583 |                2 |
  | gl                            | pt_ES              |        2 |           1722711 |  10971239 |                2 |
  | it                            | en_RU              |        1 |           2266740 |   2266740 |                2 |
  | nl                            | en_MY              |        1 |           1956704 |   1956704 |                2 |
  | pl                            | cs_CZ              |        1 |           4667811 |   4667811 |                2 |
  | cs                            | en_SA              |        1 |           1938537 |   1938537 |                2 |
  | de                            | da_DK              |        1 |           4612930 |   4612930 |                2 |
  | ru                            | en_NO              |        4 |           1326443 |   1883948 |                2 |
  | en                            | da_DK              |       26 |            793422 |   6511558 |                2 |
  | ru                            | it_IT              |        1 |           1886612 |   1886612 |                2 |
  | en                            | tr_TR              |       38 |           2033935 |   6775823 |                2 |
  | sq                            | en_AL              |        1 |           1468095 |   1468095 |                2 |
  | en                            | pl_CZ              |        1 |           3704622 |   3704622 |                2 |
  | uk                            | en_US              |        2 |           1470290 |  10970566 |                2 |
  | en                            | nl_ES              |        1 |           6153623 |   6153623 |                2 |
  | es                            | en_BO              |        1 |           2232957 |   2232957 |                2 |
  | fa                            | en_CA              |        1 |           3942920 |   3942920 |                2 |
  | it                            | en_TH              |        1 |           1996304 |   1996304 |                2 |
  | ko                            | en_RU              |        1 |           2493511 |   2493511 |                2 |
  | nl                            | en_US              |      402 |            716935 |  11431917 |                2 |
  | pl                            | en_IE              |        2 |           1538798 |   1663273 |                2 |
  | cs                            | en_SK              |        3 |           1464302 |  15180491 |                2 |
  | de                            | en_AE              |        2 |           4190131 |   4341100 |                2 |
  | ru                            | en_AT              |        2 |           1321360 |   1661206 |                2 |
  | en                            | fr_CA              |       15 |            599083 |   5691776 |                2 |
  | ru                            | en_IE              |        1 |           1721839 |   1721839 |                2 |
  | en                            | pt_BE              |        1 |           2018479 |   2018479 |                2 |
  | sk                            | en_US              |        2 |           4999492 |   5019361 |                2 |
  | en                            | pt_AO              |        1 |           3396151 |   3396151 |                2 |
  | tr                            | en_UA              |        1 |           5530756 |   5530756 |                2 |
  | en                            | de_AU              |        2 |           5273905 |  14931973 |                2 |
  | new                           | ne_IN              |        1 |           1243806 |   1243806 |                3 |
  | bar                           | de_US              |        1 |           1660892 |   1660892 |                3 |
  | chy                           | ch_ID              |        1 |           1971858 |   1971858 |                3 |
  | yue                           | yue_CN             |      103 |           6611176 |  15633318 |                3 |
  | bar                           | ba_BR              |        1 |            247919 |    247919 |                3 |
  | yue                           | yu_CA              |        2 |           2346280 |   2435043 |                3 |
  | nan                           | na_TW              |        1 |           1179959 |   1179959 |                3 |
  | ast                           | as_US              |        1 |           4846217 |   4846217 |                3 |
  | sco                           | sc_BG              |        1 |           2157329 |   2157329 |                3 |
  | mwl                           | mw_ES              |        1 |           4662737 |   4662737 |                3 |
  | arz                           | ar_RO              |        1 |           1749722 |   1749722 |                3 |
  | pam                           | pa_US              |        1 |            346682 |    346682 |                3 |
  | eml                           | em_IT              |        1 |           1647308 |   1647308 |                3 |
  | scn                           | sc_US              |        2 |           1336481 |   5038967 |                3 |
  | yue                           | yu_US              |        3 |            139479 |   6568942 |                3 |
  | hsb                           | hs_AR              |        1 |           1788020 |   1788020 |                3 |
  | arz                           | ar_AE              |        1 |           1361365 |   1361365 |                3 |
  | nrm                           | nr_CA              |        1 |           1501951 |   1501951 |                3 |
  | wuu                           | wu_CN              |        2 |           6904604 |  10985134 |                3 |
  | lzh                           | lz_CN              |        2 |            765738 |    765755 |                3 |
  | vec                           | ve_IT              |        2 |           1494085 |   1613975 |                3 |
  | eng                           | en_US              |        2 |           1047730 |   5455435 |                3 |
  | pnb                           | en_AE              |        1 |           1470884 |   1470884 |                3 |
  | lmo                           | lm_IT              |        3 |            156743 |   1478385 |                3 |
  | ang                           | an_GB              |        1 |           2299403 |   2299403 |                3 |
  | ger                           | ge_DE              |        1 |          14897301 |  14897301 |                3 |
  | ang                           | an_LT              |        1 |           1543010 |   1543010 |                3 |
  | als                           | al_CH              |        3 |            236876 |    351997 |                3 |
  | bar                           | ba_US              |        1 |           1832018 |   1832018 |                3 |
  | yue                           | yue_US             |        1 |           9505885 |   9505885 |                3 |
  | bar                           | ba_IT              |        1 |           1183482 |   1183482 |                3 |
  | tpi                           | tp_US              |        1 |           1677482 |   1677482 |                3 |
  | yue                           | yu_GB              |        1 |           6458081 |   6458081 |                3 |
  | kaa                           | ka_US              |        1 |           2402130 |   2402130 |                3 |
  | ast                           | ast_ES             |        1 |          10905073 |  10905073 |                3 |
  | jbo                           | jb_BE              |        1 |           2144944 |   2144944 |                3 |
  | nah                           | na_MX              |        1 |           1985342 |   1985342 |                3 |
  | ast                           | as_ES              |        2 |           2531552 |  10637552 |                3 |
  | sco                           | en_AU              |        1 |           1663767 |   1663767 |                3 |
  | yue                           | yu_CN              |       26 |            281075 |  13999039 |                3 |
  | mwl                           | mw_BR              |        1 |           1956769 |   1956769 |                3 |
  | arz                           | ar_IL              |        1 |           1646042 |   1646042 |                3 |
  | pag                           | pa_US              |        1 |           1402083 |   1402083 |                3 |
  | scn                           | sc_BR              |        1 |            508782 |    508782 |                3 |
  | xmf                           | xm_US              |        1 |           1491932 |   1491932 |                3 |
  | nrm                           | nr_US              |        1 |           1320602 |   1320602 |                3 |
  | sah                           | sa_RU              |        1 |           1560832 |   1560832 |                3 |
  | war                           | wa_US              |        1 |           4575552 |   4575552 |                3 |
  | hif                           | hi_AU              |        1 |           1275806 |   1275806 |                3 |
  | lzh                           | lz_US              |        2 |            500933 |    629207 |                3 |
  | vec                           | ve_HR              |        1 |           1396817 |   1396817 |                3 |
  | en0                           | en_CA              |        1 |           7534225 |   7534225 |                3 |
  | pms                           | pm_IT              |        3 |            242394 |    484960 |                3 |
  | haw                           | ha_US              |        1 |           1212101 |   1212101 |                3 |
  | lbe                           | lb_RU              |        2 |           4187872 |   4197677 |                3 |
  | ang                           | an_NL              |        2 |           2176366 |   4907170 |                3 |
  | fur                           | fu_IT              |        1 |           7127095 |   7127095 |                3 |
  | ang                           | an_SA              |        1 |           1477068 |   1477068 |                3 |
  | als                           | al_DE              |        3 |            208061 |    440918 |                3 |
  | new                           | ne_US              |        1 |           2231326 |   2231326 |                3 |
  | bar                           | ba_AT              |        3 |           1672705 |   3965648 |                3 |
  | yue                           | yue_HK             |        2 |           8777411 |  14873434 |                3 |
  | nds                           | nd_NL              |        1 |           2148756 |   2148756 |                3 |
  | bar                           | ba_DE              |        6 |            364711 |   2149179 |                3 |
  | yue                           | yu_AU              |        1 |           3687753 |   3687753 |                3 |
  | ilo                           | il_US              |        1 |           1418739 |   1418739 |                3 |
  | nan                           | na_IN              |        1 |           1345611 |   1345611 |                3 |
  | ast                           | as_GB              |        1 |           5006415 |   5006415 |                3 |
  | nah                           | na_US              |        1 |           1340050 |   1340050 |                3 |
  | arz                           | ar_KW              |        1 |           4006627 |   4006627 |                3 |
  | sco                           | sc_US              |        6 |           1274766 |  15540283 |                3 |
  | yue                           | yu_HK              |       22 |            152949 |  15067021 |                3 |
  | arz                           | ar_SA              |        2 |           1643389 |   4236105 |                3 |
  | scn                           | sc_IT              |        2 |            238909 |   1651826 |                3 |
  | wuu                           | wuu_CN             |        1 |           9526058 |   9526058 |                3 |
  | vls                           | vl_BE              |        1 |           1680199 |   1680199 |                3 |
  | 恩                           | 恩_CN             |        1 |           8460984 |   8460984 |                3 |
  | pnb                           | pn_IN              |        1 |           1762486 |   1762486 |                3 |
  | vec                           | it_IT              |        2 |           1329782 |   1536542 |                3 |
  | lad                           | la_US              |        1 |           1226502 |   1226502 |                3 |
  | ang                           | an_US              |        5 |           2084253 |  15149808 |                3 |
  | fur                           | fu_AT              |        1 |           1654523 |   1654523 |                3 |
  | ang                           | an_AU              |        1 |           1391405 |   1391405 |                3 |
  | pt-br                         | en                 |        1 |           6126108 |   6126108 |                5 |
  | pt-br                         | en_BR              |       40 |           1716488 |   4559572 |                5 |
  | pt-br                         | pt_ES              |        4 |           4124034 |  11278281 |                5 |
  | pt-br                         | pt_PE              |        2 |           3627271 |   6598373 |                5 |
  | pt-br                         | pt_AR              |        3 |           2081243 |  11404820 |                5 |
  | pt-br                         | pt_GB              |        9 |           1738037 |  11385527 |                5 |
  | pt-br                         | en_BZ              |        2 |           1699381 |   1708590 |                5 |
  | pt-br                         | pt_AE              |        1 |          11404398 |  11404398 |                5 |
  | pt-br                         | pt_AO              |        2 |          11363256 |  11363709 |                5 |
  | pt-br                         | pt_TH              |        1 |          11296480 |  11296480 |                5 |
  | pt-br                         | pt_NL              |        4 |           6983681 |  15020918 |                5 |
  | pt-br                         | pt_AU              |        2 |           6213163 |   6338452 |                5 |
  | pt-br                         | pt_CH              |        1 |           4652118 |   4652118 |                5 |
  | pt-br                         | pt_IT              |        4 |           4099527 |  11405001 |                5 |
  | pt-br                         | pt_                |        1 |           2248187 |   2248187 |                5 |
  | pt-br                         | pt_FR              |        1 |           1750828 |   1750828 |                5 |
  | pt-br                         | pt_JP              |        5 |           1698779 |  11313375 |                5 |
  | pt-br                         | pt_MX              |        1 |          11404201 |  11404201 |                5 |
  | pt-br                         | pt_PL              |        1 |          11360349 |  11360349 |                5 |
  | pt-br                         | pt_SE              |        1 |          11284125 |  11284125 |                5 |
  | pt-br                         | pt_MY              |        1 |           6812710 |   6812710 |                5 |
  | pt-br                         | pt_DK              |        1 |           4528822 |   4528822 |                5 |
  | pt-br                         | pt_DE              |        2 |           4094321 |   4148526 |                5 |
  | pt-br                         | en_US              |        6 |           2120547 |  11431848 |                5 |
  | pt-br                         | pt_CA              |        6 |           1740181 |  11405012 |                5 |
  | pt-br                         | pt_PT              |       18 |           1705347 |  15486429 |                5 |
  | pt-br                         | pt_US              |       84 |            534392 |  14594323 |                5 |
  | pt-br                         | pt_DO              |        1 |          11409290 |  11409290 |                5 |
  | pt-br                         | pt_IE              |        1 |          11404093 |  11404093 |                5 |
  | pt-br                         | pt_UY              |        1 |          11340184 |  11340184 |                5 |
  | pt-br                         | pt_HK              |        1 |          11228124 |  11228124 |                5 |
  | pt-br                         | pt_CO              |        3 |           6598355 |  11405097 |                5 |
  | pt-br                         | pt_BE              |        2 |           4708676 |  11404145 |                5 |
  | zh-hans                       | zh_ID              |        1 |            618961 |    618961 |                7 |
  | zh-hans                       | zh_ET              |        1 |          13576179 |  13576179 |                7 |
  | zh-hant                       | zh_RU              |        5 |           1187422 |   3915404 |                7 |
  | zh-hant                       | zh_BZ              |        1 |           2336413 |   2336413 |                7 |
  | zh-hans                       | zh_IE              |        2 |            567454 |   1036489 |                7 |
  | zh-hans                       | en_GB              |        1 |           6232153 |   6232153 |                7 |
  | zh-hant                       | zh_MO              |       43 |            644944 |  14809962 |                7 |
  | zh-hant                       | zh_HU              |        1 |           2139348 |   2139348 |                7 |
  | zh-hans                       | zh_NO              |        6 |            557169 |  15460420 |                7 |
  | zh-hans                       | fr_CN              |        1 |           5080637 |   5080637 |                7 |
  | zh-hant                       | zh_CO              |        1 |            468763 |    468763 |                7 |
  | zh-hant                       | en_MY              |        4 |           1973059 |   2472463 |                7 |
  | zh-hans                       | zh_TR              |        2 |            554742 |    568180 |                7 |
  | zh-hans                       | zh_                |      188 |           4750482 |   4985319 |                7 |
  | zh-hant                       | zh_FI              |        5 |            392697 |   2432706 |                7 |
  | zh-hant                       | en_TR              |        1 |           1906754 |   1906754 |                7 |
  | zh-hans                       | zh_NZ              |       36 |            312914 |   6679482 |                7 |
  | zh-hans                       | zh_CR              |        2 |           4403177 |   6245944 |                7 |
  | zh-hant                       | zh_ID              |        9 |            290122 |   2029585 |                7 |
  | zh-hant                       | en_CA              |        4 |           1796614 |   2447917 |                7 |
  | zh-hans                       | zh_CL              |        4 |            162985 |   3810124 |                7 |
  | zh-hans                       | en_                |        2 |           3434195 |   4236957 |                7 |
  | zh-hant                       | zh_NZ              |      165 |            190176 |  15618253 |                7 |
  | zh-hant                       | zh_PH              |        2 |           1600331 |   1609656 |                7 |
  | zh-hans                       | zh_SG              |       84 |            152824 |  14869315 |                7 |
  | zh-hans                       | zh_SA              |        1 |           2401030 |   2401030 |                7 |
  | zh-hant                       | zh_XX              |        1 |            122395 |    122395 |                7 |
  | zh-hant                       | zh_KR              |        6 |           1516700 |  10350003 |                7 |
  | zh-hans                       | zh_MY              |       49 |            120135 |  15634027 |                7 |
  | zh-hans                       | en_CN              |      124 |           1590536 |   6673886 |                7 |
  | zh-hant                       | zh_CA              |      345 |            115714 |  14792050 |                7 |
  | zh-hant                       | zh_EC              |        1 |           1465051 |   1465051 |                7 |
  | zh-hant                       | zh_PL              |        1 |          15111797 |  15111797 |                7 |
  | zh-hans                       | zh_US              |     1246 |            113896 |  15623410 |                7 |
  | zh-hans                       | zh_RU              |        2 |           1121746 |   2422829 |                7 |
  | zh-hant                       | zh_SG              |      186 |            112141 |  15510412 |                7 |
  | zh-hant                       | zh_SV              |        1 |           1382121 |   1382121 |                7 |
  | zh-hant                       | zh_HANS            |        1 |           6748035 |   6748035 |                7 |
  | zh-hans                       | zh_FR              |       23 |            112879 |  13941057 |                7 |
  | zh-hans                       | zh_PA              |        1 |            876145 |    876145 |                7 |
  | zh-hant                       | zh_MY              |      145 |            111254 |  15621430 |                7 |
  | zh-hant                       | en_SG              |        1 |           1225194 |   1225194 |                7 |
  | zh-hant                       | zh_CM              |        1 |           4484239 |   4484239 |                7 |
  | zh-hans                       | zh_CN              |    14548 |            106410 |  15638754 |                7 |
  | zh-hans                       | zh_BW              |        1 |            709086 |    709086 |                7 |
  | zh-hant                       | zh_DE              |       17 |            105655 |  15540270 |                7 |
  | zh-hant                       | zh_NO              |       10 |           1212062 |  12185133 |                7 |
  | zh-hant                       | en_                |        1 |           3434087 |   3434087 |                7 |
  | zh-hans                       | zh_CO              |        1 |            590101 |    590101 |                7 |
  | zh-hans                       | en_HK              |        1 |          10971564 |  10971564 |                7 |
  | zh-hant                       | en_CN              |       66 |           1170907 |  10970726 |                7 |
  | zh-hant                       | zh_KY              |        1 |           2331785 |   2331785 |                7 |
  | zh-hans                       | zh_AE              |        1 |            562591 |    562591 |                7 |
  | zh-hans                       | en                 |        3 |           6197078 |   6224772 |                7 |
  | zh-hant                       | zh_IT              |       37 |            632626 |  15585016 |                7 |
  | zh-hant                       | zh_IS              |        1 |           2038530 |   2038530 |                7 |
  | zh-hans                       | zh_SI              |        1 |            556580 |    556580 |                7 |
  | zh-hans                       | en_AU              |        1 |           5048972 |   5048972 |                7 |
  | zh-hant                       | zh_EE              |        1 |            463043 |    463043 |                7 |
  | zh-hant                       | en_GB              |        1 |           1950257 |   1950257 |                7 |
  | zh-hans                       | zh_ZA              |        2 |            407706 |    619815 |                7 |
  | zh-hans                       | en_TW              |        1 |           4667148 |   4667148 |                7 |
  | zh-hant                       | zh_ZA              |       10 |            389732 |   2365962 |                7 |
  | zh-hant                       | en_HK              |        9 |           1814743 |  10971562 |                7 |
  | zh-hans                       | zh_HU              |        3 |            248870 |  11418360 |                7 |
  | zh-hans                       | ja_CN              |        1 |           4214612 |   4214612 |                7 |
  | zh-hant                       | zh_JP              |       85 |            263514 |  15569765 |                7 |
  | zh-hant                       | zh_IN              |        3 |           1675052 |  11201425 |                7 |
  | zh-hans                       | zh_ES              |        9 |            161807 |  14793779 |                7 |
  | zh-hans                       | en_CA              |        2 |           2537045 |   4804641 |                7 |
  | zh-hant                       | zh_MX              |       10 |            158560 |   1391798 |                7 |
  | zh-hant                       | ja_TW              |        1 |           1567422 |   1567422 |                7 |
  | zh-hans                       | zh_SE              |       22 |            126887 |  15519574 |                7 |
  | zh-hans                       | zh_MO              |        3 |           1864641 |  14804375 |                7 |
  | zh-hant                       | zh_AU              |      301 |            121733 |  15576165 |                7 |
  | zh-hant                       | zh_AE              |        2 |           1502891 |   1590447 |                7 |
  | zh-hans                       | zh_TW              |      235 |            114178 |  15644878 |                7 |
  | zh-hans                       | zh_PL              |        1 |           1465189 |   1465189 |                7 |
  | zh-hant                       | zh_FR              |       16 |            115498 |  13949528 |                7 |
  | zh-hant                       | zh_NL              |       43 |           1395095 |  15385835 |                7 |
  | zh-hant                       | en_NL              |        1 |          10950232 |  10950232 |                7 |
  | zh-hans                       | zh_CA              |      212 |            113731 |  15082598 |                7 |
  | zh-hans                       | en_US              |       10 |           1118354 |   6351990 |                7 |
  | zh-hant                       | zh_HK              |     1620 |            111996 |  15633546 |                7 |
  | zh-hant                       | zh_PR              |        1 |           1320850 |   1320850 |                7 |
  | zh-hant                       | en_JP              |        1 |           6524290 |   6524290 |                7 |
  | zh-hans                       | zh_JP              |       61 |            112356 |  14910639 |                7 |
  | zh-hans                       | zh_KR              |        1 |            860195 |    860195 |                7 |
  | zh-hant                       | zh_TW              |     5635 |            111175 |  15584473 |                7 |
  | zh-hant                       | zh_HT              |        1 |           1222694 |   1222694 |                7 |
  | zh-hant                       | zh_CR              |        2 |           4234498 |   6526911 |                7 |
  | zh-hans                       | zh_BE              |        6 |            662523 |  11415011 |                7 |
  | zh-hant                       | zh_ES              |       23 |           1207609 |  15372228 |                7 |
  | zh-hant                       | ja_HK              |        1 |           2467487 |   2467487 |                7 |
  | zh-hans                       | zh_CZ              |        1 |            571549 |    571549 |                7 |
  | zh-hans                       | zh_GR              |        1 |           6387199 |   6387199 |                7 |
  | zh-hant                       | zh_AF              |        1 |            940016 |    940016 |                7 |
  | zh-hant                       | zh_                |       50 |           2247656 |   4981196 |                7 |
  | zh-hans                       | zh_IT              |        8 |            559649 |  11713841 |                7 |
  | zh-hans                       | en_NZ              |        1 |           6075928 |   6075928 |                7 |
  | zh-hant                       | zh_AT              |       10 |            486895 |   2471891 |                7 |
  | zh-hant                       | en_MO              |        1 |           1997411 |   1997411 |                7 |
  | zh-hans                       | zh_MX              |        8 |            554865 |   2466865 |                7 |
  | zh-hans                       | zh_AM              |        1 |           4906891 |   4906891 |                7 |
  | zh-hant                       | zh_SE              |       19 |            433408 |  13867592 |                7 |
  | zh-hant                       | zh_GT              |        1 |           1948373 |   1948373 |                7 |
  | zh-hans                       | zh_FI              |        3 |            352157 |   2144218 |                7 |
  | zh-hans                       | zh_CH              |        1 |           4509690 |   4509690 |                7 |
  | zh-hant                       | zh_IE              |        7 |            333859 |  12305513 |                7 |
  | zh-hant                       | zh_SK              |        2 |           1812645 |  11009376 |                7 |
  | zh-hans                       | zh_XX              |        1 |            197583 |    197583 |                7 |
  | zh-hans                       | zh_QA              |        1 |           4185369 |   4185369 |                7 |
  | zh-hant                       | zh_CH              |        2 |            234628 |   1850164 |                7 |
  | zh-hant                       | zh_DK              |        2 |           1666732 |   2020255 |                7 |
  | zh-hans                       | zh_NL              |       19 |            157749 |  14873323 |                7 |
  | zh-hans                       | zh_TH              |        1 |           2452468 |   2452468 |                7 |
  | zh-hant                       | zh_AR              |        2 |            139370 |    210687 |                7 |
  | zh-hant                       | zh_SA              |        3 |           1536925 |   1981136 |                7 |
  | zh-hans                       | zh_HK              |      243 |            123987 |  15581961 |                7 |
  | zh-hans                       | zh_BY              |        1 |           1751835 |   1751835 |                7 |
  | zh-hant                       | zh_BR              |        6 |            115729 |   4569279 |                7 |
  | zh-hant                       | zh_PT              |        1 |           1496689 |   1496689 |                7 |
  | zh-hans                       | zh_DE              |       22 |            113898 |   6162853 |                7 |
  | zh-hans                       | zh_AT              |        7 |           1310269 |  15395784 |                7 |
  | zh-hant                       | zh_GB              |       73 |            115333 |  15002818 |                7 |
  | zh-hant                       | zh_TH              |        5 |           1387719 |   6486547 |                7 |
  | zh-hant                       | zh_VN              |        1 |          10793704 |  10793704 |                7 |
  | zh-hans                       | zh_GB              |       90 |            113076 |  12428082 |                7 |
  | zh-hans                       | zh_AF              |        1 |            901004 |    901004 |                7 |
  | zh-hant                       | zh_CN              |     5935 |            111334 |  15639840 |                7 |
  | zh-hant                       | en_TW              |       12 |           1251768 |  10932598 |                7 |
  | zh-hans                       | zh_AU              |      124 |            111177 |  15620452 |                7 |
  | zh-hans                       | zh_DK              |        3 |            774502 |   2495416 |                7 |
  | zh-hant                       | zh_US              |     1929 |            111104 |  15602314 |                7 |
  | zh-hant                       | zh_BE              |        4 |           1217135 |   8110219 |                7 |
  | zh-hant                       | zh_UA              |        1 |           3718417 |   3718417 |                7 |
  | be-taras                      | be_PL              |        1 |           6347904 |   6347904 |                8 |
  | be-taras                      | be_NO              |        1 |           1827259 |   1827259 |                8 |
  | be-taras                      | be_GB              |        1 |            855816 |    855816 |                8 |
  | be-taras                      | be_CZ              |        1 |            429309 |    429309 |                8 |
  | be-taras                      | be_CA              |        1 |           3725037 |   3725037 |                8 |
  | be-taras                      | be_RU              |        6 |           1574582 |   6337836 |                8 |
  | be-taras                      | be_BY              |        8 |            850329 |   3717041 |                8 |
  | be-taras                      | be_DE              |        1 |            376379 |    376379 |                8 |
  | be-taras                      | be_EE              |        1 |           6425752 |   6425752 |                8 |
  | be-taras                      | be_ES              |        1 |           2418133 |   2418133 |                8 |
  | be-taras                      | be_LU              |        1 |           1163949 |   1163949 |                8 |
  | be-taras                      | be_IE              |        1 |            490798 |    490798 |                8 |
  | be-taras                      | be_US              |        3 |            126697 |   6341356 |                8 |
  +-------------------------------+--------------------+----------+-------------------+-----------+------------------+
  780 rows in set (46.94 sec)

   */
  // Note this line is in the select above but NOT below as we
  // want to override '_US' etc
  // AND c.preferred_language NOT LIKE '\_%\'
  $queries[] = "
    UPDATE civicrm_contact c
    LEFT JOIN temp_civicrm_contact t
    ON c.id = t.id
    SET c.preferred_language = REPLACE(t.calculated_preferred_language, '-', '_')
    WHERE 
    c.preferred_language <> REPLACE(calculated_preferred_language, '-', '_')
    AND calculated_preferred_language IS NOT NULL
    AND calculated_preferred_language <> LEFT(c.preferred_language, 2)
  ";

  foreach ($queries as $query) {
    CRM_Core_DAO::executeQuery($query);
  }
}

/**
 * The following queries are useful for analysis
 *
SELECT contact_id, trxn_id, receive_date, source , language
FROM civicrm_contribution contrib
LEFT JOIN temp_contribution_tracking ct ON ct.contribution_id = contrib.id
WHERE contact_id IN (SELECT id FROM temp_civicrm_contact WHERE calculated_preferred_language is not null AND preferred_language is null)
ORDER BY contact_id DESC;


SELECT s.contact_id, calculated_preferred_language, s.preferred_language
FROM  temp_civicrm_contact c
LEFT JOIN silverpop.silverpop_export_staging s ON c.id = s.contact_id
WHERE s.preferred_language <> calculated_preferred_language
ORDER BY s.contact_id DESC;
*/
