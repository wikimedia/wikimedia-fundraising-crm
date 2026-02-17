<?php
use CRM_Deduper_ExtensionUtil as E;
use League\Csv\Reader;

/**
 * Collection of upgrade steps.
 */
class CRM_Deduper_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
   * @throws \League\Csv\Exception
   */
  public function install() {
    $this->executeSqlFile('sql/auto_install.sql');
    $this->executeSqlFile('sql/family_names.sql');
    $this->prePopulateNameMatchTable();
  }

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   *
  public function postInstall() {
    $customFieldId = civicrm_api3('CustomField', 'getvalue', array(
      'return' => array("id"),
      'name' => "customFieldCreatedViaManagedHook",
    ));
    civicrm_api3('Setting', 'create', array(
      'myWeirdFieldSetting' => array('id' => $customFieldId, 'weirdness' => 1),
    ));
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   */
  public function uninstall() {
   $this->executeSqlFile('sql/auto_uninstall.sql');
  }

  /**
   * Example: Run a simple query when a module is enabled.
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a simple query when a module is disabled.
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4200() {
    $this->ctx->log->info('Applying update 4200');
    CRM_Core_DAO::executeQuery("
CREATE TABLE IF NOT EXISTS `civicrm_contact_name_pair` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique ID',
  `name_a` varchar(64) NOT NULL DEFAULT '',
  `name_b` varchar(64) NOT NULL DEFAULT '',
  `is_name_b_nickname` tinyint(10) NOT NULL DEFAULT '0',
  `is_name_b_inferior` tinyint(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `name_a` (`name_a`),
  KEY `name_b` (`name_b`),
  KEY `is_name_b_nickname` (`is_name_b_nickname`),
  KEY `is_name_b_inferior` (`is_name_b_inferior`)
) ENGINE=InnoDB
");

    $this->prePopulateNameMatchTable();
    return TRUE;
  }

  /**
   * Pre-populate name match table with common mis-spellings & alternatives.
   */
  public function prePopulateNameMatchTable() {
    $reader = Reader::from(__DIR__ . '/name_matches.csv', 'r');
    $reader->setHeaderOffset(0);
    foreach ($reader as $row) {
      CRM_Core_DAO::executeQuery(
        'INSERT INTO civicrm_contact_name_pair
        (name_a, name_b, is_name_b_nickname, is_name_b_inferior)
         VALUES (%1, %2, %3, %4)
      ', [
        1 => [$row['name_a'], 'String'],
        2 => [$row['name_b'], 'String'],
        3 => [$row['is_name_b_nickname'], 'Integer'],
        4 => [$row['is_name_b_inferior'], 'Integer'],
      ]);
    }

  }

  /**
   * Load Japanese names.
   *
   * Note we are just putting these in their own table for now.
   * Next we will decide how to use in deduper next round.
   * In particular we are likely to move to the name_pair table
   * with an is_family_name column but perhaps we should
   * wait until handling for that exists before doing so.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4300() {
    $this->ctx->log->info('Applying update 4300');
    CRM_Core_DAO::executeQuery("
CREATE TABLE IF NOT EXISTS `civicrm_contact_name_pair_family` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique ID',
  `name_a` varchar(64) NOT NULL DEFAULT '',
  `name_b` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `name_a` (`name_a`),
  KEY `name_b` (`name_b`)
)  ENGINE=InnoDB;

");

    $this->prePopulateFamilyNameMatchTable();
    return TRUE;
  }

  /**
   * Load Japanese names.
   *
   * Note we are just putting these in their own table for now.
   * Next we will decide how to use in deduper next round.
   * In particular we are likely to move to the name_pair table
   * with an is_family_name column but perhaps we should
   * wait until handling for that exists before doing so.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4310() {
    $this->ctx->log->info('Applying update 4310');
    CRM_Core_DAO::executeQuery("
     ALTER TABLE `civicrm_contact_name_pair_family`
     ADD COLUMN is_most_common_form TINYINT(4) DEFAULT 0 COMMENT 'Is this the most common way to write this name?',
     ADD COLUMN is_active TINYINT(4) DEFAULT 1 COMMENT 'Is this variant active?'
   ");

    // I figured adding is active might be helpful although I'm actually going to remove
    // the 'Harrisons' from the list rather than just de-activate them.
    // Although I will de-activate the 'one name that seems to be both most_common
    // and a possible Harrison.
    CRM_Core_DAO::executeQuery("
      UPDATE `civicrm_contact_name_pair_family` SET is_active = 1
    ");

    // Remove duplicate names - most common names turned out to be listed
    // in both columns
    CRM_Core_DAO::executeQuery("
      DELETE b FROM `civicrm_contact_name_pair_family` a
      LEFT JOIN civicrm_contact_name_pair_family b
      ON b.name_a = a.name_a AND a.name_b = b.name_b AND a.id < b.id
   ");

    // Mark the most common form - we are not actually doing something with this data
    // but it seems information we should keep.
    CRM_Core_DAO::executeQuery("
      UPDATE `civicrm_contact_name_pair_family` a
      SET is_most_common_form = 1
      WHERE
(name_a = 'Sato' AND name_b = '佐藤') OR (name_a = 'Suzuki' AND name_b = '鈴木') OR (name_a = 'Takahashi' AND name_b = '高橋') OR (name_a = 'Tanaka' AND name_b = '田中') OR (name_a = 'Watanabe' AND name_b = '渡辺') OR (name_a = 'Ito' AND name_b = '伊藤') OR (name_a = 'Yamamoto' AND name_b = '山本') OR (name_a = 'Nakamura' AND name_b = '中村') OR (name_a = 'Kobayashi' AND name_b = '小林') OR (name_a = 'Kato' AND name_b = '加藤') OR (name_a = 'Yoshida' AND name_b = '吉田') OR (name_a = 'Yamada' AND name_b = '山田') OR (name_a = 'Sasaki' AND name_b = '佐々木') OR (name_a = 'Yamaguchi' AND name_b = '山口') OR (name_a = 'Saito' AND name_b = '斎藤') OR (name_a = 'Matsumoto' AND name_b = '松本') OR (name_a = 'Inoue' AND name_b = '井上') OR (name_a = 'Kimura' AND name_b = '木村') OR (name_a = 'Hayashi' AND name_b = '林') OR (name_a = 'Shimizu' AND name_b = '清水') OR (name_a = 'Yamazaki' AND name_b = '山崎') OR (name_a = 'Mori' AND name_b = '森') OR (name_a = 'Abe' AND name_b = '阿部') OR (name_a = 'Ikeda' AND name_b = '池田') OR (name_a = 'Hashimoto' AND name_b = '橋本') OR (name_a = 'Yamashita' AND name_b = '山下') OR (name_a = 'Ishikawa' AND name_b = '石川') OR (name_a = 'Nakajima' AND name_b = '中島') OR (name_a = 'Maeda' AND name_b = '前田') OR (name_a = 'Fujita' AND name_b = '藤田') OR (name_a = 'Ogawa' AND name_b = '小川') OR (name_a = 'Goto' AND name_b = '後藤') OR (name_a = 'Okada' AND name_b = '岡田') OR (name_a = 'Hasegawa' AND name_b = '長谷川') OR (name_a = 'Murakami' AND name_b = '村上') OR (name_a = 'Kondo' AND name_b = '近藤') OR (name_a = 'Ishii' AND name_b = '石井') OR (name_a = 'Saito (different kanji)' AND name_b = '斉藤') OR (name_a = 'Sakamoto' AND name_b = '坂本') OR (name_a = 'Aoki' AND name_b = '青木') OR (name_a = 'Fujii' AND name_b = '藤井') OR (name_a = 'Nishimura' AND name_b = '西村') OR (name_a = 'Fukuda' AND name_b = '福田') OR (name_a = 'Ota' AND name_b = '太田') OR (name_a = 'Miura' AND name_b = '三浦') OR (name_a = 'Fujiwara' AND name_b = '藤原') OR (name_a = 'Okamoto' AND name_b = '岡本') OR (name_a = 'Matsuda' AND name_b = '松田') OR (name_a = 'Nakagawa' AND name_b = '中川') OR (name_a = 'Nakano' AND name_b = '中野') OR (name_a = 'Harada' AND name_b = '原田') OR (name_a = 'Ono' AND name_b = '小野') OR (name_a = 'Tamura' AND name_b = '田村') OR (name_a = 'Takeuchi' AND name_b = '竹内') OR (name_a = 'Kaneko' AND name_b = '金子') OR (name_a = 'Wada' AND name_b = '和田') OR (name_a = 'Nakayama' AND name_b = '中山') OR (name_a = 'Ishida' AND name_b = '石田') OR (name_a = 'Ueda' AND name_b = '上田') OR (name_a = 'Morita' AND name_b = '森田') OR (name_a = 'Hara' AND name_b = '原') OR (name_a = 'Shibata' AND name_b = '柴田') OR (name_a = 'Kudo' AND name_b = '工藤') OR (name_a = 'Yokoyama' AND name_b = '横山') OR (name_a = 'Miyazaki' AND name_b = '宮崎') OR (name_a = 'Miyamoto' AND name_b = '宮本') OR (name_a = 'Uchida' AND name_b = '内田') OR (name_a = 'Takagi' AND name_b = '高木') OR (name_a = 'Ando' AND name_b = '安藤') OR (name_a = 'Taniguchi' AND name_b = '谷口') OR (name_a = 'Ohno' AND name_b = '大野') OR (name_a = 'Maruyama' AND name_b = '丸山') OR (name_a = 'Imai' AND name_b = '今井') OR (name_a = 'Takada' AND name_b = '高田') OR (name_a = 'Fujimoto' AND name_b = '藤本') OR (name_a = 'Takeda' AND name_b = '武田') OR (name_a = 'Murata' AND name_b = '村田') OR (name_a = 'Ueno' AND name_b = '上野') OR (name_a = 'Sugiyama' AND name_b = '杉山') OR (name_a = 'Masuda' AND name_b = '増田') OR (name_a = 'Sugawara' AND name_b = '菅原') OR (name_a = 'Hirano' AND name_b = '平野') OR (name_a = 'Kojima' AND name_b = '小島') OR (name_a = 'Otsuka' AND name_b = '大塚') OR (name_a = 'Chiba' AND name_b = '千葉') OR (name_a = 'Kubo' AND name_b = '久保') OR (name_a = 'Matsui' AND name_b = '松井') OR (name_a = 'Iwasaki' AND name_b = '岩崎') OR (name_a = 'Sakurai' AND name_b = '桜井') OR (name_a = 'Kinoshita' AND name_b = '木下') OR (name_a = 'Noguchi' AND name_b = '野口') OR (name_a = 'Matsuo' AND name_b = '松尾') OR (name_a = 'Nomura' AND name_b = '野村') OR (name_a = 'Kikuchi' AND name_b = '菊地') OR (name_a = 'Sano' AND name_b = '佐野') OR (name_a = 'Onishi' AND name_b = '大西') OR (name_a = 'Sugimoto' AND name_b = '杉本') OR (name_a = 'Arai' AND name_b = '新井')
   ");

    // These names are the ones my neighbour marked as 'Harrisons' - they are also in the email.
    // Note that both the email & this list here come from a local db table so they
    // don't involve another round of possible human error.
    CRM_Core_DAO::executeQuery("
      DELETE FROM `civicrm_contact_name_pair_family`
      WHERE
   ((name_a = 'Sato' AND name_b = '佐登') OR (name_a = 'Sato' AND name_b = '砂東') OR (name_a = 'Sato' AND name_b = '左登') OR (name_a = 'Suzuki' AND name_b = '雪') OR (name_a = 'Suzuki' AND name_b = '進来') OR (name_a = 'Watanabe' AND name_b = '競') OR (name_a = 'Yoshida' AND name_b = 'よし田') OR (name_a = 'Sasaki' AND name_b = '雀') OR (name_a = 'Kimura' AND name_b = '喜邑') OR (name_a = 'Kimura' AND name_b = '貴邑') OR (name_a = 'Hayashi' AND name_b = '晨') OR (name_a = 'Shimizu' AND name_b = '清水') OR (name_a = 'Shimizu' AND name_b = '深水') OR (name_a = 'Shimizu' AND name_b = '真水') OR (name_a = 'Shimizu' AND name_b = '瀏') OR (name_a = 'Shimizu' AND name_b = '滋水') OR (name_a = 'Shimizu' AND name_b = '滋水') OR (name_a = 'Shimizu' AND name_b = '七美') OR (name_a = 'Mori' AND name_b = '盛') OR (name_a = 'Mori' AND name_b = '茂利') OR (name_a = 'Abe' AND name_b = '晁') OR (name_a = 'Maeda' AND name_b = '真枝') OR (name_a = 'Aoki' AND name_b = '檍') OR (name_a = 'Ono' AND name_b = '雄野') OR (name_a = 'Kaneko' AND name_b = '兼子') OR (name_a = 'Kaneko' AND name_b = '兼児') OR (name_a = 'Ueda' AND name_b = '宇枝') OR (name_a = 'Sakai' AND name_b = '栄') OR (name_a = 'Sakai' AND name_b = '盛') OR (name_a = 'Sakai' AND name_b = '界') OR (name_a = 'Sakai' AND name_b = '沙海') OR (name_a = 'Kudo' AND name_b = '宮道') OR (name_a = 'Kudo' AND name_b = '久道') OR (name_a = 'Kudo' AND name_b = '工通') OR (name_a = 'Miyamoto' AND name_b = '宮基') OR (name_a = 'Takagi' AND name_b = '高貴') OR (name_a = 'Takagi' AND name_b = '高樹') OR (name_a = 'Ando' AND name_b = '安道') OR (name_a = 'Fujimoto' AND name_b = '藤基') OR (name_a = 'Otsuka' AND name_b = '大槻') OR (name_a = 'Otsuka' AND name_b = '大司') OR (name_a = 'Chiba' AND name_b = '千野') OR (name_a = 'Chiba' AND name_b = '千波') OR (name_a = 'Chiba' AND name_b = '智葉') OR (name_a = 'Chiba' AND name_b = '知葉') OR (name_a = 'Chiba' AND name_b = '智羽') OR (name_a = 'Kubo' AND name_b = '久穂') OR (name_a = 'Kubo' AND name_b = '久甫') OR (name_a = 'Kinoshita' AND name_b = '空') OR (name_a = 'Kikuchi' AND name_b = '釋子'))
     AND is_most_common_form != 1
  ");

    // I think there was a name in the to-delete that was also the most common form -
    // I've just re-copied the list from ^^ with a WHERE rather than pick out that name.
    CRM_Core_DAO::executeQuery("
      UPDATE `civicrm_contact_name_pair_family`
      SET is_active = 0
      WHERE
   (name_a = 'Sato' AND name_b = '佐登') OR (name_a = 'Sato' AND name_b = '砂東') OR (name_a = 'Sato' AND name_b = '左登') OR (name_a = 'Suzuki' AND name_b = '雪') OR (name_a = 'Suzuki' AND name_b = '進来') OR (name_a = 'Watanabe' AND name_b = '競') OR (name_a = 'Yoshida' AND name_b = 'よし田') OR (name_a = 'Sasaki' AND name_b = '雀') OR (name_a = 'Kimura' AND name_b = '喜邑') OR (name_a = 'Kimura' AND name_b = '貴邑') OR (name_a = 'Hayashi' AND name_b = '晨') OR (name_a = 'Shimizu' AND name_b = '清水') OR (name_a = 'Shimizu' AND name_b = '深水') OR (name_a = 'Shimizu' AND name_b = '真水') OR (name_a = 'Shimizu' AND name_b = '瀏') OR (name_a = 'Shimizu' AND name_b = '滋水') OR (name_a = 'Shimizu' AND name_b = '滋水') OR (name_a = 'Shimizu' AND name_b = '七美') OR (name_a = 'Mori' AND name_b = '盛') OR (name_a = 'Mori' AND name_b = '茂利') OR (name_a = 'Abe' AND name_b = '晁') OR (name_a = 'Maeda' AND name_b = '真枝') OR (name_a = 'Aoki' AND name_b = '檍') OR (name_a = 'Ono' AND name_b = '雄野') OR (name_a = 'Kaneko' AND name_b = '兼子') OR (name_a = 'Kaneko' AND name_b = '兼児') OR (name_a = 'Ueda' AND name_b = '宇枝') OR (name_a = 'Sakai' AND name_b = '栄') OR (name_a = 'Sakai' AND name_b = '盛') OR (name_a = 'Sakai' AND name_b = '界') OR (name_a = 'Sakai' AND name_b = '沙海') OR (name_a = 'Kudo' AND name_b = '宮道') OR (name_a = 'Kudo' AND name_b = '久道') OR (name_a = 'Kudo' AND name_b = '工通') OR (name_a = 'Miyamoto' AND name_b = '宮基') OR (name_a = 'Takagi' AND name_b = '高貴') OR (name_a = 'Takagi' AND name_b = '高樹') OR (name_a = 'Ando' AND name_b = '安道') OR (name_a = 'Fujimoto' AND name_b = '藤基') OR (name_a = 'Otsuka' AND name_b = '大槻') OR (name_a = 'Otsuka' AND name_b = '大司') OR (name_a = 'Chiba' AND name_b = '千野') OR (name_a = 'Chiba' AND name_b = '千波') OR (name_a = 'Chiba' AND name_b = '智葉') OR (name_a = 'Chiba' AND name_b = '知葉') OR (name_a = 'Chiba' AND name_b = '智羽') OR (name_a = 'Kubo' AND name_b = '久穂') OR (name_a = 'Kubo' AND name_b = '久甫') OR (name_a = 'Kinoshita' AND name_b = '空') OR (name_a = 'Kikuchi' AND name_b = '釋子')

  ");
   return TRUE;
  }

  /**
   * Pre-populate name match table with Japanese comparisons.
   *
   * @throws \League\Csv\Exception
   */
  public function prePopulateFamilyNameMatchTable(): void {
    $reader = Reader::from(__DIR__ . '/Upgrader/japanese-family-names.csv', 'r');
    $reader->setHeaderOffset(0);
    foreach ($reader as $row) {
      CRM_Core_DAO::executeQuery(
        'INSERT INTO civicrm_contact_name_pair_family
        (name_a, name_b)
         VALUES (%1, %2)
      ', [
        1 => [$row['name'], 'String'],
        2 => [$row['most_common_form'], 'String'],
      ]);
      $otherNames = explode('、', $row['alternates']);
      foreach ($otherNames as $name) {
        if (!empty($name)) {
          CRM_Core_DAO::executeQuery(
            'INSERT INTO civicrm_contact_name_pair_family
             (name_a, name_b)
            VALUES (%1, %2)
          ', [
            1 => [$row['name'], 'String'],
            2 => [$name, 'String'],
          ]);
        }
      }
    }

  }

  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = E::ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
