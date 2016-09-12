<?php

/**
 * DokuWiki DAVCal PlugIn - Disable component
 */

if(!defined('DOKU_INC')) die();

class action_plugin_davcal_disable extends DokuWiki_Action_Plugin {

    /**
     * @var helper_plugin_davcal
     */
    private $hlp = null;

    function __construct() {
        $this->hlp =& plugin_load('helper','davcal');
    }

    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'BEFORE', $this, 'handle_wikipage_write');
    }
    
    function handle_wikipage_write(&$event, $param)
    {
        $data = $event->data;
        if(strpos($data[0][1], '{{davcal') !== false) return; // Plugin is still enabled
        
        $id = ltrim($data[1].':'.$data[2], ':');
        
        $this->hlp->disableCalendarForPage($id);
    }
};
