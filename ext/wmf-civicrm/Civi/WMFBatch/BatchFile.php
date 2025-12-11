<?php

namespace Civi\WMFBatch;

use CRM_Utils_Request;
use CRM_Utils_System;
use League\Csv\Reader;

class BatchFile {
  public static function getBatchFile(): void {
    $batch = CRM_Utils_Request::retrieveValue('batch', 'String');
    $type = CRM_Utils_Request::retrieveValue('type', 'String', 'details');
    // for now let's put a single batch in the string but ideally we would make it so that multiple
    // batches can be combined into one csv or a zip file.
    if (empty($batch) || !preg_match('/^[A-Za-z0-9_-]+$/', $batch)) {
      CRM_Utils_System::statusBounce(ts('Invalid batch identifier.'));
    }
    $file = self::getBatchFilePath($batch, $type);
    if (!$file) {
      throw new \CRM_Core_Exception('batch not generated');
    }
    // So far only details.
    $allowedTypes = ['details'];
    if (!in_array($type, $allowedTypes, true)) {
      CRM_Utils_System::statusBounce(ts('Invalid batch type.'));
    }
    $reader = Reader::createFromPath($file);
    $reader->output(basename($file));
    CRM_Utils_System::civiExit();
  }

  public static function getBatchFilePath($batchName, $type): ?string {
    $file = \Civi::settings()->get('wmf_audit_intact_files') . '/' . $batchName . '_' . $type . '.csv';
    if (file_exists($file)) {
      return $file;
    }
    return NULL;
  }

  public static function getBatchFileUrl(array $batch, string $type): string {
    return CRM_Utils_System::url('civicrm/batch/download', [
      'type' => $type,
      'batch' => implode(',', $batch),
    ], TRUE);
  }

}
