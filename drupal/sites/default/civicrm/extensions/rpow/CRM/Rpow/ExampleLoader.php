<?php

class CRM_Rpow_ExampleLoader {

  /**
   * @param string $file
   *   File name
   * @return array
   *   List of SQL expressions
   */
  public static function load($file) {
    $c = file_get_contents($file);
    $lines = explode(";", $c);
    $result = [];
    foreach ($lines as $line) {
      if (strlen(trim($line)) > 0) {
        $result[] = $line;
      }
    }
    return $result;
  }

}
