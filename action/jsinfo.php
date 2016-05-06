<?php

/**
 * DokuWiki DAVCal PlugIn - JSINFO component
 */
 
if(!defined('DOKU_INC')) die();

class action_plugin_davcal_jsinfo extends DokuWiki_Action_Plugin {

    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'add_jsinfo_information');
    }

    /**
     * Add the language variable to the JSINFO variable
     */
    function add_jsinfo_information(&$event, $param) {
      global $conf;
      global $JSINFO;
      
      $lang = $conf['lang'];
      
      switch($lang)
      {
        case 'de':
        case 'de-informal':
            $lc = 'de';
            break;
        case 'nl':
            $lc = 'nl';
            break;
        case 'fr':
            $lc = 'fr';
            break;
        default:
            $lc = 'en';
      }
      
      $JSINFO['plugin']['davcal']['sectok'] = getSecurityToken();
      $JSINFO['plugin']['davcal']['language'] = $lc;
      if(isset($_SERVER['REMOTE_USER']) && !is_null($_SERVER['REMOTE_USER']))
      {
        $JSINFO['plugin']['davcal']['disable_sync'] = $this->getConf('disable_sync');
        $JSINFO['plugin']['davcal']['disable_settings'] = $this->getConf('hide_settings');
      }
      else
      {
        $JSINFO['plugin']['davcal']['disable_settings'] = 1;
        $JSINFO['plugin']['davcal']['disable_sync'] = 1;
      }
      $JSINFO['plugin']['davcal']['disable_ics'] = $this->getConf('disable_ics');
    }  
}
