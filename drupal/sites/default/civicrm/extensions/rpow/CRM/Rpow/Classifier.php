<?php

class CRM_Rpow_Classifier {

  /**
   * The SQL statement may be safely executed on a read-only slave.
   *
   * Ex: SELECT foo FROM BAR;
   */
  const TYPE_READ = 'R';

  /**
   * The SQL statement must be executed on the read-write master.
   *
   * Ex: INSERT INTO foo (bar) VALUES (123);
   */
  const TYPE_WRITE = 'W';

  /**
   * The SQL statement may be tentatively executed on a read-only slave; however,
   * if there are subsequent changes, then we must re-play on the read-write master.
   *
   * Ex: SET @active_contact_id = 123;
   */
  const TYPE_BUFFER = 'B';

  /**
   * Determine whether the SQL expression represents a simple read, a write, or a buffer-required read.
   *
   * @param string $rawSql
   *   An SQL statement
   * @return string
   *   TYPE_READ, TYPE_WRITE, or TYPE_BUFFER
   */
  public function classify($rawSql) {
    // Distill to a normalized SQL expression -- simplify whitespace and capitalization; remove user-supplied strings.
    $trimmedSql = $this->cleanWhitespace(
      mb_strtolower(
        trim($rawSql)
      )
    );
    $possibleTempTable = (mb_strpos($trimmedSql, 'civicrm_tmp_e_') !== FALSE);
    if ($possibleTempTable) {
      $trimmedSql = preg_replace(';`civicrm_tmp_e_(\w*)`;', 'civicrm_tmp_e_$1', $trimmedSql);
    }

    $sql = $this->cleanActiveComments($this->stripStrings($trimmedSql));

    // Hard-dice: UNION statements require more parsing work.
    if (mb_strpos($sql, ' union ') !== FALSE) {
      $unionParts = explode(' union ', $sql);
      $isBuffer = FALSE;
      foreach ($unionParts as $unionPart) {
        $subClassify = $this->classify($this->stripParens($unionPart));
        if ($subClassify === self::TYPE_WRITE) {
          return $subClassify;
        }
        $isBuffer = $isBuffer || ($subClassify == self::TYPE_BUFFER);
      }
      return $isBuffer ? self::TYPE_BUFFER : self::TYPE_READ;
    }

    // Micro-optimization: we'll execute most frequently in pure-read scenarios, so check those early on.
    if (mb_substr($sql, 0, 7) === 'select ') {

      $isWrite =
        preg_match('; (for update|for share|into outfile|into dumpfile);', $sql)
        // ^^keywords

        // FIXME: This is more correct, but civicrm-core may be a bit too eager with using them?
        || preg_match(';[ ,\(](get_lock|is_free_lock|is_used_lock) *\(;S', $sql)
        // ^^functions

        || ($trimmedSql === 'select "civirpow-force-write"')
        || ($trimmedSql === 'select \'civirpow-force-write\'');
      if ($isWrite) {
        return self::TYPE_WRITE;
      }

      $isBuffer = preg_match(';@[a-zA-Z0-9_\s]+:=;', $sql) || (mb_strpos($sql, ' into @') !== FALSE);
      return $isBuffer ? self::TYPE_BUFFER : self::TYPE_READ;
    }

    // Micro-optimization: only do long regexes if there was hint that they're useful
    if ($possibleTempTable) {
      // INSERT [LOW_PRIORITY | DELAYED | HIGH_PRIORITY] [IGNORE] [INTO] tbl_name
      if (preg_match(';^insert (low_priority |delayed |high_priority |ignore |into )*civicrm_tmp_e_;', $sql)) {
        return self::TYPE_BUFFER;
      }
      // UPDATE [LOW_PRIORITY] [IGNORE] table_reference SET
      if (preg_match(';^update (low_priority |ignore )*([\w,` ]+) set;', $sql, $matches)) {
        // If *all* updated tables are temp, then TYPE_BUFFER. But if *any* are durable, then TYPE_WRITE.
        // FIXME: Does MySQL UPDATE also do table-aliases and joins?
        $tables = preg_split(';,\s*;', $matches[2]);
        if ($tables === preg_grep(';^civicrm_tmp_e;', $tables)) {
          return self::TYPE_BUFFER;
        }
      }
      // DELETE [LOW_PRIORITY] [QUICK] [IGNORE] FROM tbl_name
      if (preg_match(';^delete (low_priority |quick |ignore )*from civicrm_tmp_e_;', $sql)) {
        return self::TYPE_BUFFER;
      }
    }

    if (preg_match(';^(desc|describe|show|explain) ;', $sql)) {
      return self::TYPE_READ;
    }

    if (preg_match(';^(set|begin|savepoint|start transaction|set autocommit|create temporary|drop temporary);', $sql)) {
      // "SET" and "SET autocommit" are technically redundant, but they should be considered logically distinct.
      return self::TYPE_BUFFER;
    }

    return self::TYPE_WRITE;
  }

  /**
   * Convert any escaped inline user-strings to empty-strings.
   *
   * @param string $sql
   *   Ex: SELECT * FROM foo WHERE bar = "loopdiloop" AND id > 10
   * @return string
   *   Ex: SELECT * FROM foo WHERE bar = "" AND id > 10
   */
  public function stripStrings($sql) {
    $PLAIN = -1;
    $SINGLE = '\'';
    $DOUBLE = '"';
    $BACK = '`';
    $ESCAPE = '\\';

    $buf = '';
    $len = strlen($sql);
    $mode = $PLAIN;
    $esc = FALSE;
    for ($i = 0; $i < $len; $i++) {
      $char = $sql[$i];
      // echo "check ($char) in mode ($mode) while buf=($buf)\n";

      switch ($mode) {
        case $PLAIN:
          $buf .= $char;

          if ($char === $SINGLE || $char === $DOUBLE || $char === $BACK) {
            // echo " -> switch to $char mode\n";
            $mode = $char;
          }
          break;

        case $SINGLE:
        case $DOUBLE:
        case $BACK:
          if ($char === $ESCAPE) {
            $esc = TRUE;
            break;
          }
          elseif ($char === $mode && !$esc) {
            $mode = $PLAIN;
            $buf .= $char;
          }
          else {
            $esc = FALSE;
          }
          break;
      }
    }

    return $buf;
  }

  public function stripParens($sql) {
    if ($sql[0] !== '(') {
      return $sql;
    }

    $len = mb_strlen($sql);
    if ($sql[$len - 1] !== ')') {
      return $sql;
    }

    return mb_substr($sql, 1, $len - 2);
  }

  /**
   * Normalize whitespace -- all adjacent whitespace becomes a single literal space-character.
   *
   * @param string $sql
   *   Ex: "\t\t\tHello   world \r\n\r\n"
   * @return string
   *   Ex: " Hello world "
   */
  public function cleanWhitespace($sql) {
    return preg_replace(';\s+;S', ' ', $sql);
  }

  /**
   * MySQL allows conditional expressions that are only executed if the server
   * matches a version constraint. (ex: /*!40101). Assume that these will be executed
   * and render them as full SQL.
   *
   * @param string $sql
   *   Ex: '/*!40101 SET NAMES utf8 *' . '/'
   *   (This example is munged because a clean example will break PHP comment parsing.)
   * @return string
   *   The same query with conditional notations rendered as active notations.
   *   Ex: ' SET NAMES utf8 '
   * @link https://dev.mysql.com/doc/refman/8.0/en/comments.html
   */
  public function cleanActiveComments($sql) {
    if (FALSE !== mb_strpos($sql, '/*!')) {
      return $this->cleanWhitespace(preg_replace(';/\*!\d+ (.*?)\*/;', '$1', $sql));
    }
    else {
      return $sql;
    }
  }

}
