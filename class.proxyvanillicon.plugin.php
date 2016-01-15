<?php
/**
 * Proxy for vanillicon.
 * This plugin is based on the plugin "vanillicon" by Todd Burry.
 *
 * @author Kristof Hamann
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 * @package proxyvanillicon
 */

// Define the plugin:
$PluginInfo['proxyvanillicon'] = array(
   'Name' => 'Vanillicon Proxy',
   'Description' => "This proxy (with local cache) for Vanillicon enhances privacy for your users.",
   'Version' => '2.1',
   'RequiredApplications' => array('Vanilla' => '2.0.18'),
   'Author' => 'Kristof Hamann',
   'AuthorEmail' => 'vanilliconproxy@korelstar.de',
   'AuthorUrl' => 'http://www.vanillaforums.org/profile/korelstar',
   'MobileFriendly' => true,
   'SettingsUrl' => '/settings/vanillicon',
   'SettingsPermission' => 'Garden.Settings.Manage'
);

/**
 * Class VanilliconProxyPlugin
 */
class VanilliconProxyPlugin extends Gdn_Plugin {

    const IMG_DIR = 'vanilliconproxy';

   /**
    * Set up the plugin.
    */
    public function setup() {
        TouchConfig('Plugins.Vanillicon.Type', 'v2');
    }

   /**
    * Get the cache path for images as a absolute path and creates the directory if neccessary
    */
    public static function getCachePath() {
        $path = PATH_CACHE.'/'.self::IMG_DIR.'/';
        if(!file_exists($path))
            mkdir($path, 0777);
        return $path;
    }

   /**
    * Gets the cache path for images as a relative path, relative to the vanilla base directory
    */
    public static function getCachePathRelative() {
        return 'cache/'.self::IMG_DIR.'/';
    }

   /**
    * Get the path to the maintenance file used for locking and clear up interval.
    */
    public static function getMaintenanceFilepath() {
        $path = self::getCachePath().'maintenance';
        if(!file_exists($path))
            touch($path);
        return $path;
    }


    public static function getExpirePeriod() {
        return C('Plugins.ProxyVanillicon.ExpirePeriod', '1 Month');
    }
    public static function getExpireCheckPeriod() {
        return C('Plugins.ProxyVanillicon.ExpireCheckPeriod', '1 Day');
    }
    public static function getExpiredTime() {
        return strtotime('-'.self::getExpirePeriod());
    }
    public static function getExpiredCheckTime() {
        return strtotime('-'.self::getExpireCheckPeriod());
    }

   /**
    * Clears the cache.
    * $days (optional) if given, clears only files which are older than $days days.
    */
    public static function clearCache($expiredTime=null) {
        $pathMaintenance = self::getMaintenanceFilepath();
        foreach(glob(self::getCachePath().'*') as $filename) {
            if($filename != $pathMaintenance && !is_dir($filename)) {
                if($expiredTime===null || filemtime($filename)<$expiredTime) {
                    unlink($filename);
                }
            }
        }
    }

   /**
    * Clears the cache only, if the last clearance was a long time ago.
    */
    public static function clearExpired() {
        $pathMaintenance = self::getMaintenanceFilepath();
        $checktime = self::getExpiredCheckTime();
        // Check if maintenance needed
        if(filemtime($pathMaintenance) < $checktime) {
            $fp = fopen($pathMaintenance, 'r+');
            if($fp) {
                // get lock in order to prevent parallel requests do the same thing
                if(flock($fp, LOCK_EX | LOCK_NB)) {
                    // check if somebody else did the same in parallel before I got the lock
                    if(filemtime($pathMaintenance) < $checktime) {
                        // now I'm sure, nobody else clears the cache in parallel, so I can do it
                        self::clearCache(self::getExpiredTime());
                        touch($pathMaintenance);
                        clearstatcache();
                    }
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
            }
        }
    }

   /**
    * Set the vanillicon on the user' profile.
    *
    * @param ProfileController $Sender
    * @param array $Args
    */
    public function profileController_afterAddSideMenu_handler($Sender, $Args) {
        if (!$Sender->User->Photo) {
            $Sender->User->Photo = userPhotoDefaultUrl($Sender->User, array('Size' => 200));
        }
    }

   /**
    * The settings page for vanillicon.
    *
    * @param Gdn_Controller $sender
    */
    public function settingsController_vanillicon_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        $cf = new ConfigurationModule($sender);

        $items = array(
         'v1' => 'Vanillicon 1',
         'v2' => 'Vanillicon 2 (beta)'
        );

        $cachePath = self::getCachePath();
        $writable = is_writable($cachePath);
        $txtNotWritable = '<p style="color: #c00;">'.T('<b>Warning:</b> the cache directory is not writable. If you want to use the cache mechanism, you have to enable write permissions for').'</p><code>'.$cachePath.'</code><br><br><br>';
        $txtProxyActive = '<p style="color: #090;">'.T('<b>Congratulations: The privacy of your users is respected!</b> All requests to vanillicon.com are made by your server and not by your users.').'</p><br>';

        $cf->initialize(array(
         'Plugins.Vanillicon.Type' => array(
            'LabelCode' => 'Vanillicon Proxy',
            'Control' => 'radiolist',
            'Description' => $txtProxyActive.($writable ? '' : $txtNotWritable).' '.T('Which vanillicon set do you want to use?'),
            'Items' => $items,
            'Options' => array('list' => true, 'listclass' => 'icon-list', 'display' => 'after'),
            'Default' => 'v1'
         )
        ));

        $sender->addSideMenu();
        $sender->setData('Title', sprintf(t('%s Settings'), 'Vanillicon'));
        $cf->renderAll();
    }


   /**
    * Empty cache when disabling this plugin.
    */
    public function OnDisable() {
        self::clearCache();
    }


   /**
    * Dispatches icon request to the function loadImage()
    *
    * @Gdn_Dispatcher $dispatcher
    */
    public function Gdn_Dispatcher_NotFound_Handler($Dispatcher) {
        $requestUri = Gdn::Request()->Path();
        if(dirname($requestUri).'/'==self::getCachePathRelative()) {
            $filename = basename($requestUri);
            if(preg_match('/^[[:alnum:]_\.]+$/', $filename)) {
                $this->loadImage($filename);
            }
        }
    }

   /**
    * Loads the icon from the vanillicon server, saves it in the local cache (if writable) and send the image to the browser
    *
    * @param String $filename
    */
    protected function loadImage($filename) {
        $version = substr($filename, -4)=='.svg' ? 2 : 1;
        $uri = 'http://w'.substr($filename, 0, 1).'.vanillicon.com/'.($version==2 ? 'v2/' : '').$filename;
        $data = file_get_contents($uri);
        if(strlen($data)==0) {
            echo T('Sorry, we have got not data from vanillicon.com.');
            exit;
        }
        $path = self::getCachePath().$filename;
        if(is_writable(dirname($path))) {
            file_put_contents($path, $data);
        }
        header('Content-Type: image/'.($version==2 ? 'svg+xml' : 'png'));
        echo $data;
        exit;
    }
}

if (!function_exists('UserPhotoDefaultUrl')) {
   /**
    * Calculate the user's default photo url.
    *
    * @param array|object $user The user to examine.
    * @param array $options An array of options.
    * - Size: The size of the photo.
    * @return string Returns the vanillicon url for the user.
    */
    function userPhotoDefaultUrl($user, $options = array()) {
        @VanilliconProxyPlugin::clearExpired();
        static $iconSize = null, $type = null;
        if ($iconSize === null) {
            $thumbSize = c('Garden.Thumbnail.Size');
            $iconSize = $thumbSize <= 50 ? 50 : 100;
        }
        if ($type === null) {
            $type = c('Plugins.Vanillicon.Type');
        }
        $size = val('Size', $options, $iconSize);

        $email = val('Email', $user);
        if (!$email) {
            $email = val('UserID', $user, 100);
        }
        $hash = md5($email);
        $px = substr($hash, 0, 1);
        $cachePath = '//'.Gdn::Request()->Host().'/'.Gdn::Request()->WebRoot().'/'.VanilliconProxyPlugin::getCachePathRelative();

        switch ($type) {
            case 'v2':
                $photoUrl = "{$cachePath}{$hash}.svg";
                break;
            default:
                $photoUrl = "{$cachePath}{$hash}_{$size}.png";
                break;
        }

        return $photoUrl;
    }
}
