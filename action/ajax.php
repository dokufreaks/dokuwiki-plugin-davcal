<?php

/**
 * DokuWiki DAVCal PlugIn - Ajax component
 */

if(!defined('DOKU_INC')) die();

class action_plugin_davcal_ajax extends DokuWiki_Action_Plugin {

    /**
     * @var helper_plugin_davcal
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
      $page = trim($INPUT->post->str('page'));
      $params = $INPUT->post->arr('params');
      $user = $_SERVER['REMOTE_USER'];
      $write = false;
      $multi = false;
      
      $data = array();
      
      $data['result'] = false;
      $data['html'] = $this->getLang('unknown_error');
      
      // Check if we have access to the calendar ($id is given by parameters,
      // that's not necessarily the page we come from)
      $acl = auth_quickaclcheck($id);
      if($acl > AUTH_READ)
      {
          $write = true;
      }
      
      // Retrieve the calendar pages based on the meta data
      $calendarPages = $this->hlp->getCalendarPagesByMeta($page);
      if($calendarPages === false)
      {
          $calendarPages = array($page);
      }
      if(count($calendarPages) > 1)
        $multi = true;
      
      // Parse the requested action
      switch($action)
      {
          // Add a new Event
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
          // Retrieve existing Events
          case 'getEvents':
              $startDate = $INPUT->post->str('start');
              $endDate = $INPUT->post->str('end');
              $data = array();
              foreach($calendarPages as $calPage)
              {
                  $data = array_merge($data, $this->hlp->getEventsWithinDateRange($calPage, 
                                      $user, $startDate, $endDate)); 
              }
          break;
          // Edit an event
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
          // Delete an Event
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
          // Get personal settings
          case 'getSettings':
              $data['result'] = true;
              $data['settings'] = $this->hlp->getPersonalSettings($user);
              $data['settings']['multi'] = $multi;
              $data['settings']['calids'] = $this->hlp->getCalendarMapForIDs($calendarPages);
              $data['settings']['readonly'] = !$write;
              $data['settings']['syncurl'] = $this->hlp->getSyncUrlForPage($page, $user);
              $data['settings']['privateurl'] = $this->hlp->getPrivateURLForPage($page);
              $data['settings']['meta'] = $this->hlp->getCalendarMetaForPage($page);
          break;
          // Save personal settings
          case 'saveSettings':
              $settings = array();
              $settings['weeknumbers'] = $params['weeknumbers'];
              $settings['timezone'] = $params['timezone'];
              $settings['workweek'] = $params['workweek'];
              $settings['monday'] = $params['monday'];
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