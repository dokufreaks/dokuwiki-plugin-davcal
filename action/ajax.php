<?php

/**
 * DokuWiki DAVCal PlugIn - Ajax component
 */

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
      
      // Buffer output so PHP warnings don't corrupt JSON response
      ob_start();

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
                    // Auto-create event page for appearances calendar
                    if($id === 'calendars:appearances_calendar')
                    {
                        // Look up the real UID by finding the most recently added event
                        $realUid = $this->hlp->getMostRecentEventUid($id);
                        if($realUid)
                            $params['uid'] = $realUid;
                        $pageId = $this->hlp->createEventPage($id, $params);
                        if($pageId !== false)
                        {
                            $data['eventpage_url'] = wl($pageId);
                            $data['eventpage_id'] = $pageId;
                        }
                    }
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
                  // For appearances calendar, also delete the generated wiki page.
                  // Skipped when only one occurrence is being removed, since the
                  // rest of the series still needs its page.
                  $singleOccurrence = (isset($params['edit_mode']) && $params['edit_mode'] === 'single'
                                       && !empty($params['occurrence_date']));
                  if($id === 'calendars:appearances_calendar' && !empty($params['uid']) && !$singleOccurrence)
                  {
                      $title = $params['eventname'] ?? '';
                      $date  = $params['eventfrom'] ?? '';
                      if($title && $date)
                      {
                          $pageId = $this->hlp->getEventPageId($id, $title, $date);
                          if($pageId && page_exists($pageId))
                              saveWikiText($pageId, '', 'Deleted: event removed from calendar');
                      }
                  }
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

          // Check if an event page already exists
          case 'checkEventPage':
              $eventTitle = $params['title'];
              $eventDate = $params['date'];
              $pageId = $this->hlp->getEventPageId($id, $eventTitle, $eventDate);
              if($pageId === false)
              {
                  $data['result'] = false;
                  $data['html'] = $this->getLang('unknown_error');
              }
              else
              {
                  $data['result'] = true;
                  $data['exists'] = page_exists($pageId);
                  $data['url'] = wl($pageId);
                  $data['page_id'] = $pageId;
              }
          break;
          // Create an event page
          case 'createEventPage':
              if($write)
              {
                  $pageId = $this->hlp->createEventPage($id, $params);
                  if($pageId === false)
                  {
                      $data['result'] = false;
                      $data['html'] = $this->getLang('unknown_error');
                  }
                  else
                  {
                      $data['result'] = true;
                      $data['url'] = wl($pageId);
                  }
              }
              else
              {
                  $data['result'] = false;
                  $data['html'] = $this->getLang('no_permission');
              }
          break;
          // Regenerate an appearances event page preserving Notes and Recording Links
          case 'regenAppearancePage':
              if($write)
              {
                  try
                  {
                      $pageId = isset($params['page_id']) ? $params['page_id'] : '';
                      if(empty($pageId))
                      {
                          $data['result'] = false;
                          $data['html'] = 'No page ID provided.';
                          break;
                      }
                      $result = $this->hlp->regenAppearanceEventPage($pageId, $params);
                      if($result)
                      {
                          $data['result'] = true;
                          $data['url'] = wl($pageId);
                      }
                      else
                      {
                          $data['result'] = false;
                          $data['html'] = 'Failed to regenerate page.';
                      }
                  }
                  catch(\Exception $e)
                  {
                      $data['result'] = false;
                      $data['html'] = 'Server error: ' . $e->getMessage();
                  }
              }
              else
              {
                  $data['result'] = false;
                  $data['html'] = $this->getLang('no_permission');
              }
          break;
          case 'appendMember':
              if($write)
              {
                  try
                  {
                      $pageId = isset($params['page_id']) ? $params['page_id'] : '';
                      $uid    = isset($params['uid']) ? $params['uid'] : '';
                      if(empty($pageId))
                      {
                          $data['result'] = false;
                          $data['html'] = 'No page ID provided.';
                          break;
                      }
                      $res = $this->hlp->appendMemberToEventPage($pageId, $params);
                      if($res !== false)
                      {
                          // Ensure X-DAVCAL-MEMBERS is set to at least 1 so badge shows
                          if(!empty($uid))
                              $this->hlp->setMembersFlag($id, $uid);
                          $data['result'] = true;
                          $data['html'] = 'Member info added!';
                          $data['url'] = wl($pageId);
                      }
                      else
                      {
                          $data['result'] = false;
                          $data['html'] = 'Could not add member — event page not found.';
                      }
                  }
                  catch(\Exception $e)
                  {
                      $data['result'] = false;
                      $data['html'] = 'Server error: ' . $e->getMessage();
                  }
              }
              else
              {
                  $data['result'] = false;
                  $data['html'] = $this->getLang('no_permission');
              }
          break;
      }

     // If we are still here, JSON output is requested
      
      // Discard any stray output (PHP warnings etc) that would corrupt JSON
      ob_end_clean();

      //set content type
      header('Content-Type: application/json');
      echo json_encode($data);            
    }
 
}              
