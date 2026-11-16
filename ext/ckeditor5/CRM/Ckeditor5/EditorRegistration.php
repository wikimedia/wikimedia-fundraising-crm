<?php

/**
 * Resolve CKEditor 5 editor identifiers and preserve legacy upload behavior.
 */
class CRM_Ckeditor5_EditorRegistration {

  public const EDITOR_ID = 'CKEditor5';

  public const LEGACY_UPLOAD_MODES = [
    'CKEditor5-elfinder' => 'elfinder',
    'CKEditor5-base64' => 'base64',
  ];

  /**
   * Check whether an editor identifier belongs to this extension.
   */
  public static function supports($editorId) {
    return $editorId === self::EDITOR_ID || isset(self::LEGACY_UPLOAD_MODES[$editorId]);
  }

  /**
   * Advertise support for fields which store GitHub Flavored Markdown.
   *
   * Companion extensions may feature-detect this method without declaring a
   * hard dependency on CKEditor 5.
   */
  public static function supportsMarkdown() {
    return TRUE;
  }

  /**
   * Return the upload mode encoded by a legacy editor identifier.
   */
  public static function legacyUploadMode($editorId) {
    return self::LEGACY_UPLOAD_MODES[$editorId] ?? NULL;
  }

  /**
   * Merge a migrated upload mode into the default preset without data loss.
   */
  public static function migratePresets(array $presets, $uploadMode) {
    if (!in_array($uploadMode, ['elfinder', 'base64'], TRUE)) {
      throw new InvalidArgumentException('Invalid legacy CKEditor 5 upload mode.');
    }
    if (isset($presets['default']) && !is_array($presets['default'])) {
      throw new UnexpectedValueException('The default CKEditor 5 preset is invalid.');
    }
    $presets['default'] = $presets['default'] ?? [];
    $presets['default']['civiUploadMode'] = $uploadMode;
    return $presets;
  }

}
