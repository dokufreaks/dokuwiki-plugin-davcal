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
      
      // Update the calendar name here
      
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
                      0);
      $query = "INSERT INTO calendars (principaluri, displayname, uri, description, components, transparent) VALUES (".$this->sqlite->quote_and_join($values, ',').");";
      $res = $this->sqlite->query($query);
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
          return true;
      }
      
      return false;
  }

  public function addCalendarEntryToCalendarForPage($id, $user, $params)
  {
      require_once('vendor/autoload.php');
      $vcalendar = new \Sabre\VObject\Component\VCalendar();
      $event = $vcalendar->add('VEVENT');
      $event->summary = $params['eventname'];
      $dtStart = new \DateTime($params['eventfrom'], new \DateTimeZone('Europe/Vienna')); // FIXME: Timezone
      $dtEnd = new \DateTime($params['eventto'], new \DateTimeZone('Europe/Vienna')); // FIXME: Timezone
      $event->DTSTART = $dtStart;
      $event->DTEND = $dtEnd;
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
      return true;
  }

  public function getEventsWithinDateRange($id, $user, $startDate, $endDate)
  {
      $data = array();
      require_once('vendor/autoload.php');
      $calid = $this->getCalendarIdForPage($id);
      $startTs = new \DateTime($startDate);
      $endTs = new \DateTime($endDate);
      $query = "SELECT calendardata, componenttype, uid FROM calendarobjects WHERE calendarid=".
                $this->sqlite->quote_string($calid)." AND firstoccurence > ".
                $this->sqlite->quote_string($startTs->getTimestamp())." AND firstoccurence < ".
                $this->sqlite->quote_string($endTs->getTimestamp());
      $res = $this->sqlite->query($query);
      $arr = $this->sqlite->res2arr($res);
      foreach($arr as $row)
      {
          if(isset($row['calendardata']))
          {
              $vcal = \Sabre\VObject\Reader::read($row['calendardata']);
              $start = $vcal->VEVENT->DTSTART->getDateTime();
              $end = $vcal->VEVENT->DTEND->getDateTime();
              $summary = (string)$vcal->VEVENT->summary;
              $data[] = array("title" => $summary, "start" => $start->format(\DateTime::W3C),
                              "end" => $end->format(\DateTime::W3C),
                              "id" => $row['uid']);
          }
      }
      return $data;
  }
  
  public function getEventWithUid($uid)
  {
      $query = "SELECT calendardata, componenttype FROM calendarobjects WHERE uid=".
                $this->sqlite->quote_string($uid);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      return $row;
  }
  
  public function editCalendarEntryForPage($id, $user, $params)
  {
      $event = $this->getEventWithUid($params['uid']);
      require_once('vendor/autoload.php');
      if(!isset($event['calendardata']))
        return false;
      $vcal = \Sabre\VObject\Reader::read($event['calendardata']);
      $vcal->VEVENT->summary = $params['eventname'];
      $dtStart = new \DateTime($params['eventfrom'], new \DateTimeZone('Europe/Vienna')); // FIXME: Timezone
      $dtEnd = new \DateTime($params['eventto'], new \DateTimeZone('Europe/Vienna')); // FIXME: Timezone
      $vcal->VEVENT->DTSTART = $dtStart;
      $vcal->VEVENT->DTEND = $dtEnd;
      $now = new DateTime();
      $eventStr = $vcal->serialize();
      
      $query = "UPDATE calendarobjects SET calendardata=".$this->sqlite->quote_string($eventStr).
               ", lastmodified=".$this->sqlite->quote_string($now->getTimestamp()).
               ", firstoccurence=".$this->sqlite->quote_string($dtStart->getTimestamp()).
               ", lastoccurence=".$this->sqlite->quote_string($dtEnd->getTimestamp()).
               ", size=".strlen($eventStr).
               ", etag=".$this->sqlite->quote_string(md5($eventStr)).
               " WHERE uid=".$this->sqlite->quote_string($params['uid']);
      $res = $this->sqlite->query($query);
      return true;
  }

  public function deleteCalendarEntryForPage($id, $params)
  {
      $uid = $params['uid'];
      $query = "DELETE FROM calendarobjects WHERE uid=".$this->sqlite->quote_string($uid);
      $res = $this->sqlite->query($query);
      return true;
  }
  
}
