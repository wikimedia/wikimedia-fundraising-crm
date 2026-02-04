<?php

namespace Civi\WMFHelper;

class Name {
  /**
   * Count the number of capital letters in a string.
   *
   * @param string $string
   *
   * @return int
   */
  protected static function countCapitalLetters(string $string): int {
    return strlen(preg_replace('/[^A-Z]+/', '', $string));
  }

  /**
   * Is name 2 better than name 1 from a capitalisation point of view.
   *
   * We define 'better' as the least capital letters, but more than zero.
   *
   * @param string $name1
   * @param string $name2
   *
   * @return bool true if name 2 is better
   */
  public static function isBetterCapitalization(string $name1, string $name2): bool {
    return self::countCapitalLetters($name1) === 0
      || (self::countCapitalLetters($name2) > 0 && self::countCapitalLetters($name2) < self::countCapitalLetters($name1)
      );
  }
}
