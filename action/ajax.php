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
      if(isset($_SERVER['REMOTE_USER']) && !is_null($_SERVER['REMOTE_USER']))
        $user = $_SERVER['REMOTE_USER'];
      else
        $user = null;
      $write = false;
         
      if(!checkSecurityToken())
      {
          echo "CSRF Attack.";
          return;
      }
      
      $data = array();
      
      $data['result'] = false;
      $data['html'] = $this->getLang('unknown_error');
      
      // Check if we have access to the calendar ($id is given by parameters,
      // that's not necessarily the page we come from)
      
      $acl = $this->hlp->checkCalendarPermission($id);
      if($acl > AUTH_READ)
      {
          $write = true;
      }
      elseif($acl < AUTH_READ)
      {
          $data['result'] = false;
          $data['html'] = $this->getLang('no_permission');
          // Set to an invalid action in order to just return the result
          $action = 'invalid';
      }
      
      // Retrieve the calendar pages based on the meta data
      $calendarPages = $this->hlp->getCalendarPagesByMeta($page);
      if($calendarPages === false)
      {
          $calendarPages = array($page => null);
      }
      
      // Parse the requested action
      switch($action)
      {
          // Add a new Event
          case 'newEvent':
              if($write)
              {
                  $res = $this->hlp->addCalendarEntryToCalendarForPage($id, $user, $params);
                  if($res === true)
                  {
                    $data['result'] = true;
                    $data['html'] = $this->getLang('event_added');
                  }
                  else
                  {
                    $data['result'] = false;
                    $data['html'] = $this->getLang('unknown_error');
                  }
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
              $timezone = $INPUT->post->str('timezone');
              $data = array();
              foreach($calendarPages as $calPage => $color)
              {
                  $data = array_merge($data, $this->hlp->getEventsWithinDateRange($calPage, 
                                      $user, $startDate, $endDate, $timezone, $color)); 
              }
          break;
          // Edit an event
          case 'editEvent':
              if($write)
              {
                  $res = $this->hlp->editCalendarEntryForPage($id, $user, $params);
                  if($res === true)
                  {
                    $data['result'] = true;
                    $data['html'] = $this->getLang('event_edited');
                  }
                  else
                  {
                    $data['result'] = false;
                    $data['html'] = $this->getLang('unknown_error');
                  }
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
                  $res = $this->hlp->deleteCalendarEntryForPage($id, $params);
                  if($res === true)
                  {
                    $data['result'] = true;
                    $data['html'] = $this->getLang('event_deleted');
                  }
                  else
                  {
                    $data['result'] = false;
                    $data['html'] = $this->getLang('unknown_error');
                  }
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
              $data['settings']['calids'] = $this->hlp->getCalendarMapForIDs($calendarPages);
              $data['settings']['readonly'] = !$write;
              $data['settings']['syncurl'] = $this->hlp->getSyncUrlForPage($page, $user);
              $data['settings']['privateurl'] = $this->hlp->getPrivateURLForPage($page);
              $data['settings']['principalurl'] = $this->hlp->getPrincipalUrlForUser($user);
              $data['settings']['meta'] = $this->hlp->getCalendarMetaForPage($page);
          break;
          // Save personal settings
          case 'saveSettings':
              $settings = array();
              $settings['weeknumbers'] = $params['weeknumbers'];
              $settings['timezone'] = $params['timezone'];
              $settings['workweek'] = $params['workweek'];
              $settings['monday'] = $params['monday'];
              $settings['timeformat'] = $params['timeformat'];
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
