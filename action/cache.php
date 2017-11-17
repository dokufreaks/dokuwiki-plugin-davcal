<?php

/**
 * DokuWiki DAVCal PlugIn - Cache component
 */

if(!defined('DOKU_INC')) die();

class action_plugin_davcal_cache extends DokuWiki_Action_Plugin {

    /**
     * @var helper_plugin_davcal
     */
    private $hlp = null;

    function __construct() {
        $this->hlp =& plugin_load('helper','davcal');
    }

    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'handle_parser_cache_use');
    }

    function handle_parser_cache_use(&$event, $param) {
      global $ID;
      $cache = &$event->data;
      if(!isset($cache->page)) return;
      
      $davcalMeta = p_get_metadata($ID, 'plugin_davcal');
      if(!$davcalMeta)
        return;
      
      if((isset($davcalMeta['table']) && $davcalMeta['table'] === true) ||
         (isset($davcalMeta['events']) && $davcalMeta['events'] === true))
      {
            $event->preventDefault();
            $event->stopPropagation();
            $event->result = false;
      }         
    }
 
}              