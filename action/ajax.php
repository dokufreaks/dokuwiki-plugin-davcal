<?php

if(!defined('DOKU_INC')) die();

class action_plugin_davcal_ajax extends DokuWiki_Action_Plugin {

    /**
     * @var helper_plugin_publish
     */
    private $hlp = null;

    function __construct() {
        $this->hlp =& plugin_load('helper','davcal');
    }

    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handle_ajax_call_unknown');
    }

    function handle_ajax_call_unknown(&$event, $param) {
      if($event->data != 'plugin_davcal') return;
      
      $event->preventDefault();
      $event->stopPropagation();
      global $INPUT;
      
      $action = trim($INPUT->post->str('action'));
      $id = trim($INPUT->post->str('id'));
      $params = $INPUT->post->arr('params');
      $user = $_SERVER['REMOTE_USER'];
      $write = false;
      
      $data = array();
      
      $data['result'] = false;
      $data['html'] = $this->getLang('unknown_error');
      
      $acl = auth_quickaclcheck($id);
      if($acl > AUTH_READ)
      {
          $write = true;
      }
      
      switch($action)
      {
          case 'newEvent':
              if($write)
              {
                  $data['result'] = true;
                  $data['html'] = $this->getLang('event_added');
                  $this->hlp->addCalendarEntryToCalendarForPage($id, $user, $params);
              }
              else
              {
                  $data['result'] = false;
                  $data['html'] = $this->getLang('no_permission');
              }
          break;
          case 'getEvents':
              $startDate = $INPUT->post->str('start');
              $endDate = $INPUT->post->str('end');
              $data = $this->hlp->getEventsWithinDateRange($id, $user, $startDate, $endDate);
              
          break;
          case 'editEvent':
              if($write)
              {
                  $data['result'] = true;
                  $data['html'] = $this->getLang('event_edited');
                  $this->hlp->editCalendarEntryForPage($id, $user, $params);
              }
              else
              {
                  $data['result'] = false;
                  $data['html'] = $this->getLang('no_permission');
              }
          break;
          case 'deleteEvent':
              if($write)
              {
                  $data['result'] = true;
                  $data['html'] = $this->getLang('event_deleted');
                  $this->hlp->deleteCalendarEntryForPage($id, $params);
              }
              else 
              {
                  $data['result'] = false;
                  $data['html'] = $this->getLang('no_permission');
              }
          break;
          case 'getSettings':
              $data['result'] = true;
              $data['settings'] = $this->hlp->getPersonalSettings($user);
          break;
          case 'saveSettings':
              $settings = array();
              $settings['weeknumbers'] = $params['weeknumbers'];
              $settings['timezone'] = $params['timezone'];
              $settings['workweek'] = $params['workweek'];
              if($this->hlp->savePersonalSettings($settings, $user))
              {
                  $data['result'] = true;
                  $data['html'] = $this->getLang('settings_saved');
              }
              else
              {
                  $data['result'] = false;
                  $data['html'] = $this->getLang('error_saving');
              }
          break;
      }
              
              
              
              
      // If we are still here, JSON output is requested
      
      //json library of DokuWiki
      require_once DOKU_INC . 'inc/JSON.php';
      $json = new JSON();
 
      //set content type
      header('Content-Type: application/json');
      echo $json->encode($data);            
    }
 
}              