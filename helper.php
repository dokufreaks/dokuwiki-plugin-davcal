<?php
/** 
  * Helper Class for the tagrevisions plugin
  * This helper does the actual work.
  * 
  * Configurable in DokuWiki's configuration
  */
  
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class helper_plugin_davcal extends DokuWiki_Plugin {
  
  protected $sqlite = null;
  
  /**
    * Constructor to load the configuration
    */
  public function helper_plugin_davcal() {
    $this->sqlite =& plugin_load('helper', 'sqlite');
    if(!$this->sqlite)
    {
        msg('This plugin requires the sqlite plugin. Please install it.');
        return;
    }
    
    if(!$this->sqlite->init('davcal', DOKU_PLUGIN.'davcal/db/'))
    {
        return;
    }
  }
  
  public function setCalendarNameForPage($name, $description, $id = null, $userid = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      if(is_null($userid))
        $userid = $_SERVER['REMOTE_USER'];
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return $this->createCalendarForPage($name, $description, $id, $userid);
      
      $query = "UPDATE calendars SET displayname=".$this->sqlite->quote_string($name).", ".
               "description=".$this->sqlite->quote_string($description)." WHERE ".
               "id=".$this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      if($res !== false)
        return true;
      return false;
  }
  
  public function savePersonalSettings($settings, $userid = null)
  {
      if(is_null($userid))
          $userid = $_SERVER['REMOTE_USER'];
      $this->sqlite->query("BEGIN TRANSACTION");
      
      $query = "DELETE FROM calendarsettings WHERE userid=".$this->sqlite->quote_string($userid);
      $this->sqlite->query($query);
      
      foreach($settings as $key => $value)
      {
          $query = "INSERT INTO calendarsettings (userid, key, value) VALUES (".
                   $this->sqlite->quote_string($userid).", ".
                   $this->sqlite->quote_string($key).", ".
                   $this->sqlite->quote_string($value).")";
          $res = $this->sqlite->query($query);
          if($res === false)
              return false;
      }
      $this->sqlite->query("COMMIT TRANSACTION");
      return true;
  }
  
  public function getPersonalSettings($userid = null)
  {
      if(is_null($userid))
        $userid = $_SERVER['REMOTE_USER'];
      // Some sane default settings
      $settings = array(
        'timezone' => 'local',
        'weeknumbers' => '0',
        'workweek' => '0'
      );
      $query = "SELECT key, value FROM calendarsettings WHERE userid=".$this->sqlite->quote_string($userid);
      $res = $this->sqlite->query($query);
      $arr = $this->sqlite->res2arr($res);
      foreach($arr as $row)
      {
          $settings[$row['key']] = $row['value'];
      }
      return $settings;
  }
  
  public function getCalendarIdForPage($id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      
      $query = "SELECT calid FROM pagetocalendarmapping WHERE page=".$this->sqlite->quote_string($id);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      if(isset($row['calid']))
        return $row['calid'];
      else
        return false;
  }
  
  public function getCalendarIdToPageMapping()
  {
      $query = "SELECT calid, page FROM pagetocalendarmapping";
      $res = $this->sqlite->query($query);
      $arr = $this->sqlite->res2arr($res);
      return $arr;
  }
  
  public function getCalendarIdsForUser($principalUri)
  {
      $user = explode('/', $principalUri);
      $user = end($user);
      $mapping = $this->getCalendarIdToPageMapping();
      $calids = array();
      foreach($mapping as $row)
      {
          $id = $row['calid'];
          $page = $row['page'];
          $acl = auth_quickaclcheck($page);
          if($acl >= AUTH_READ)
          {
              $write = $acl > AUTH_READ;
              $calids[$id] = array('readonly' => !$write);
          }
      }
      return $calids;
  }
  
  public function createCalendarForPage($name, $description, $id = null, $userid = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      if(is_null($userid))
          $userid = $_SERVER['REMOTE_USER'];
      $values = array('principals/'.$userid, 
                      $name,
                      str_replace(array('/', ' ', ':'), '_', $id), 
                      $description,
                      'VEVENT,VTODO',
                      0,
                      1);
      $query = "INSERT INTO calendars (principaluri, displayname, uri, description, components, transparent, synctoken) VALUES (".$this->sqlite->quote_and_join($values, ',').");";
      $res = $this->sqlite->query($query);
      if($res === false)
        return false;
      $query = "SELECT id FROM calendars WHERE principaluri=".$this->sqlite->quote_string($values[0])." AND ".
               "displayname=".$this->sqlite->quote_string($values[1])." AND ".
               "uri=".$this->sqlite->quote_string($values[2])." AND ".
               "description=".$this->sqlite->quote_string($values[3]);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      if(isset($row['id']))
      {
          $values = array($id, $row['id']);
          $query = "INSERT INTO pagetocalendarmapping (page, calid) VALUES (".$this->sqlite->quote_and_join($values, ',').")";
          $res = $this->sqlite->query($query);
          return ($res !== false);
      }
      
      return false;
  }

  public function addCalendarEntryToCalendarForPage($id, $user, $params)
  {
      $settings = $this->getPersonalSettings($user);
      if($settings['timezone'] !== '' && $settings['timezone'] !== 'local')
          $timezone = new \DateTimeZone($settings['timezone']);
      elseif($settings['timezone'] === 'local')
          $timezone = new \DateTimeZone($params['detectedtz']);
      else
          $timezone = new \DateTimeZone('UTC');
      $startDate = explode('-', $params['eventfrom']);
      $startTime = explode(':', $params['eventfromtime']);
      $endDate = explode('-', $params['eventto']);
      $endTime = explode(':', $params['eventtotime']);
      require_once('vendor/autoload.php');
      $vcalendar = new \Sabre\VObject\Component\VCalendar();
      $event = $vcalendar->add('VEVENT');
      $uuid = \Sabre\VObject\UUIDUtil::getUUID();
      $event->add('UID', $uuid);
      $event->summary = $params['eventname'];
      $description = $params['eventdescription'];
      if($description !== '')
        $event->add('DESCRIPTION', $description);
      $dtStamp = new \DateTime(null, new \DateTimeZone('UTC'));
      $event->add('DTSTAMP', $dtStamp);
      $event->add('CREATED', $dtStamp);
      $event->add('LAST-MODIFIED', $dtStamp);
      $dtStart = new \DateTime();
      $dtStart->setTimezone($timezone);            
      $dtStart->setDate(intval($startDate[0]), intval($startDate[1]), intval($startDate[2]));
      if($params['allday'] != '1')
        $dtStart->setTime(intval($startTime[0]), intval($startTime[1]), 0);
      $dtEnd = new \DateTime();
      $dtEnd->setTimezone($timezone);      
      $dtEnd->setDate(intval($endDate[0]), intval($endDate[1]), intval($endDate[2]));
      if($params['allday'] != '1')
        $dtEnd->setTime(intval($endTime[0]), intval($endTime[1]), 0);
      // According to the VCal spec, we need to add a whole day here
      if($params['allday'] == '1')
          $dtEnd->add(new \DateInterval('P1D'));
      $dtStartEv = $event->add('DTSTART', $dtStart);
      $dtEndEv = $event->add('DTEND', $dtEnd);
      if($params['allday'] == '1')
      {
          $dtStartEv['VALUE'] = 'DATE';
          $dtEndEv['VALUE'] = 'DATE';
      }
      $calid = $this->getCalendarIdForPage($id);
      $uri = uniqid('dokuwiki-').'.ics';
      $now = new DateTime();
      $eventStr = $vcalendar->serialize();
      
      $values = array($calid,
                      $uri,
                      $eventStr,
                      $now->getTimestamp(),
                      'VEVENT',
                      $event->DTSTART->getDateTime()->getTimeStamp(),
                      $event->DTEND->getDateTime()->getTimeStamp(),
                      strlen($eventStr),
                      md5($eventStr),
                      uniqid()
      );
      
      $query = "INSERT INTO calendarobjects (calendarid, uri, calendardata, lastmodified, componenttype, firstoccurence, lastoccurence, size, etag, uid) VALUES (".$this->sqlite->quote_and_join($values, ',').")";
      $res = $this->sqlite->query($query);
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'added');
          return true;
      }
      return false;
  }

  public function getCalendarSettings($calid)
  {
      $query = "SELECT principaluri, displayname, uri, description, components, transparent, synctoken FROM calendars WHERE id=".$this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      return $row;
  }

  public function getEventsWithinDateRange($id, $user, $startDate, $endDate)
  {
      $settings = $this->getPersonalSettings($user);
      if($settings['timezone'] !== '' && $settings['timezone'] !== 'local')
          $timezone = new \DateTimeZone($settings['timezone']);
      else
          $timezone = new \DateTimeZone('UTC');
      $data = array();
      require_once('vendor/autoload.php');
      $calid = $this->getCalendarIdForPage($id);
      $startTs = new \DateTime($startDate);
      $endTs = new \DateTime($endDate);
      $query = "SELECT calendardata, componenttype, uid FROM calendarobjects WHERE calendarid=".
                $this->sqlite->quote_string($calid)." AND firstoccurence < ".
                $this->sqlite->quote_string($endTs->getTimestamp())." AND lastoccurence > ".
                $this->sqlite->quote_string($startTs->getTimestamp());
      $res = $this->sqlite->query($query);
      $arr = $this->sqlite->res2arr($res);
      foreach($arr as $row)
      {
          if(isset($row['calendardata']))
          {
              $entry = array();
              $vcal = \Sabre\VObject\Reader::read($row['calendardata']);
              $recurrence = $vcal->VEVENT->RRULE;
              if($recurrence != null)
              {
                  $rEvents = new \Sabre\VObject\Recur\EventIterator(array($vcal->VEVENT));
                  $rEvents->rewind();
                  $done = false;
                  while($rEvents->valid() && !$done)
                  {
                      $event = $rEvents->getEventObject();
                      if(($rEvents->getDtStart()->getTimestamp() > $endTs->getTimestamp()) &&
                         ($rEvents->getDtEnd()->getTimestamp() > $endTs->getTimestamp()))
                        $done = true;
                      if($rEvents->getDtEnd()->getTimestamp() < $startTs->getTimestamp())
                      {
                          $rEvents->next();
                          continue;
                      }
                      $data[] = $this->convertIcalDataToEntry($event, $timezone, $row['uid']);
                      $rEvents->next();
                  }
              }
              else
                $data[] = $this->convertIcalDataToEntry($vcal->VEVENT, $timezone, $row['uid']);
          }
      }
      return $data;
  }

  private function convertIcalDataToEntry($event, $timezone, $uid)
  {
      $entry = array();
      $start = $event->DTSTART;
      if($start !== null)
      {
        $dtStart = $start->getDateTime();
        $dtStart->setTimezone($timezone);
        $entry['start'] = $dtStart->format(\DateTime::ATOM);
        if($start['VALUE'] == 'DATE')
          $entry['allDay'] = true;
        else
          $entry['allDay'] = false;
      }
      $end = $event->DTEND;
      if($end !== null)
      {
        $dtEnd = $end->getDateTime();
        $dtEnd->setTimezone($timezone);
        $entry['end'] = $dtEnd->format(\DateTime::ATOM);
      }
      $description = $event->DESCRIPTION;
      if($description !== null)
        $entry['description'] = (string)$description;
      else
        $entry['description'] = '';
      $entry['title'] = (string)$event->summary;
      $entry['id'] = $uid;
      return $entry;
  }
  
  public function getEventWithUid($uid)
  {
      $query = "SELECT calendardata, calendarid, componenttype, uri FROM calendarobjects WHERE uid=".
                $this->sqlite->quote_string($uid);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      return $row;
  }
  
  public function getAllCalendarEvents($calid)
  {
      $query = "SELECT calendardata, uid, componenttype, uri FROM calendarobjects WHERE calendarid=".
               $this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      $arr = $this->sqlite->res2arr($res);
      return $arr;
  }
  
  public function editCalendarEntryForPage($id, $user, $params)
  {
      $settings = $this->getPersonalSettings($user);
      if($settings['timezone'] !== '' && $settings['timezone'] !== 'local')
          $timezone = new \DateTimeZone($settings['timezone']);
      elseif($settings['timezone'] === 'local')
          $timezone = new \DateTimeZone($params['detectedtz']);
      else
          $timezone = new \DateTimeZone('UTC');
      $startDate = explode('-', $params['eventfrom']);
      $startTime = explode(':', $params['eventfromtime']);
      $endDate = explode('-', $params['eventto']);
      $endTime = explode(':', $params['eventtotime']);
      $uid = $params['uid'];
      $event = $this->getEventWithUid($uid);
      require_once('vendor/autoload.php');
      if(!isset($event['calendardata']))
        return false;
      $uri = $event['uri'];
      $calid = $event['calendarid'];
      $vcal = \Sabre\VObject\Reader::read($event['calendardata']);
      $vevent = $vcal->VEVENT;
      $vevent->summary = $params['eventname'];
      $dtStamp = new \DateTime(null, new \DateTimeZone('UTC'));
      $description = $params['eventdescription'];
      $vevent->remove('DESCRIPTION');      
      $vevent->remove('DTSTAMP');
      $vevent->remove('LAST-MODIFIED');
      $vevent->add('DTSTAMP', $dtStamp);
      $vevent->add('LAST-MODIFIED', $dtStamp);
      if($description !== '')
        $vevent->add('DESCRIPTION', $description);      
      $dtStart = new \DateTime();
      $dtStart->setTimezone($timezone);      
      $dtStart->setDate(intval($startDate[0]), intval($startDate[1]), intval($startDate[2]));
      if($params['allday'] != '1')
        $dtStart->setTime(intval($startTime[0]), intval($startTime[1]), 0);
      $dtEnd = new \DateTime();
      $dtEnd->setTimezone($timezone);      
      $dtEnd->setDate(intval($endDate[0]), intval($endDate[1]), intval($endDate[2]));
      if($params['allday'] != '1')
        $dtEnd->setTime(intval($endTime[0]), intval($endTime[1]), 0);
      // According to the VCal spec, we need to add a whole day here
      if($params['allday'] == '1')
          $dtEnd->add(new \DateInterval('P1D'));
      $vevent->remove('DTSTART');
      $vevent->remove('DTEND');
      $dtStartEv = $vevent->add('DTSTART', $dtStart);
      $dtEndEv = $vevent->add('DTEND', $dtEnd);
      if($params['allday'] == '1')
      {
          $dtStartEv['VALUE'] = 'DATE';
          $dtEndEv['VALUE'] = 'DATE';
      }
      $now = new DateTime();
      $eventStr = $vcal->serialize();
      
      $query = "UPDATE calendarobjects SET calendardata=".$this->sqlite->quote_string($eventStr).
               ", lastmodified=".$this->sqlite->quote_string($now->getTimestamp()).
               ", firstoccurence=".$this->sqlite->quote_string($dtStart->getTimestamp()).
               ", lastoccurence=".$this->sqlite->quote_string($dtEnd->getTimestamp()).
               ", size=".strlen($eventStr).
               ", etag=".$this->sqlite->quote_string(md5($eventStr)).
               " WHERE uid=".$this->sqlite->quote_string($uid);
      $res = $this->sqlite->query($query);
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'modified');
          return true;
      }
      return false;
  }

  public function deleteCalendarEntryForPage($id, $params)
  {
      $uid = $params['uid'];
      $event = $this->getEventWithUid($uid);
      $calid = $event['calendarid'];
      $uri = $event['uri'];
      $query = "DELETE FROM calendarobjects WHERE uid=".$this->sqlite->quote_string($uid);
      $res = $this->sqlite->query($query);
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'deleted');
      }
      return true;
  }
  
  public function getSyncTokenForCalendar($calid)
  {
      $row = $this->getCalendarSettings($calid);
      if(isset($row['synctoken']))
          return $row['synctoken'];
      return false;
  }
  
  public function operationNameToOperation($operationName)
  {
      switch($operationName)
      {
          case 'added':
              return 1;
          break;
          case 'modified':
              return 2;
          break;
          case 'deleted':
              return 3;
          break;
      }
      return false;
  }
  
  private function updateSyncTokenLog($calid, $uri, $operation)
  {
      $currentToken = $this->getSyncTokenForCalendar($calid);
      $operationCode = $this->operationNameToOperation($operation);
      if(($operationCode === false) || ($currentToken === false))
          return false;
      $values = array($uri,
                      $currentToken,
                      $calid,
                      $operationCode
      );
      $query = "INSERT INTO calendarchanges (uri, synctoken, calendarid, operation) VALUES(".
               $this->sqlite->quote_and_join($values, ',').")";
      $res = $this->sqlite->query($query);
      if($res === false)
        return false;
      $currentToken++;
      $query = "UPDATE calendars SET synctoken=".$this->sqlite->quote_string($currentToken)." WHERE id=".
               $this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      return ($res !== false);
  }
  
  public function getSyncUrlForPage($id, $user = null)
  {
      if(is_null($user))
        $user = $_SERVER['REMOTE_USER'];
      
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;
      
      $calsettings = $this->getCalendarSettings($calid);
      if(!isset($calsettings['uri']))
        return false;
      
      $syncurl = DOKU_URL.'lib/plugins/davcal/calendarserver.php/calendars/'.$user.'/'.$calsettings['uri'];
      return $syncurl; 
  }
  
  public function getPrivateURLForPage($id)
  {
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;
      
      return $this->getPrivateURLForCalendar($calid);
  }
  
  public function getPrivateURLForCalendar($calid)
  {
      $query = "SELECT url FROM calendartoprivateurlmapping WHERE calid=".$this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      if(!isset($row['url']))
      {
          $url = uniqid("dokuwiki-").".ics";
          $values = array(
                $url,
                $calid
          );
          $query = "INSERT INTO calendartoprivateurlmapping (url, calid) VALUES(".
                $this->sqlite->quote_and_join($values, ", ").")";
          $res = $this->sqlite->query($query);
          if($res === false)
            return false;
      }
      else
      {
          $url = $row['url'];
      }
      return DOKU_URL.'lib/plugins/davcal/ics.php/'.$url;
  }
  
  public function getCalendarForPrivateURL($url)
  {
      $query = "SELECT calid FROM calendartoprivateurlmapping WHERE url=".$this->sqlite->quote_string($url);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      if(!isset($row['calid']))
        return false;
      return $row['calid'];
  }
  
  public function getCalendarAsICSFeed($calid)
  {
      $calSettings = $this->getCalendarSettings($calid);
      if($calSettings === false)
        return false;
      $events = $this->getAllCalendarEvents($calid);
      if($events === false)
        return false;
      
      $out = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//DAVCal//DAVCal for DokuWiki//EN\nCALSCALE:GREGORIAN\nX-WR-CALNAME:";
      $out .= $calSettings['displayname']."\n";
      foreach($events as $event)
      {
          $out .= rtrim($event['calendardata']);
          $out .= "\n";
      }
      $out .= "END:VCALENDAR\n";
      return $out;
  }
  
}
