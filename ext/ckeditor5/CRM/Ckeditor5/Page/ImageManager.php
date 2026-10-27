<?php
use CRM_Ckeditor5_ExtensionUtil as E;

class CRM_Ckeditor5_Page_ImageManager extends CRM_Core_Page {

  /**
   * Run upload.
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public function run() {
    if (!CRM_Core_Permission::check('access CiviCRM') || !CRM_Core_Permission::check('access uploaded files')) {
      throw new CRM_Core_Exception('Permission denied');
    }

    // // Optional exec path settings (Default is called with command name only)
    // define('ELFINDER_TAR_PATH',      '/PATH/TO/tar');
    // define('ELFINDER_GZIP_PATH',     '/PATH/TO/gzip');
    // define('ELFINDER_BZIP2_PATH',    '/PATH/TO/bzip2');
    // define('ELFINDER_XZ_PATH',       '/PATH/TO/xz');
    // define('ELFINDER_ZIP_PATH',      '/PATH/TO/zip');
    // define('ELFINDER_UNZIP_PATH',    '/PATH/TO/unzip');
    // define('ELFINDER_RAR_PATH',      '/PATH/TO/rar');
    // define('ELFINDER_UNRAR_PATH',    '/PATH/TO/unrar');
    // define('ELFINDER_7Z_PATH',       '/PATH/TO/7za');
    // define('ELFINDER_CONVERT_PATH',  '/PATH/TO/convert');
    // define('ELFINDER_IDENTIFY_PATH', '/PATH/TO/identify');
    // define('ELFINDER_EXIFTRAN_PATH', '/PATH/TO/exiftran');
    // define('ELFINDER_JPEGTRAN_PATH', '/PATH/TO/jpegtran');
    // define('ELFINDER_FFMPEG_PATH',   '/PATH/TO/ffmpeg');

    define('ELFINDER_CONNECTOR_URL', CRM_Utils_System::url('civicrm/ckeditor5/image-manager', NULL, TRUE, NULL, FALSE, FALSE, TRUE));
    // see elFinder::getConnectorUrl()

    // define('ELFINDER_DEBUG_ERRORLEVEL', -1); // Error reporting level of debug mode

    // // To Enable(true) handling of PostScript files by ImageMagick
    // // It is disabled by default as a countermeasure
    // // of Ghostscript multiple -dSAFER sandbox bypass vulnerabilities
    // // see https://www.kb.cert.org/vuls/id/332928
    // define('ELFINDER_IMAGEMAGICK_PS', true);
    // ===============================================

    // elFinder autoload
    require CRM_Core_Resources::singleton()->getPath('ckeditor5', '/js/elFinder/php/autoload.php');
    // ===============================================

    // // Required for Dropbox network mount
    // // Installation by composer
    // // `composer require kunalvarma05/dropbox-php-sdk` on php directory
    // // Enable network mount
    // elFinder::$netDrivers['dropbox2'] = 'Dropbox2';
    // // Dropbox2 Netmount driver need next two settings. You can get at https://www.dropbox.com/developers/apps
    // // AND require register redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=dropbox2&host=1"
    // define('ELFINDER_DROPBOX_APPKEY',    '');
    // define('ELFINDER_DROPBOX_APPSECRET', '');
    // ===============================================

    // // Required for Google Drive network mount
    // // Installation by composer
    // // `composer require google/apiclient:^2.0` on php directory
    // // Enable network mount
    // elFinder::$netDrivers['googledrive'] = 'GoogleDrive';
    // // GoogleDrive Netmount driver need next two settings. You can get at https://console.developers.google.com
    // // AND require register redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=googledrive&host=1"
    // define('ELFINDER_GOOGLEDRIVE_CLIENTID',     '');
    // define('ELFINDER_GOOGLEDRIVE_CLIENTSECRET', '');
    // // Required case when Google API is NOT added via composer
    // define('ELFINDER_GOOGLEDRIVE_GOOGLEAPICLIENT', '/path/to/google-api-php-client/vendor/autoload.php');
    // ===============================================

    // // Required for Google Drive network mount with Flysystem
    // // Installation by composer
    // // `composer require nao-pon/flysystem-google-drive:~1.1 nao-pon/elfinder-flysystem-driver-ext` on php directory
    // // Enable network mount
    // elFinder::$netDrivers['googledrive'] = 'FlysystemGoogleDriveNetmount';
    // // GoogleDrive Netmount driver need next two settings. You can get at https://console.developers.google.com
    // // AND require register redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=googledrive&host=1"
    // define('ELFINDER_GOOGLEDRIVE_CLIENTID',     '');
    // define('ELFINDER_GOOGLEDRIVE_CLIENTSECRET', '');
    // // And "php/.tmp" directory must exist and be writable by PHP.
    // ===============================================

    // // Required for One Drive network mount
    // //  * cURL PHP extension required
    // //  * HTTP server PATH_INFO supports required
    // // Enable network mount
    // elFinder::$netDrivers['onedrive'] = 'OneDrive';
    // // GoogleDrive Netmount driver need next two settings. You can get at https://dev.onedrive.com
    // // AND require register redirect url to "YOUR_CONNECTOR_URL/netmount/onedrive/1"
    // define('ELFINDER_ONEDRIVE_CLIENTID',     '');
    // define('ELFINDER_ONEDRIVE_CLIENTSECRET', '');
    // ===============================================

    // // Required for Box network mount
    // //  * cURL PHP extension required
    // // Enable network mount
    // elFinder::$netDrivers['box'] = 'Box';
    // // Box Netmount driver need next two settings. You can get at https://developer.box.com
    // // AND require register redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=box&host=1"
    // define('ELFINDER_BOX_CLIENTID',     '');
    // define('ELFINDER_BOX_CLIENTSECRET', '');
    // ===============================================


    // // Zoho Office Editor APIKey
    // // https://www.zoho.com/docs/help/office-apis.html
    // define('ELFINDER_ZOHO_OFFICE_APIKEY', '');
    // ===============================================

    // // Online converter (online-convert.com) APIKey
    // // https://apiv2.online-convert.com/docs/getting_started/api_key.html
    // define('ELFINDER_ONLINE_CONVERT_APIKEY', '');
    // ===============================================

    // // Zip Archive editor
    // // Installation by composer
    // // `composer require nao-pon/elfinder-flysystem-ziparchive-netmount` on php directory
    // define('ELFINDER_DISABLE_ZIPEDITOR', false); // set `true` to disable zip editor
    // ===============================================

    /**
     * Simple function to demonstrate how to control file access using "accessControl" callback.
     * This method hides internal thumbnail directories and files/folders
     * starting from '.' (dot).
     *
     * @param string $attr attribute name (read|write|locked|hidden)
     * @param string $path absolute file path
     * @param string $data value of volume option `accessControlData`
     * @param object $volume elFinder volume driver object
     * @param bool|null $isDir path is directory (true: directory, false: file, null: unknown)
     * @param string $relpath file path relative to volume root directory started with directory separator
     *
     * @return bool|null
     **/
    $accessControl = static function($attr, $path, $data, $volume, $isDir, $relpath) {
      $basename = basename($path);
      return ($basename[0] === '.' || ($isDir && $basename === 'thumbnails'))
      && strlen($relpath) !== 1           // but with out volume root
        ? !($attr === 'read' || $attr === 'write') // set read+write to false, other (locked+hidden) set to true
        : NULL;                                 // else elFinder decide it itself
    };

    $requiredDirectories = [
      'persist/contribute/images/thumbnails',
      'persist/contribute/images/.trash',
      'persist/contribute/images/.trash/thumbnails',
    ];
    foreach ($requiredDirectories as $directory) {
      $directory = Civi::paths()->getPath('[civicrm.files]/' . $directory);
      if (!is_dir($directory) && !mkdir($directory, 0775, TRUE)) {
        throw new \RuntimeException(sprintf('Directory "%s" does not exist', $directory));

      }
    }

    $imageUrl = Civi::paths()->getUrl('[civicrm.files]/persist/contribute/images');
    $thumbnailPath = Civi::paths()->getPath('[civicrm.files]/persist/contribute/images/thumbnails');
    $thumbnailUrl = Civi::paths()->getUrl('[civicrm.files]/persist/contribute/images/thumbnails/');
    $trashThumbnailPath = Civi::paths()->getPath('[civicrm.files]/persist/contribute/images/.trash/thumbnails');
    $trashThumbnailUrl = Civi::paths()->getUrl('[civicrm.files]/persist/contribute/images/.trash/thumbnails/');
    // Some CMS path configurations return a local URL with a doubled leading
    // slash. Browsers interpret it as a protocol-relative hostname (e.g.
    // //sites/... becomes https://sites/...), so keep local paths root-relative.
    if (substr($imageUrl, 0, 2) === '//') {
      $imageUrl = substr($imageUrl, 1);
    }
    if (substr($thumbnailUrl, 0, 2) === '//') {
      $thumbnailUrl = substr($thumbnailUrl, 1);
    }
    if (substr($trashThumbnailUrl, 0, 2) === '//') {
      $trashThumbnailUrl = substr($trashThumbnailUrl, 1);
    }

    $allowedMimeTypes = [
      'image/x-ms-bmp',
      'image/gif',
      'image/jpeg',
      'image/png',
      'image/webp',
      'image/x-icon',
    ];
    $disabledCommands = ['mkfile', 'archive', 'extract', 'edit', 'netmount'];

    // Documentation for connector options:
    // https://github.com/Studio-42/elFinder/wiki/Connector-configuration-options
    $opts = [
      'debug' => Civi::settings()->get('debug_enabled'),
      // The image manager does not need to fetch arbitrary server-side URLs.
      'urlUploadFilter' => static function($url) {
        return FALSE;
      },
      'roots' => [
        // Items volume
        [
          'driver' => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
          // path to files (REQUIRED)
          'path' => Civi::paths()->getPath('[civicrm.files]/persist/contribute/images'),
          // URL to files (REQUIRED)
          'URL' => $imageUrl,
          // Dot-prefixed directories are commonly blocked by the web server.
          'tmbPath' => $thumbnailPath,
          'tmbURL' => $thumbnailUrl,
          'trashHash' => 't1_Lw',                     // elFinder's hash of trash folder
          'winHashFix' => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
          'uploadDeny' => ['all'],                // All Mimetypes not allowed to upload
          'uploadAllow' => $allowedMimeTypes,
          'uploadOrder' => ['deny', 'allow'],
          'disabled' => $disabledCommands,
          'accessControl' => $accessControl,              // disable and hide dot starting files (OPTIONAL)
        ],
        // Trash volume
        [
          'id' => '1',
          'driver' => 'Trash',
          'path' => Civi::paths()->getPath('[civicrm.files]/persist/contribute/images/.trash/'),
          'tmbPath' => $trashThumbnailPath,
          'tmbURL' => $trashThumbnailUrl,
          'winHashFix' => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
          'uploadDeny' => ['all'],                // Recomend the same settings as the original volume that uses the trash
          'uploadAllow' => $allowedMimeTypes,
          'uploadOrder' => ['deny', 'allow'],      // Same as above
          'disabled' => $disabledCommands,
          'accessControl' => $accessControl,              // Same as above
        ],
      ]
    ];

    // run elFinder
    $connector = new elFinderConnector(new elFinder($opts));
    $connector->run();
    exit;

  }

}
