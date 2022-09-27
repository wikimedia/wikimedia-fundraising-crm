<?php

namespace Civi\WMFAudit;

interface MultipleFileTypeParser {

  /**
   * Set the path of the file
   *
   * @param string $filePath Full path of the file to be parsed
   */
  public function setFilePath( string $filePath );

  /**
   * Get the path of the file
   *
   * @return string $filePath Full path of the file to be parsed
   */
  public function getFilePath();
}
