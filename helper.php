<?php
/**
  * Helper Class for the DAVCal plugin
  * This helper does the actual work.
  *
  */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class helper_plugin_davcal extends DokuWiki_Plugin {
  protected $sqlite = null;
  protected $cachedValues = array();

  /**
    * Constructor to load the configuration and the SQLite plugin
    */
  public function __construct() {
  }

  /** Establish and initialize the database if not already done
   * @return sqlite interface or false
   */
  private function getDB()
  {
      if($this->sqlite === null)
      {
        $this->sqlite = new \dokuwiki\plugin\sqlite\SQLiteDB('davcal', DOKU_PLUGIN.'davcal/db/');
        if(!$this->sqlite)
        {
            \dokuwiki\Logger::error('DAVCAL', 'This plugin requires the sqlite plugin. Please install it.', __FILE__, __LINE__);
            msg('This plugin requires the sqlite plugin. Please install it.', -1);
            return false;
        }
      }
      return $this->sqlite;
  }

  /**
   * Retrieve meta data for a given page
   *
   * @param string $id optional The page ID
   * @return array The metadata
   */
  private function getMeta($id = null) {
    global $ID;
    global $INFO;

    if ($id === null) $id = $ID;

    if($ID === $id && $INFO['meta']) {
        $meta = $INFO['meta'];
    } else {
        $meta = p_get_metadata($id);
    }

    return $meta;
  }

  /**
   * Retrieve the meta data for a given page
   *
   * @param string $id optional The page ID
   * @return array with meta data
   */
  public function getCalendarMetaForPage($id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }

      $meta = $this->getMeta($id);
      if(isset($meta['plugin_davcal']))
        return $meta['plugin_davcal'];
      else
        return array();
  }

  /**
   * Check the permission of a user for a given calendar ID
   *
   * @param string $id The calendar ID to check
   * @return int AUTH_* constants
   */
  public function checkCalendarPermission($id)
  {
      if(strpos($id, 'webdav://') === 0)
      {
          $wdc =& plugin_load('helper', 'webdavclient');
          if(is_null($wdc))
            return AUTH_NONE;
          $connectionId = str_replace('webdav://', '', $id);
          $settings = $wdc->getConnection($connectionId);
          if($settings === false)
            return AUTH_NONE;
          // Check if webdavclient has permissions attached to a page
          if(!empty($settings['permission']))
          {
            $perm = auth_quickaclcheck($settings['permission']);
            // In case the page has more than read permission, but the
            // calendar is read-only, we need to modify the permission here.
            if($perm > AUTH_READ && $settings['write'] == 0)
              $perm = AUTH_READ;
            return $perm;
          }
          if($settings['write'] === '1')
            return AUTH_CREATE;
          return AUTH_READ;
      }
      else
      {
          $calid = $this->getCalendarIdForPage($id);
          // We return AUTH_READ if the calendar does not exist. This makes
          // davcal happy when there are just included calendars
          if($calid === false)
            return AUTH_READ;
          return auth_quickaclcheck($id);
      }
  }

  /**
   * Filter calendar pages and return only those where the current
   * user has at least read permission.
   *
   * @param array $calendarPages Array with calendar pages to check
   * @return array with filtered calendar pages
   */
  public function filterCalendarPagesByUserPermission($calendarPages)
  {
      $retList = array();
      foreach($calendarPages as $page => $data)
      {
          if($this->checkCalendarPermission($page) >= AUTH_READ)
          {
              $retList[$page] = $data;
          }
      }
      return $retList;
  }

  /**
   * Get all calendar pages used by a given page
   * based on the stored metadata
   *
   * @param string $id optional The page id
   * @param bool $skipPermissionCheck optional Skip permission filtering (for private URLs)
   * @return mixed The pages as array or false
   */
  public function getCalendarPagesByMeta($id = null, $skipPermissionCheck = false)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }

      $meta = $this->getCalendarMetaForPage($id);

      if(isset($meta['id']))
      {
          if($skipPermissionCheck)
          {
              // For private URLs, return all pages without permission filtering
              return $meta['id'];
          }
          else
          {
              // Filter the list of pages by permission
              $pages = $this->filterCalendarPagesByUserPermission($meta['id']);
              if(empty($pages))
                return false;
              return $pages;
          }
      }
      return false;
  }

  /**
   * Get a list of calendar names/pages/ids/colors
   * for an array of page ids
   *
   * @param array $calendarPages The calendar pages to retrieve
   * @return array The list
   */
  public function getCalendarMapForIDs($calendarPages)
  {
      $data = array();
      foreach($calendarPages as $page => $color)
      {
            if(strpos($page, 'webdav://') === 0)
            {
                $wdc =& plugin_load('helper', 'webdavclient');
                if(is_null($wdc))
                    continue;
                $connectionId = str_replace('webdav://', '', $page);
                $settings = $wdc->getConnection($connectionId);
                if($settings === false)
                    continue;
                $name = $settings['displayname'];
                $write = ($settings['write'] === '1');
                $calid = $connectionId;
                $color = '#3a87ad';
            }
            else
            {
                $calid = $this->getCalendarIdForPage($page);
                if($calid !== false)
                {
                    $settings = $this->getCalendarSettings($calid);
                    $name = $settings['displayname'];
                    $color = $settings['calendarcolor'];
                    $write = (auth_quickaclcheck($page) > AUTH_READ);
                }
                else
                {
                    continue;
                }
            }
            $data[] = array('name' => $name, 'page' => $page, 'calid' => $calid,
                            'color' => $color, 'write' => $write);
      }
      return $data;
  }

  /**
   * Get the saved calendar color for a given page.
   *
   * @param string $id optional The page ID
   * @return mixed The color on success, otherwise false
   */
  public function getCalendarColorForPage($id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }

      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;

      return $this->getCalendarColorForCalendar($calid);
  }

  /**
   * Get the saved calendar color for a given calendar ID.
   *
   * @param string $id optional The calendar ID
   * @return mixed The color on success, otherwise false
   */
  public function getCalendarColorForCalendar($calid)
  {
      if(isset($this->cachedValues['calendarcolor'][$calid]))
        return $this->cachedValues['calendarcolor'][$calid];

      $row = $this->getCalendarSettings($calid);

      if(!isset($row['calendarcolor']))
        return false;

      $color = $row['calendarcolor'];
      $this->cachedValues['calendarcolor'][$calid] = $color;
      return $color;
  }

  /**
   * Get the user's principal URL for iOS sync
   * @param string $user the user name
   * @return the URL to the principal sync
   */
  public function getPrincipalUrlForUser($user)
  {
      if(is_null($user))
        return false;
      $url = DOKU_URL.'lib/plugins/davcal/calendarserver.php/principals/'.$user;
      return $url;
  }

  /**
   * Set the calendar color for a given page.
   *
   * @param string $color The color definition
   * @param string $id optional The page ID
   * @return boolean True on success, otherwise false
   */
  public function setCalendarColorForPage($color, $id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "UPDATE calendars SET calendarcolor = ? ".
               " WHERE id = ?";
      $res = $sqlite->query($query, $color, $calid);
      if($res !== false)
      {
        $this->cachedValues['calendarcolor'][$calid] = $color;
        return true;
      }
      return false;
  }

  /**
   * Set the calendar name and description for a given page with a given
   * page id.
   * If the calendar doesn't exist, the calendar is created!
   *
   * @param string  $name The name of the new calendar
   * @param string  $description The description of the new calendar
   * @param string  $id (optional) The ID of the page
   * @param string  $userid The userid of the creating user
   *
   * @return boolean True on success, otherwise false.
   */
  public function setCalendarNameForPage($name, $description, $id = null, $userid = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      if(is_null($userid))
      {
        if(isset($_SERVER['REMOTE_USER']) && !is_null($_SERVER['REMOTE_USER']))
        {
          $userid = $_SERVER['REMOTE_USER'];
        }
        else
        {
          $userid = uniqid('davcal-');
        }
      }
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return $this->createCalendarForPage($name, $description, $id, $userid);

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "UPDATE calendars SET displayname = ?, description = ? WHERE id = ?";
      $res = $sqlite->query($query, $name, $description, $calid);
      if($res !== false)
        return true;
      return false;
  }

  /**
   * Update a calendar's displayname
   *
   * @param int $calid The calendar's ID
   * @param string $name The new calendar name
   *
   * @return boolean True on success, otherwise false
   */
  public function updateCalendarName($calid, $name)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "UPDATE calendars SET displayname = ? WHERE id = ?";
      $res = $sqlite->query($query, $calid, $name);
      if($res !== false)
      {
        $this->updateSyncTokenLog($calid, '', 'modified');
        return true;
      }
      return false;
  }

  /**
   * Update the calendar description
   *
   * @param int $calid The calendar's ID
   * @param string $description The new calendar's description
   *
   * @return boolean True on success, otherwise false
   */
  public function updateCalendarDescription($calid, $description)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "UPDATE calendars SET description = ? WHERE id = ?";
      $res = $sqlite->query($query, $calid, $description);
      if($res !== false)
      {
        $this->updateSyncTokenLog($calid, '', 'modified');
        return true;
      }
      return false;
  }

  /**
   * Update a calendar's timezone information
   *
   * @param int $calid The calendar's ID
   * @param string $timezone The new timezone to set
   *
   * @return boolean True on success, otherwise false
   */
  public function updateCalendarTimezone($calid, $timezone)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "UPDATE calendars SET timezone = ? WHERE id = ?";
      $res = $sqlite->query($query, $calid, $timezone);
      if($res !== false)
      {
        $this->updateSyncTokenLog($calid, '', 'modified');
        return true;
      }
      return false;
  }

  /**
   * Save the personal settings to the SQLite database 'calendarsettings'.
   *
   * @param array  $settings The settings array to store
   * @param string $userid (optional) The userid to store
   *
   * @param boolean True on success, otherwise false
   */
  public function savePersonalSettings($settings, $userid = null)
  {
      if(is_null($userid))
      {
          if(isset($_SERVER['REMOTE_USER']) && !is_null($_SERVER['REMOTE_USER']))
          {
            $userid = $_SERVER['REMOTE_USER'];
          }
          else
          {
              return false;
          }
      }
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $sqlite->query("BEGIN TRANSACTION");

      $query = "DELETE FROM calendarsettings WHERE userid = ?";
      $sqlite->query($query, $userid);

      foreach($settings as $key => $value)
      {
          $query = "INSERT INTO calendarsettings (userid, key, value) VALUES (?, ?, ?)";
          $res = $sqlite->query($query, $userid, $key, $value);
          if($res === false)
              return false;
      }
      $sqlite->query("COMMIT TRANSACTION");
      $this->cachedValues['settings'][$userid] = $settings;
      return true;
  }

  /**
   * Retrieve the settings array for a given user id.
   * Some sane defaults are returned, currently:
   *
   *    timezone    => local
   *    weeknumbers => 0
   *    workweek    => 0
   *
   * @param string $userid (optional) The user id to retrieve
   *
   * @return array The settings array
   */
  public function getPersonalSettings($userid = null)
  {
      $settings = array(
        'timezone' => $this->getConf('timezone'),
        'weeknumbers' => $this->getConf('weeknumbers'),
        'workweek' => $this->getConf('workweek'),
        'monday' => $this->getConf('monday'),
        'timeformat' => $this->getConf('timeformat')
      );
      if(is_null($userid))
      {
          if(isset($_SERVER['REMOTE_USER']) && !is_null($_SERVER['REMOTE_USER']))
          {
            $userid = $_SERVER['REMOTE_USER'];
          }
          else
          {
            return $settings;
          }
      }

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      if(isset($this->cachedValues['settings'][$userid]))
        return $this->cachedValues['settings'][$userid];
      $query = "SELECT key, value FROM calendarsettings WHERE userid = ?";
      $arr = $sqlite->queryAll($query, [$userid]);
      foreach($arr as $row)
      {
          $settings[$row['key']] = $row['value'];
      }
      $this->cachedValues['settings'][$userid] = $settings;
      return $settings;
  }

  /**
   * Retrieve the calendar ID based on a page ID from the SQLite table
   * 'pagetocalendarmapping'.
   *
   * @param string $id (optional) The page ID to retrieve the corresponding calendar
   *
   * @return mixed the ID on success, otherwise false
   */
  public function getCalendarIdForPage($id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }

      if(isset($this->cachedValues['calid'][$id]))
        return $this->cachedValues['calid'][$id];

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT calid FROM pagetocalendarmapping WHERE page = ?";
      $row = $sqlite->queryRecord($query, [$id]);
      if(isset($row['calid']))
      {
        $calid = $row['calid'];
        $this->cachedValues['calid'] = $calid;
        return $calid;
      }
      return false;
  }

  /**
   * Retrieve the complete calendar id to page mapping.
   * This is necessary to be able to retrieve a list of
   * calendars for a given user and check the access rights.
   *
   * @return array The mapping array
   */
  public function getCalendarIdToPageMapping()
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT calid, page FROM pagetocalendarmapping";
      $arr = $sqlite->queryAll($query, []);
      return $arr;
  }

  /**
   * Retrieve all calendar IDs a given user has access to.
   * The user is specified by the principalUri, so the
   * user name is actually split from the URI component.
   *
   * Access rights are checked against DokuWiki's ACL
   * and applied accordingly.
   *
   * @param string $principalUri The principal URI to work on
   *
   * @return array An associative array of calendar IDs
   */
  public function getCalendarIdsForUser($principalUri)
  {
      global $auth;
      $user = explode('/', $principalUri);
      $user = end($user);
      $mapping = $this->getCalendarIdToPageMapping();
      $calids = array();
      $ud = $auth->getUserData($user);
      $groups = $ud['grps'];
      foreach($mapping as $row)
      {
          $id = $row['calid'];
          $enabled = $this->getCalendarStatus($id);
          if($enabled == false)
            continue;
          $page = $row['page'];
          $acl = auth_aclcheck($page, $user, $groups);
          if($acl >= AUTH_READ)
          {
              $write = $acl > AUTH_READ;
              $calids[$id] = array('readonly' => !$write);
          }
      }
      return $calids;
  }

  /**
   * Create a new calendar for a given page ID and set name and description
   * accordingly. Also update the pagetocalendarmapping table on success.
   *
   * @param string $name The calendar's name
   * @param string $description The calendar's description
   * @param string $id (optional) The page ID to work on
   * @param string $userid (optional) The user ID that created the calendar
   *
   * @return boolean True on success, otherwise false
   */
  public function createCalendarForPage($name, $description, $id = null, $userid = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      if(is_null($userid))
      {
        if(isset($_SERVER['REMOTE_USER']) && !is_null($_SERVER['REMOTE_USER']))
        {
          $userid = $_SERVER['REMOTE_USER'];
        }
        else
        {
          $userid = uniqid('davcal-');
        }
      }
      $values = array('principals/'.$userid,
                      $name,
                      str_replace(array('/', ' ', ':'), '_', $id),
                      $description,
                      'VEVENT,VTODO',
                      0,
                      1);

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "INSERT INTO calendars (principaluri, displayname, uri, description, components, transparent, synctoken) ".
               "VALUES (?, ?, ?, ?, ?, ?, ?)";
      $sqlite->query($query, $values);

      $query = "SELECT id FROM calendars WHERE principaluri = ? AND displayname = ? AND ".
               "uri = ? AND description = ?";
      $row = $sqlite->queryRecord($query, array($values[0], $values[1], $values[2], $values[3]));

      if(isset($row['id']))
      {
          $query = "INSERT INTO pagetocalendarmapping (page, calid) VALUES (?, ?)";
          $sqlite->query($query, array($id, $row['id']));
          return true;
      }

      return false;
  }

  /**
   * Add a new calendar entry to the given calendar. Calendar data is
   * specified as ICS file, thus it needs to be parsed first.
   *
   * This is mainly needed for the sync support.
   *
   * @param int $calid The calendar's ID
   * @param string $uri The new object URI
   * @param string $ics The ICS file
   *
   * @return mixed The etag.
   */
  public function addCalendarEntryToCalendarByICS($calid, $uri, $ics)
  {
    $extraData = $this->getDenormalizedData($ics);

    $sqlite = $this->getDB();
    if(!$sqlite)
      return false;
    $query = "INSERT INTO calendarobjects (calendarid, uri, calendardata, lastmodified, etag, size, componenttype, firstoccurence, lastoccurence, uid) VALUES (?,?,?,?,?,?,?,?,?,?)";
    $res = $sqlite->query($query,
            $calid,
            $uri,
            $ics,
            time(),
            $extraData['etag'],
            $extraData['size'],
            $extraData['componentType'],
            $extraData['firstOccurence'],
            $extraData['lastOccurence'],
            $extraData['uid']);
            // If successfully, update the sync token database
    if($res !== false)
    {
        $this->updateSyncTokenLog($calid, $uri, 'added');
    }
    return $extraData['etag'];
  }

  /**
   * Edit a calendar entry by providing a new ICS file. This is mainly
   * needed for the sync support.
   *
   * @param int $calid The calendar's IS
   * @param string $uri The object's URI to modify
   * @param string $ics The new object's ICS file
   */
  public function editCalendarEntryToCalendarByICS($calid, $uri, $ics)
  {
      $extraData = $this->getDenormalizedData($ics);

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "UPDATE calendarobjects SET calendardata = ?, lastmodified = ?, etag = ?, size = ?, componenttype = ?, firstoccurence = ?, lastoccurence = ?, uid = ? WHERE calendarid = ? AND uri = ?";
      $res = $sqlite->query($query,
        $ics,
        time(),
        $extraData['etag'],
        $extraData['size'],
        $extraData['componentType'],
        $extraData['firstOccurence'],
        $extraData['lastOccurence'],
        $extraData['uid'],
        $calid,
        $uri
      );
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'modified');
      }
      return $extraData['etag'];
  }

  /**
   * Add a new iCal entry for a given page, i.e. a given calendar.
   *
   * The parameter array needs to contain
   *   detectedtz       => The timezone as detected by the browser
   *   currenttz        => The timezone in use by the calendar
   *   eventfrom        => The event's start date
   *   eventfromtime    => The event's start time
   *   eventto          => The event's end date
   *   eventtotime      => The event's end time
   *   eventname        => The event's name
   *   eventdescription => The event's description
   *
   * @param string $id The page ID to work on
   * @param string $user The user who created the calendar
   * @param string $params A parameter array with values to create
   *
   * @return boolean True on success, otherwise false
   */
  public function addCalendarEntryToCalendarForPage($id, $user, $params)
  {
      if($params['currenttz'] !== '' && $params['currenttz'] !== 'local')
          $timezone = new \DateTimeZone($params['currenttz']);
      elseif($params['currenttz'] === 'local')
          $timezone = new \DateTimeZone($params['detectedtz']);
      else
          $timezone = new \DateTimeZone('UTC');

      // Retrieve dates from settings
      $startDate = explode('-', $params['eventfrom']);
      $startTime = explode(':', $params['eventfromtime']);
      $endDate = explode('-', $params['eventto']);
      $endTime = explode(':', $params['eventtotime']);

      // Load SabreDAV
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');
      $vcalendar = new \Sabre\VObject\Component\VCalendar();

      // Add VCalendar, UID and Event Name
      $event = $vcalendar->add('VEVENT');
      $uuid = \Sabre\VObject\UUIDUtil::getUUID();
      $event->add('UID', $uuid);
      $event->summary = $params['eventname'];

      // Add a description if requested
      $description = $params['eventdescription'];
      if($description !== '')
        $event->add('DESCRIPTION', $description);

      // Add a location if requested
      $location = $params['eventlocation'];
      if($location !== '')
        $event->add('LOCATION', $location);

      // Add attachments
      $attachments = $params['attachments'];
      if(!is_null($attachments))
        foreach($attachments as $attachment)
          $event->add('ATTACH', $attachment);

      // Create a timestamp for last modified, created and dtstamp values in UTC
      $dtStamp = new \DateTime(null, new \DateTimeZone('UTC'));
      $event->add('DTSTAMP', $dtStamp);
      $event->add('CREATED', $dtStamp);
      $event->add('LAST-MODIFIED', $dtStamp);

      // Adjust the start date, based on the given timezone information
      $dtStart = new \DateTime();
      $dtStart->setTimezone($timezone);
      $dtStart->setDate(intval($startDate[0]), intval($startDate[1]), intval($startDate[2]));

      // Only add the time values if it's not an allday event
      if($params['allday'] != '1')
        $dtStart->setTime(intval($startTime[0]), intval($startTime[1]), 0);

      // Adjust the end date, based on the given timezone information
      $dtEnd = new \DateTime();
      $dtEnd->setTimezone($timezone);
      $dtEnd->setDate(intval($endDate[0]), intval($endDate[1]), intval($endDate[2]));

      // Only add the time values if it's not an allday event
      if($params['allday'] != '1')
        $dtEnd->setTime(intval($endTime[0]), intval($endTime[1]), 0);

      // According to the VCal spec, we need to add a whole day here
      if($params['allday'] == '1')
          $dtEnd->add(new \DateInterval('P1D'));

      // Really add Start and End events
      $dtStartEv = $event->add('DTSTART', $dtStart);
      $dtEndEv = $event->add('DTEND', $dtEnd);

      // Adjust the DATE format for allday events
      if($params['allday'] == '1')
      {
          $dtStartEv['VALUE'] = 'DATE';
          $dtEndEv['VALUE'] = 'DATE';
      }

      // Add recurring rule if specified (AFTER dates are set)
      if(isset($params['recurrence']) && !empty($params['recurrence']))
      {
          $recurrence = $params['recurrence'];
          $rrule = '';

          switch($recurrence)
          {
              case 'DAILY':
                  $rrule = 'FREQ=DAILY';
                  break;
              case 'WEEKLY':
                  $rrule = 'FREQ=WEEKLY';
                  break;
              case 'BIWEEKLY':
                  $rrule = 'FREQ=WEEKLY;INTERVAL=2';
                  break;
              case 'MONTHLY':
                  $rrule = 'FREQ=MONTHLY';
                  break;
              case 'YEARLY':
                  $rrule = 'FREQ=YEARLY';
                  break;
          }

          if(!empty($rrule))
          {
              if($recurrence === 'MONTHLY' && (empty($params['recurrence_monthly_mode']) || $params['recurrence_monthly_mode'] === 'weekday') && !empty($params['recurrence_byday']) && !empty($params['recurrence_bysetpos']))
              {
                  $rrule .= ';BYDAY=' . $params['recurrence_byday'] . ';BYSETPOS=' . $params['recurrence_bysetpos'];
              }
              // 'date' mode: just FREQ=MONTHLY, iCal repeats on same day of month by default
              elseif(($recurrence === 'WEEKLY' || $recurrence === 'BIWEEKLY') && !empty($params['recurrence_weekday']))
              {
                  $rrule .= ';BYDAY=' . $params['recurrence_weekday'];
              }
              $event->add('RRULE', $rrule);
          }
      }

      // Add public flag if specified
      if(!empty($params['public']) && $params['public'] == '1')
          $event->add('X-DAVCAL-PUBLIC', '1');

      // Add members flag/count if specified
      if(!empty($params['members']) && $params['members'] == '1')
          $event->add('X-DAVCAL-MEMBERS', '1');

      $eventStr = $vcalendar->serialize();

      if(strpos($id, 'webdav://') === 0)
      {
          $wdc =& plugin_load('helper', 'webdavclient');
          if(is_null($wdc))
            return false;
          $connectionId = str_replace('webdav://', '', $id);
          return $wdc->addCalendarEntry($connectionId, $eventStr);
      }
      else
      {
          // Actually add the values to the database
          $calid = $this->getCalendarIdForPage($id);
          $uri = $uri = 'dokuwiki-' . bin2hex(random_bytes(16)) . '.ics';
          $now = new \DateTime();

          $sqlite = $this->getDB();
          if(!$sqlite)
            return false;
          $query = "INSERT INTO calendarobjects (calendarid, uri, calendardata, lastmodified, componenttype, firstoccurence, lastoccurence, size, etag, uid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $extraData = $this->getDenormalizedData($eventStr);
          $res = $sqlite->query($query, $calid, $uri, $eventStr, $now->getTimestamp(), 'VEVENT',
                                $extraData['firstOccurence'], $extraData['lastOccurence'],
                                strlen($eventStr), md5($eventStr), $uuid);

          // If successfully, update the sync token database
          if($res !== false)
          {
              $this->updateSyncTokenLog($calid, $uri, 'added');
              return true;
          }
      }
      return false;
  }

  /**
   * Retrieve the calendar settings of a given calendar id
   *
   * @param string $calid The calendar ID
   *
   * @return array The calendar settings array
   */
  public function getCalendarSettings($calid)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT id, principaluri, calendarcolor, displayname, uri, description, components, transparent, synctoken, disabled, timezone FROM calendars WHERE id= ? ";
      $row = $sqlite->queryRecord($query, [$calid]);
      return $row;
  }

  /**
   * Retrieve the calendar status of a given calendar id
   *
   * @param string $calid The calendar ID
   * @return boolean True if calendar is enabled, otherwise false
   */
  public function getCalendarStatus($calid)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT disabled FROM calendars WHERE id = ?";
      $row = $sqlite->queryRecord($query, [$calid]);
      if($row && $row['disabled'] == 1)
        return false;
      else
        return true;
  }

  /**
   * Disable a calendar for a given page
   *
   * @param string $id The page ID
   *
   * @return boolean true on success, otherwise false
   */
  public function disableCalendarForPage($id)
  {
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "UPDATE calendars SET disabled = 1 WHERE id = ?";
      $res = $sqlite->query($query, $calid);
      if($res !== false)
        return true;
      return false;
  }

  /**
   * Enable a calendar for a given page
   *
   * @param string $id The page ID
   *
   * @return boolean true on success, otherwise false
   */
  public function enableCalendarForPage($id)
  {
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "UPDATE calendars SET disabled = 0 WHERE id = ?";
      $res = $sqlite->query($query, $calid);
      if($res !== false)
        return true;
      return false;
  }

  /**
   * Retrieve all events that are within a given date range,
   * based on the timezone setting.
   *
   * There is also support for retrieving recurring events,
   * using Sabre's VObject Iterator. Recurring events are represented
   * as individual calendar entries with the same UID.
   *
   * @param string $id The page ID to work with
   * @param string $user The user ID to work with
   * @param string $startDate The start date as a string
   * @param string $endDate The end date as a string
   * @param string $color (optional) The calendar's color
   *
   * @return array An array containing the calendar entries.
   */
  public function getEventsWithinDateRange($id, $user, $startDate, $endDate, $timezone, $color = null)
  {
      if($timezone !== '' && $timezone !== 'local')
          $timezone = new \DateTimeZone($timezone);
      else
          $timezone = new \DateTimeZone('UTC');
      $data = array();
      $calname = 'unknown';
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;

      $calid = $this->getCalendarIdForPage($id);
      $query = "SELECT calendardata, componenttype, uid FROM calendarobjects WHERE calendarid = ?";
      $params = array($calid);
      $startTs = null;
      $endTs = null;
      if($startDate !== null)
      {
          $startTs = new \DateTime($startDate);
          $query .= " AND lastoccurence > ?";
          $params[] = $startTs->getTimestamp();
      }
      if($endDate !== null)
      {
          $endTs = new \DateTime($endDate);
          $query .= " AND firstoccurence < ?";
          $params[] = $endTs->getTimestamp();
      }

      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');

      if(strpos($id, 'webdav://') === 0)
      {
          $wdc =& plugin_load('helper', 'webdavclient');
          if(is_null($wdc))
            return $data;
          $connectionId = str_replace('webdav://', '', $id);
          $arr = $wdc->getCalendarEntries($connectionId, $startDate, $endDate);
          $conn = $wdc->getConnection($connectionId);
          $calname = $conn['displayname'];
      }
      else
      {
          if(is_null($color))
            $color = $this->getCalendarColorForCalendar($calid);

          $enabled = $this->getCalendarStatus($calid);
          if($enabled === false)
            return $data;

          $settings = $this->getCalendarSettings($calid);
          $calname = $settings['displayname'];

          $arr = $sqlite->queryAll($query, $params);
      }

      foreach($arr as $row)
      {
          if(isset($row['calendardata']))
          {
              $entry = array();
              $vcal = \Sabre\VObject\Reader::read($row['calendardata']);
              $recurrence = $vcal->VEVENT->RRULE;
              if($recurrence != null)
              {
                  // The whole series lives in one calendarobjects row, master
                  // VEVENT plus any RECURRENCE-ID overrides. All of them must
                  // be handed to the iterator, otherwise moved occurrences are
                  // silently ignored and the RRULE date is shown instead.
                  $seriesEvents = array();
                  foreach($vcal->VEVENT as $seriesEvent)
                      $seriesEvents[] = $seriesEvent;
                  $rEvents = new \Sabre\VObject\Recur\EventIterator($seriesEvents);
                  $rEvents->rewind();
                  $count = 0;
                  while($rEvents->valid() && $count < 500)
                  {
                      $count++;
                      $occurrenceStart = $rEvents->getDtStart();
                      $occurrenceEnd = $rEvents->getDtEnd();

                      // If we're past the end date, stop iterating
                      if(($endTs !== null) && ($occurrenceStart->getTimestamp() > $endTs->getTimestamp()))
                      {
                          break;
                      }

                      // Skip if this occurrence ends before our start date
                      if(($startTs !== null) && ($occurrenceEnd->getTimestamp() < $startTs->getTimestamp()))
                      {
                          $rEvents->next();
                          continue;
                      }

                      // This occurrence is within our range, add it
                      $event = $rEvents->getEventObject();
                      $occurrenceEntry = $this->convertIcalDataToEntry($event, $id, $timezone, $row['uid'], $color, true);
                      // For an occurrence that has already been overridden, hand
                      // the original RECURRENCE-ID back to the client. Editing it
                      // again must key off that value, not off the occurrence's
                      // current start, otherwise the next save writes an override
                      // pointing at a date the RRULE never produces.
                      if(isset($event->{'RECURRENCE-ID'}))
                          $occurrenceEntry['recurrenceid'] = $event->{'RECURRENCE-ID'}->getDateTime()->format(\DateTime::ATOM);
                      // Occurrence objects from EventIterator may not carry RRULE,
                      // so fall back to the master VEVENT for recurrence info
                      if(empty($occurrenceEntry['recurrence']))
                      {
                          $masterRrule = $vcal->VEVENT->RRULE;
                          if($masterRrule !== null)
                          {
                              $rruleStr = (string)$masterRrule;
                              if(strpos($rruleStr, 'FREQ=DAILY') !== false)
                              {
                                  $occurrenceEntry['recurrence'] = 'DAILY';
                                  $occurrenceEntry['recurrence_weekday'] = '';
                              }
                              elseif(strpos($rruleStr, 'FREQ=WEEKLY') !== false)
                              {
                                  if(strpos($rruleStr, 'INTERVAL=2') !== false)
                                      $occurrenceEntry['recurrence'] = 'BIWEEKLY';
                                  else
                                      $occurrenceEntry['recurrence'] = 'WEEKLY';
                                  if(preg_match('/BYDAY=([A-Z]+)/', $rruleStr, $m))
                                      $occurrenceEntry['recurrence_weekday'] = $m[1];
                                  else
                                      $occurrenceEntry['recurrence_weekday'] = '';
                              }
                              elseif(strpos($rruleStr, 'FREQ=MONTHLY') !== false)
                              {
                                  $occurrenceEntry['recurrence'] = 'MONTHLY';
                                  if(preg_match('/BYDAY=([A-Z]+)/', $rruleStr, $m))
                                      $occurrenceEntry['recurrence_byday'] = $m[1];
                                  if(preg_match('/BYSETPOS=(-?[0-9]+)/', $rruleStr, $m))
                                      $occurrenceEntry['recurrence_bysetpos'] = $m[1];
                              }
                              elseif(strpos($rruleStr, 'FREQ=YEARLY') !== false)
                              {
                                  $occurrenceEntry['recurrence'] = 'YEARLY';
                                  $occurrenceEntry['recurrence_weekday'] = '';
                              }
                          }
                      }
                      $data[] = array_merge(array('calendarname' => $calname), $occurrenceEntry);
                      $rEvents->next();
                  }
              }
              else
                  $data[] = array_merge(array('calendarname' => $calname),
                                        $this->convertIcalDataToEntry($vcal->VEVENT, $id, $timezone, $row['uid'], $color));
          }
      }
      return $data;
  }

  /**
   * Helper function that parses the iCal data of a VEVENT to a calendar entry.
   *
   * @param \Sabre\VObject\VEvent $event The event to parse
   * @param \DateTimeZone $timezone The timezone object
   * @param string $uid The entry's UID
   * @param boolean $recurring (optional) Set to true to define a recurring event
   *
   * @return array The parse calendar entry
   */
  private function convertIcalDataToEntry($event, $page, $timezone, $uid, $color, $recurring = false)
  {
      $entry = array();
      $start = $event->DTSTART;
      // Parse only if the start date/time is present
      if($start !== null)
      {
          $dtStart = $start->getDateTime();
          $dtStart->setTimezone($timezone);

          // moment.js doesn't like times be given even if
          // allDay is set to true
          // This should fix T23
          if($start['VALUE'] == 'DATE')
          {
              $entry['allDay'] = true;
              $entry['start'] = $dtStart->format("Y-m-d");
          }
          else
          {
              $entry['allDay'] = false;
              $entry['start'] = $dtStart->format(\DateTime::ATOM);
          }
      }
      $end = $event->DTEND;
      // Parse only if the end date/time is present
      if($end !== null)
      {
          $dtEnd = $end->getDateTime();
          $dtEnd->setTimezone($timezone);
          if($end['VALUE'] == 'DATE')
              $entry['end'] = $dtEnd->format("Y-m-d");
          else
              $entry['end'] = $dtEnd->format(\DateTime::ATOM);
      }
      $duration = $event->DURATION;
      // Parse duration only if start is set, but end is missing
      if($start !== null && $end == null && $duration !== null)
      {
          $interval = $duration->getDateInterval();
          $dtStart = $start->getDateTime();
          $dtStart->setTimezone($timezone);
          $dtEnd = $dtStart->add($interval);
          $dtEnd->setTimezone($timezone);
          $entry['end'] = $dtEnd->format(\DateTime::ATOM);
      }
      $description = $event->DESCRIPTION;
      if($description !== null)
          $entry['description'] = (string)$description;
      else
          $entry['description'] = '';
      $attachments = $event->ATTACH;
      if($attachments !== null)
      {
          $entry['attachments'] = array();
          foreach($attachments as $attachment)
              $entry['attachments'][] = (string)$attachment;
      }
      $entry['title'] = (string)$event->summary;
      $entry['location'] = (string)$event->location;
      $entry['id'] = $uid;
      $entry['page'] = $page;
      $entry['color'] = $color;
      $entry['recurring'] = $recurring;

      // Extract public flag
      $publicProp = $event->{'X-DAVCAL-PUBLIC'};
      $entry['public'] = ($publicProp !== null && (string)$publicProp === '1') ? '1' : '0';

      // Extract members count
      $membersProp = $event->{'X-DAVCAL-MEMBERS'};
      $membersVal = ($membersProp !== null) ? (string)$membersProp : '0';
      $entry['members'] = is_numeric($membersVal) ? $membersVal : ($membersVal === '1' ? '1' : '0');

      // Extract recurring rule if it exists
      $rrule = $event->RRULE;
      if($rrule !== null)
      {
          $rruleStr = (string)$rrule;
          // Parse the RRULE to get the frequency
          if(strpos($rruleStr, 'FREQ=DAILY') !== false)
          {
              $entry['recurrence'] = 'DAILY';
              $entry['recurrence_weekday'] = '';
          }
          elseif(strpos($rruleStr, 'FREQ=WEEKLY') !== false)
          {
              if(strpos($rruleStr, 'INTERVAL=2') !== false)
                  $entry['recurrence'] = 'BIWEEKLY';
              else
                  $entry['recurrence'] = 'WEEKLY';
              if(preg_match('/BYDAY=([A-Z]+)/', $rruleStr, $m))
                  $entry['recurrence_weekday'] = $m[1];
              else
                  $entry['recurrence_weekday'] = '';
          }
          elseif(strpos($rruleStr, 'FREQ=MONTHLY') !== false)
          {
              $entry['recurrence'] = 'MONTHLY';
              $entry['recurrence_weekday'] = '';
              if(preg_match('/BYDAY=([A-Z]+)/', $rruleStr, $m))
                  $entry['recurrence_byday'] = $m[1];
              else
                  $entry['recurrence_byday'] = '';
              if(preg_match('/BYSETPOS=(-?[0-9]+)/', $rruleStr, $m))
                  $entry['recurrence_bysetpos'] = $m[1];
              else
                  $entry['recurrence_bysetpos'] = '';
          }
          elseif(strpos($rruleStr, 'FREQ=YEARLY') !== false)
          {
              $entry['recurrence'] = 'YEARLY';
              $entry['recurrence_weekday'] = '';
          }
          else
          {
              $entry['recurrence'] = '';
              $entry['recurrence_weekday'] = '';
          }
      }
      else
      {
          $entry['recurrence'] = '';
          $entry['recurrence_weekday'] = '';
      }

      return $entry;
  }

  /**
   * Retrieve an event by its UID
   *
   * @param string $uid The event's UID
   *
   * @return mixed The table row with the given event
   */
  public function getEventWithUid($uid)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT calendardata, calendarid, componenttype, uri FROM calendarobjects WHERE uid = ?";
      $row = $sqlite->queryRecord($query, [$uid]);
      return $row;
  }

  /**
   * Retrieve information of a calendar's object, not including the actual
   * calendar data! This is mainly needed for the sync support.
   *
   * @param int $calid The calendar ID
   *
   * @return mixed The result
   */
  public function getCalendarObjects($calid)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT id, uri, lastmodified, etag, calendarid, size, componenttype FROM calendarobjects WHERE calendarid = ?";
      $arr = $sqlite->queryAll($query, [$calid]);
      return $arr;
  }

  /**
   * Retrieve a single calendar object by calendar ID and URI
   *
   * @param int $calid The calendar's ID
   * @param string $uri The object's URI
   *
   * @return mixed The result
   */
  public function getCalendarObjectByUri($calid, $uri)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT id, uri, lastmodified, etag, calendarid, size, calendardata, componenttype FROM calendarobjects WHERE calendarid = ? AND uri = ?";
      $row = $sqlite->queryRecord($query, [$calid, $uri]);
      return $row;
  }

  /**
   * Retrieve several calendar objects by specifying an array of URIs.
   * This is mainly neede for sync.
   *
   * @param int $calid The calendar's ID
   * @param array $uris An array of URIs
   *
   * @return mixed The result
   */
  public function getMultipleCalendarObjectsByUri($calid, $uris)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT id, uri, lastmodified, etag, calendarid, size, calendardata, componenttype FROM calendarobjects WHERE calendarid = ? AND uri IN (";
      $query .= implode(',', array_fill(0, count($uris), '?'));
      $query .= ')';
      $vals = array_merge(array($calid), $uris);
      $arr = $sqlite->queryAll($query, $vals);
      return $arr;
  }

  /**
   * Retrieve all calendar events for a given calendar ID
   *
   * @param string $calid The calendar's ID
   *
   * @return array An array containing all calendar data
   */
  public function getAllCalendarEvents($calid)
  {
      $enabled = $this->getCalendarStatus($calid);
      if($enabled === false)
        return false;

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT calendardata, uid, componenttype, uri FROM calendarobjects WHERE calendarid = ?";
      $arr = $sqlite->queryAll($query, [$calid]);
      return $arr;
  }

  /**
   * Edit a calendar entry for a page, given by its parameters.
   * The params array has the same format as @see addCalendarEntryForPage
   *
   * @param string $id The page's ID to work on
   * @param string $user The user's ID to work on
   * @param array $params The parameter array for the edited calendar event
   *
   * @return boolean True on success, otherwise false
   */
  public function editCalendarEntryForPage($id, $user, $params)
  {
      if($params['currenttz'] !== '' && $params['currenttz'] !== 'local')
          $timezone = new \DateTimeZone($params['currenttz']);
      elseif($params['currenttz'] === 'local')
          $timezone = new \DateTimeZone($params['detectedtz']);
      else
          $timezone = new \DateTimeZone('UTC');

      // Parse dates
      $startDate = explode('-', $params['eventfrom']);
      $startTime = explode(':', $params['eventfromtime']);
      $endDate = explode('-', $params['eventto']);
      $endTime = explode(':', $params['eventtotime']);

      // Retrieve the existing event based on the UID
      $uid = $params['uid'];

      if(strpos($id, 'webdav://') === 0)
      {
          $wdc =& plugin_load('helper', 'webdavclient');
          if(is_null($wdc))
            return false;
          $event = $wdc->getCalendarEntryByUid($uid);
      }
      else
      {
          $event = $this->getEventWithUid($uid);
      }

      // Load SabreDAV
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');
      if(!isset($event['calendardata']))
        return false;
      $uri = $event['uri'];
      $calid = $event['calendarid'];

      // Parse the existing event
      $vcal = \Sabre\VObject\Reader::read($event['calendardata']);
      $vevent = $vcal->VEVENT;

      // Set the new event values
      $vevent->summary = $params['eventname'];
      $dtStamp = new \DateTime(null, new \DateTimeZone('UTC'));
      $description = $params['eventdescription'];
      $location = $params['eventlocation'];

      // Remove existing timestamps to overwrite them
      $vevent->remove('DESCRIPTION');
      $vevent->remove('DTSTAMP');
      $vevent->remove('LAST-MODIFIED');
      $vevent->remove('ATTACH');
      $vevent->remove('LOCATION');
      $vevent->remove('X-DAVCAL-PUBLIC');
      $vevent->remove('X-DAVCAL-MEMBERS');

      // Add new time stamps, description and location
      $vevent->add('DTSTAMP', $dtStamp);
      $vevent->add('LAST-MODIFIED', $dtStamp);
      if($description !== '')
          $vevent->add('DESCRIPTION', $description);
      if($location !== '')
          $vevent->add('LOCATION', $location);

      // Add public flag if specified
      if(!empty($params['public']) && $params['public'] == '1')
          $vevent->add('X-DAVCAL-PUBLIC', '1');

      // Add members flag if specified
      if(!empty($params['members']) && $params['members'] == '1')
          $vevent->add('X-DAVCAL-MEMBERS', '1');

      // Add attachments
      $attachments = $params['attachments'];
      if(!is_null($attachments))
          foreach($attachments as $attachment)
              $vevent->add('ATTACH', $attachment);

      // Add recurring rule if specified
      if(isset($params['recurrence']) && !empty($params['recurrence']))
      {
          $recurrence = $params['recurrence'];
          $rrule = '';

          switch($recurrence)
          {
              case 'DAILY':
                  $rrule = 'FREQ=DAILY';
                  break;
              case 'WEEKLY':
                  $rrule = 'FREQ=WEEKLY';
                  break;
              case 'BIWEEKLY':
                  $rrule = 'FREQ=WEEKLY;INTERVAL=2';
                  break;
              case 'MONTHLY':
                  $rrule = 'FREQ=MONTHLY';
                  break;
              case 'YEARLY':
                  $rrule = 'FREQ=YEARLY';
                  break;
          }

          // Remove existing RRULE first
          $vevent->remove('RRULE');

          if(!empty($rrule))
          {
              if($recurrence === 'MONTHLY' && (empty($params['recurrence_monthly_mode']) || $params['recurrence_monthly_mode'] === 'weekday') && !empty($params['recurrence_byday']) && !empty($params['recurrence_bysetpos']))
              {
                  $rrule .= ';BYDAY=' . $params['recurrence_byday'] . ';BYSETPOS=' . $params['recurrence_bysetpos'];
              }
              // 'date' mode: just FREQ=MONTHLY, iCal repeats on same day of month by default
              elseif(($recurrence === 'WEEKLY' || $recurrence === 'BIWEEKLY') && !empty($params['recurrence_weekday']))
              {
                  $rrule .= ';BYDAY=' . $params['recurrence_weekday'];
              }
              $vevent->add('RRULE', $rrule);
          }
      }

      // Setup DTSTART
      $dtStart = new \DateTime();
      $dtStart->setTimezone($timezone);
      $dtStart->setDate(intval($startDate[0]), intval($startDate[1]), intval($startDate[2]));
      if($params['allday'] != '1')
          $dtStart->setTime(intval($startTime[0]), intval($startTime[1]), 0);

      // Setup DTEND
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

      // Remove the time for allday events
      if($params['allday'] == '1')
      {
          $dtStartEv['VALUE'] = 'DATE';
          $dtEndEv['VALUE'] = 'DATE';
      }
      $eventStr = $vcal->serialize();

      if(strpos($id, 'webdav://') === 0)
      {
          $connectionId = str_replace('webdav://', '', $id);
          return $wdc->editCalendarEntry($connectionId, $uid, $eventStr);
      }
      else
      {
          $sqlite = $this->getDB();
          if(!$sqlite)
            return false;
          $now = new DateTime();

          // Handle single occurrence edit
          if(isset($params['edit_mode']) && $params['edit_mode'] === 'single' && !empty($params['occurrence_date']))
          {
              // Re-read the original unmodified calendar data
              $originalEvent = $this->getEventWithUid($uid);
              $vcalOriginal = \Sabre\VObject\Reader::read($originalEvent['calendardata']);

              // RECURRENCE-ID must match the original occurrence's DTSTART exactly
              $occurrenceDate = new \DateTime($params['occurrence_date']);
              $occurrenceDate->setTimezone($timezone);
              $occurrenceTs = $occurrenceDate->getTimestamp();

              // Drop any override that already covers this occurrence. Saving
              // twice used to append a second VEVENT carrying the same
              // RECURRENCE-ID, and the occurrence was then drawn once per
              // override. Comparison is by timestamp, since the same instant can
              // be written under different TZIDs.
              $staleOverrides = array();
              foreach($vcalOriginal->VEVENT as $existingEvent)
              {
                  if(!isset($existingEvent->{'RECURRENCE-ID'}))
                      continue;
                  if($existingEvent->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() === $occurrenceTs)
                      $staleOverrides[] = $existingEvent;
              }
              foreach($staleOverrides as $staleOverride)
                  $vcalOriginal->remove($staleOverride);

              // Create the exception VEVENT
              $exEvent = $vcalOriginal->add('VEVENT');
              $exEvent->add('UID', $uid);
              $exEvent->add('RECURRENCE-ID', $occurrenceDate);

              // Set the new values
              $dtStampEx = new \DateTime(null, new \DateTimeZone('UTC'));
              $exEvent->add('DTSTAMP', $dtStampEx);
              $exEvent->add('LAST-MODIFIED', $dtStampEx);
              $exEvent->add('DTSTART', $dtStart);
              $exEvent->add('DTEND', $dtEnd);
              $exEvent->add('SUMMARY', $params['eventname']);

              if(!empty($params['eventdescription']))
                  $exEvent->add('DESCRIPTION', $params['eventdescription']);
              if(!empty($params['eventlocation']))
                  $exEvent->add('LOCATION', $params['eventlocation']);

              $attachments = $params['attachments'];
              if(!is_null($attachments))
                  foreach($attachments as $attachment)
                      $exEvent->add('ATTACH', $attachment);

              if($params['allday'] == '1')
              {
                  $exEvent->DTSTART['VALUE'] = 'DATE';
                  $exEvent->DTEND['VALUE'] = 'DATE';
              }

              $eventStr = $vcalOriginal->serialize();
              $extraData = $this->getDenormalizedData($eventStr);

              $query = "UPDATE calendarobjects SET calendardata = ?, lastmodified = ?, ".
                       "firstoccurence = ?, lastoccurence = ?, size = ?, etag = ? WHERE uid = ?";
              $res = $sqlite->query($query, $eventStr, $now->getTimestamp(),
                                          $extraData['firstOccurence'], $extraData['lastOccurence'],
                                          strlen($eventStr), md5($eventStr), $uid);
          }
          else
          {
              // Edit all occurrences - existing behavior
              $extraData = $this->getDenormalizedData($eventStr);
              $query = "UPDATE calendarobjects SET calendardata = ?, lastmodified = ?, ".
                       "firstoccurence = ?, lastoccurence = ?, size = ?, etag = ? WHERE uid = ?";
              $res = $sqlite->query($query, $eventStr, $now->getTimestamp(),
                                          $extraData['firstOccurence'], $extraData['lastOccurence'],
                                          strlen($eventStr), md5($eventStr), $uid);
          }

          if($res !== false)
          {
              $this->updateSyncTokenLog($calid, $uri, 'modified');
              return true;
          }
      }
      return false;
  }

  /**
   * Delete an event from a calendar by calendar ID and URI
   *
   * @param int $calid The calendar's ID
   * @param string $uri The object's URI
   *
   * @return true
   */
  public function deleteCalendarEntryForCalendarByUri($calid, $uri)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "DELETE FROM calendarobjects WHERE calendarid = ? AND uri = ?";
      $res = $sqlite->query($query, $calid, $uri);
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'deleted');
      }
      return true;
  }

  /**
   * Delete a calendar entry for a given page. Actually, the event is removed
   * based on the entry's UID, so that page ID is no used.
   *
   * @param string $id The page's ID (unused)
   * @param array $params The parameter array to work with
   *
   * @return boolean True
   */
  public function deleteCalendarEntryForPage($id, $params)
  {
      $uid = $params['uid'];

      // A single occurrence is excluded rather than deleted, otherwise the
      // whole series goes with it.
      if(isset($params['edit_mode']) && $params['edit_mode'] === 'single'
         && !empty($params['occurrence_date']) && strpos($id, 'webdav://') !== 0)
      {
          return $this->deleteSingleOccurrenceForPage($id, $params);
      }

      if(strpos($id, 'webdav://') === 0)
      {
          $wdc =& plugin_load('helper', 'webdavclient');
          if(is_null($wdc))
            return false;
          $connectionId = str_replace('webdav://', '', $id);
          $result = $wdc->deleteCalendarEntry($connectionId, $uid);
          return $result;
      }
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $event = $this->getEventWithUid($uid);
      $calid = $event['calendarid'];
      $uri = $event['uri'];
      $query = "DELETE FROM calendarobjects WHERE uid = ?";
      $res = $sqlite->query($query, $uid);
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'deleted');
      }
      return true;
  }

  /**
   * Delete a single occurrence of a recurring event. The whole series lives in
   * one calendarobjects row, so the occurrence is excluded by adding an EXDATE
   * to the master VEVENT instead of removing the row.
   *
   * Any RECURRENCE-ID override for the same occurrence is removed as well,
   * otherwise it survives as a detached event.
   *
   * @param string $id The page's ID
   * @param array $params The parameter array to work with
   *
   * @return boolean True on success, otherwise false
   */
  public function deleteSingleOccurrenceForPage($id, $params)
  {
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');
      $uid = $params['uid'];

      $event = $this->getEventWithUid($uid);
      if(!isset($event['calendardata']))
        return false;
      $calid = $event['calendarid'];
      $uri = $event['uri'];

      $vcal = \Sabre\VObject\Reader::read($event['calendardata']);

      // The master VEVENT is the one without a RECURRENCE-ID
      $master = null;
      foreach($vcal->VEVENT as $vevent)
      {
          if(!isset($vevent->{'RECURRENCE-ID'}))
          {
              $master = $vevent;
              break;
          }
      }

      // Not actually a series, so fall back to removing the object
      if(is_null($master) || !isset($master->RRULE))
      {
          return $this->deleteCalendarEntryForPage($id, array('uid' => $uid));
      }

      $dtStart = $master->DTSTART;
      $startDt = $dtStart->getDateTime();
      $isAllDay = (isset($dtStart['VALUE']) && strtoupper((string)$dtStart['VALUE']) === 'DATE');

      // The occurrence date arrives as an ISO 8601 UTC timestamp. Line it up
      // with DTSTART's timezone so the EXDATE matches what clients expand.
      $exDate = new \DateTime($params['occurrence_date']);
      $exDate->setTimezone($startDt->getTimezone());
      if($isAllDay)
          $exDate = new \DateTime($exDate->format('Y-m-d'), $startDt->getTimezone());

      // Drop an override for this occurrence, if one exists
      $overrides = array();
      foreach($vcal->VEVENT as $vevent)
      {
          if(!isset($vevent->{'RECURRENCE-ID'}))
              continue;
          $recurrenceId = $vevent->{'RECURRENCE-ID'}->getDateTime();
          if($recurrenceId->getTimestamp() === $exDate->getTimestamp())
              $overrides[] = $vevent;
      }
      foreach($overrides as $override)
          $vcal->remove($override);

      // Exclude the occurrence
      $exDateProp = $master->add('EXDATE', $exDate);
      if($isAllDay)
          $exDateProp['VALUE'] = 'DATE';
      elseif(isset($dtStart['TZID']))
          $exDateProp['TZID'] = (string)$dtStart['TZID'];

      $master->remove('LAST-MODIFIED');
      $master->add('LAST-MODIFIED', new \DateTime('now', new \DateTimeZone('UTC')));

      $eventStr = $vcal->serialize();
      $extraData = $this->getDenormalizedData($eventStr);

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $now = new \DateTime();
      $query = "UPDATE calendarobjects SET calendardata = ?, lastmodified = ?, ".
               "firstoccurence = ?, lastoccurence = ?, size = ?, etag = ? WHERE uid = ?";
      $res = $sqlite->query($query, $eventStr, $now->getTimestamp(),
                            $extraData['firstOccurence'], $extraData['lastOccurence'],
                            strlen($eventStr), md5($eventStr), $uid);
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'modified');
          return true;
      }
      return false;
  }

  /**
   * Retrieve the current sync token for a calendar
   *
   * @param string $calid The calendar id
   *
   * @return mixed The synctoken or false
   */
  public function getSyncTokenForCalendar($calid)
  {
      $row = $this->getCalendarSettings($calid);
      if(isset($row['synctoken']))
          return $row['synctoken'];
      return false;
  }

  /**
   * Helper function to convert the operation name to
   * an operation code as stored in the database
   *
   * @param string $operationName The operation name
   *
   * @return mixed The operation code or false
   */
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

  /**
   * Update the sync token log based on the calendar id and the
   * operation that was performed.
   *
   * @param string $calid The calendar ID that was modified
   * @param string $uri The calendar URI that was modified
   * @param string $operation The operation that was performed
   *
   * @return boolean True on success, otherwise false
   */
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
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "INSERT INTO calendarchanges (uri, synctoken, calendarid, operation) VALUES(?, ?, ?, ?)";
      $res = $sqlite->query($query, $uri, $currentToken, $calid, $operationCode);
      if($res === false)
        return false;
      $currentToken++;
      $query = "UPDATE calendars SET synctoken = ? WHERE id = ?";
      $res = $sqlite->query($query, $currentToken, $calid);
      return ($res !== false);
  }

  /**
   * Return the sync URL for a given Page, i.e. a calendar
   *
   * @param string $id The page's ID
   * @param string $user (optional) The user's ID
   *
   * @return mixed The sync url or false
   */
  public function getSyncUrlForPage($id, $user = null)
  {
      if(is_null($user))
      {
          if(isset($_SERVER['REMOTE_USER']) && !is_null($_SERVER['REMOTE_USER']))
          {
            $user = $_SERVER['REMOTE_USER'];
          }
          else
          {
            return false;
          }
      }

      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;

      $calsettings = $this->getCalendarSettings($calid);
      if(!isset($calsettings['uri']))
        return false;

      $syncurl = DOKU_URL.'lib/plugins/davcal/calendarserver.php/calendars/'.$user.'/'.$calsettings['uri'];
      return $syncurl;
  }

  /**
   * Return the private calendar's URL for a given page
   *
   * @param string $id the page ID
   *
   * @return mixed The private URL or false
   */
  public function getPrivateURLForPage($id)
  {
      // Check if this is an aggregated calendar (has multiple calendar pages)
      $calendarPages = $this->getCalendarPagesByMeta($id);

      if($calendarPages !== false && count($calendarPages) > 1)
      {
          // This is an aggregated calendar - create a special private URL
          return $this->getPrivateURLForAggregatedCalendar($id, $calendarPages);
      }

      // Single calendar - use the original logic
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;

      return $this->getPrivateURLForCalendar($calid);
  }

  /**
   * Return the private calendar's URL for a given calendar ID
   *
   * @param string $calid The calendar's ID
   *
   * @return mixed The private URL or false
   */
  public function getPrivateURLForCalendar($calid)
  {
      if(isset($this->cachedValues['privateurl'][$calid]))
        return $this->cachedValues['privateurl'][$calid];
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT url FROM calendartoprivateurlmapping WHERE calid = ?";
      $row = $sqlite->queryRecord($query, [$calid]);
      if(!isset($row['url']))
      {
          $url = 'dokuwiki-' . bin2hex(random_bytes(16)) . '.ics';
          $query = "INSERT INTO calendartoprivateurlmapping (url, calid) VALUES(?, ?)";
          $sqlite->query($query, [$url, $calid]);
      }
      else
      {
          $url = $row['url'];
      }

      $url = DOKU_URL.'lib/plugins/davcal/ics.php/'.$url;
      $this->cachedValues['privateurl'][$calid] = $url;
      return $url;
  }

  /**
   * Return the private calendar's URL for an aggregated calendar
   *
   * @param string $id the page ID
   * @param array $calendarPages the calendar pages in the aggregation
   *
   * @return mixed The private URL or false
   */
  public function getPrivateURLForAggregatedCalendar($id, $calendarPages)
  {
      $aggregateId = 'aggregated-' . md5($id . serialize($calendarPages));

      if(isset($this->cachedValues['privateurl'][$aggregateId]))
        return $this->cachedValues['privateurl'][$aggregateId];

      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;

      $query = "SELECT url FROM calendartoprivateurlmapping WHERE calid = ?";
      $row = $sqlite->queryRecord($query, [$aggregateId]);

      if(!isset($row['url']))
      {
          $url = 'dokuwiki-aggregated-' . bin2hex(random_bytes(16)) . '.ics';
          $query = "INSERT INTO calendartoprivateurlmapping (url, calid) VALUES(?, ?)";
          $sqlite->query($query, [$url, $aggregateId]);

          $query = "INSERT INTO pagetocalendarmapping (page, calid) VALUES(?, ?)";
          $sqlite->query($query, [$id, $aggregateId]);
      }
      else
      {
          $url = $row['url'];
      }

      $url = DOKU_URL.'lib/plugins/davcal/ics.php/'.$url;
      $this->cachedValues['privateurl'][$aggregateId] = $url;
      return $url;
  }

  /**
   * Retrieve the calendar ID for a given private calendar URL
   *
   * @param string $url The private URL
   *
   * @return mixed The calendar ID or false
   */
  public function getCalendarForPrivateURL($url)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;
      $query = "SELECT calid FROM calendartoprivateurlmapping WHERE url = ?";
      $row = $sqlite->queryRecord($query, [$url]);
      if(!isset($row['calid']))
        return false;
      return $row['calid'];
  }

  /**
   * Return a given calendar as ICS feed, i.e. all events in one ICS file.
   *
   * @param string $calid The calendar ID to retrieve
   *
   * @return mixed The calendar events as string or false
   */
  public function getCalendarAsICSFeed($calid)
  {
      $calSettings = $this->getCalendarSettings($calid);
      if($calSettings === false)
        return false;
      $events = $this->getAllCalendarEvents($calid);
      if($events === false)
        return false;

      // Load SabreDAV
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');
      $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//DAVCal//DAVCal for DokuWiki//EN\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:";
      $out .= $calSettings['displayname']."\r\n";
      foreach($events as $event)
      {
          $vcal = \Sabre\VObject\Reader::read($event['calendardata']);
          if(!isset($vcal->VEVENT))
            continue;
          // Emit every VEVENT, master plus RECURRENCE-ID overrides, so moved
          // occurrences survive into the feed.
          foreach($vcal->VEVENT as $evt)
              $out .= $evt->serialize();
      }
      $out .= "END:VCALENDAR\r\n";
      return $out;
  }

  /**
   * Return an aggregated calendar as ICS feed, combining multiple calendars.
   *
   * @param string $icsFile The ICS file name for the aggregated calendar
   *
   * @return mixed The combined calendar events as string or false
   */
  public function getAggregatedCalendarAsICSFeed($icsFile)
  {
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;

      // Find the aggregated calendar ID from the URL
      $query = "SELECT calid FROM calendartoprivateurlmapping WHERE url = ?";
      $row = $sqlite->queryRecord($query, [$icsFile]);

      if(!isset($row['calid']))
        return false;

      $aggregateId = $row['calid'];

      // Get the page ID for this aggregated calendar
      $query = "SELECT page FROM pagetocalendarmapping WHERE calid = ?";
      $row = $sqlite->queryRecord($query, [$aggregateId]);

      if(!isset($row['page']))
        return false;

      $pageId = $row['page'];

      // Get the calendar pages for this aggregated calendar
      $calendarPages = $this->getCalendarPagesByMeta($pageId, true); // Skip permission check for private URLs
      if($calendarPages === false || count($calendarPages) <= 1)
      {
          if($conf['allowdebug'])
              \dokuwiki\Logger::error('DAVCAL', 'No calendar pages found or count <= 1 for page: '.$pageId.' (count: '.(is_array($calendarPages) ? count($calendarPages) : 'false').')', __FILE__, __LINE__);
          return false;
      }

      // Load SabreDAV
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');

      // Start building the combined ICS
      $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//DAVCal//DAVCal for DokuWiki//EN\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:";
      $out .= "Aggregated Calendar\r\n";

      // Combine events from all calendars
      foreach($calendarPages as $calPage => $color)
      {
          $calid = $this->getCalendarIdForPage($calPage);
          if($calid === false)
            continue;

          $events = $this->getAllCalendarEvents($calid);
          if($events === false)
            continue;

          foreach($events as $event)
          {
              $vcal = \Sabre\VObject\Reader::read($event['calendardata']);
              if(!isset($vcal->VEVENT))
                continue;
              // Emit every VEVENT, master plus RECURRENCE-ID overrides.
              foreach($vcal->VEVENT as $evt)
                  $out .= $evt->serialize();
          }
      }

      $out .= "END:VCALENDAR\r\n";
      return $out;
  }

  /**
   * Retrieve a configuration option for the plugin
   *
   * @param string $key The key to query
   * @return mixed The option set, null if not found
   */
  public function getConfig($key)
  {
      return $this->getConf($key);
  }

  /**
   * Parses some information from calendar objects, used for optimized
   * calendar-queries. Taken nearly unmodified from Sabre's PDO backend
   *
   * Returns an array with the following keys:
   *   * etag - An md5 checksum of the object without the quotes.
   *   * size - Size of the object in bytes
   *   * componentType - VEVENT, VTODO or VJOURNAL
   *   * firstOccurence
   *   * lastOccurence
   *   * uid - value of the UID property
   *
   * @param string $calendarData
   * @return array
   */
  protected function getDenormalizedData($calendarData)
  {
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');

      $vObject = \Sabre\VObject\Reader::read($calendarData);
      $componentType = null;
      $component = null;
      $firstOccurence = null;
      $lastOccurence = null;
      $uid = null;
      foreach ($vObject->getComponents() as $component)
      {
          if ($component->name !== 'VTIMEZONE')
          {
              $componentType = $component->name;
              $uid = (string)$component->UID;
              break;
          }
      }
      if (!$componentType)
      {
          return false;
      }
      if ($componentType === 'VEVENT')
      {
          $firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
          // Finding the last occurence is a bit harder
          if (!isset($component->RRULE))
          {
              if (isset($component->DTEND))
              {
                  $lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
              }
              elseif (isset($component->DURATION))
              {
                  $endDate = clone $component->DTSTART->getDateTime();
                  $endDate->add(\Sabre\VObject\DateTimeParser::parse($component->DURATION->getValue()));
                  $lastOccurence = $endDate->getTimeStamp();
              }
              elseif (!$component->DTSTART->hasTime())
              {
                  $endDate = clone $component->DTSTART->getDateTime();
                  $endDate->modify('+1 day');
                  $lastOccurence = $endDate->getTimeStamp();
              }
              else
              {
                  $lastOccurence = $firstOccurence;
              }
          }
          else
          {
              $it = new \Sabre\VObject\Recur\EventIterator($vObject, (string)$component->UID);
              $maxDate = new \DateTime('2038-01-01');
              if ($it->isInfinite())
              {
                  $lastOccurence = $maxDate->getTimeStamp();
              }
              else
              {
                  $end = $it->getDtEnd();
                  while ($it->valid() && $end < $maxDate)
                  {
                      $end = $it->getDtEnd();
                      $it->next();
                  }
                  $lastOccurence = $end->getTimeStamp();
              }
          }
      }

      return array(
          'etag'           => md5($calendarData),
          'size'           => strlen($calendarData),
          'componentType'  => $componentType,
          'firstOccurence' => $firstOccurence,
          'lastOccurence'  => $lastOccurence,
          'uid'            => $uid,
      );
  }

  /**
   * Query a calendar by ID and taking several filters into account.
   * This is heavily based on Sabre's PDO backend.
   *
   * @param int $calendarId The calendar's ID
   * @param array $filters The filter array to apply
   *
   * @return mixed The result
   */
  public function calendarQuery($calendarId, $filters)
  {
      \dokuwiki\Logger::debug('DAVCAL', 'Calendar query executed', __FILE__, __LINE__);
      $componentType = null;
      $requirePostFilter = true;
      $timeRange = null;
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;

      // if no filters were specified, we don't need to filter after a query
      if (!$filters['prop-filters'] && !$filters['comp-filters'])
      {
          $requirePostFilter = false;
      }

      // Figuring out if there's a component filter
      if (count($filters['comp-filters']) > 0 && !$filters['comp-filters'][0]['is-not-defined'])
      {
          $componentType = $filters['comp-filters'][0]['name'];

          // Checking if we need post-filters
          if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$filters['comp-filters'][0]['time-range'] && !$filters['comp-filters'][0]['prop-filters'])
          {
              $requirePostFilter = false;
          }
          // There was a time-range filter
          if ($componentType == 'VEVENT' && isset($filters['comp-filters'][0]['time-range']))
          {
              $timeRange = $filters['comp-filters'][0]['time-range'];

              // If start time OR the end time is not specified, we can do a
              // 100% accurate mysql query.
              if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$filters['comp-filters'][0]['prop-filters'] && (!$timeRange['start'] || !$timeRange['end']))
              {
                  $requirePostFilter = false;
              }
          }
      }

      if ($requirePostFilter)
      {
          $query = "SELECT uri, calendardata FROM calendarobjects WHERE calendarid = ?";
      }
      else
      {
          $query = "SELECT uri FROM calendarobjects WHERE calendarid = ?";
      }

      $values = array(
          $calendarId
      );

      if ($componentType)
      {
          $query .= " AND componenttype = ?";
          $values[] = $componentType;
      }

      if ($timeRange && $timeRange['start'])
      {
          $query .= " AND lastoccurence > ?";
          $values[] = $timeRange['start']->getTimeStamp();
      }
      if ($timeRange && $timeRange['end'])
      {
          $query .= " AND firstoccurence < ?";
          $values[] = $timeRange['end']->getTimeStamp();
      }

      $arr = $sqlite->queryAll($query, $values);

      $result = array();
      foreach($arr as $row)
      {
          if ($requirePostFilter)
          {
              if (!$this->validateFilterForObject($row, $filters))
              {
                  continue;
              }
          }
          $result[] = $row['uri'];
      }

      return $result;
  }

  /**
   * This method validates if a filter (as passed to calendarQuery) matches
   * the given object. Taken from Sabre's PDO backend
   *
   * @param array $object
   * @param array $filters
   * @return bool
   */
  protected function validateFilterForObject($object, $filters)
  {
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');
      // Unfortunately, setting the 'calendardata' here is optional. If
      // it was excluded, we actually need another call to get this as
      // well.
      if (!isset($object['calendardata']))
      {
          $object = $this->getCalendarObjectByUri($object['calendarid'], $object['uri']);
      }

      $vObject = \Sabre\VObject\Reader::read($object['calendardata']);
      $validator = new \Sabre\CalDAV\CalendarQueryValidator();

      $res = $validator->validate($vObject, $filters);
      return $res;
  }

  /**
   * Retrieve changes for a given calendar based on the given syncToken.
   *
   * @param int $calid The calendar's ID
   * @param int $syncToken The supplied sync token
   * @param int $syncLevel The sync level
   * @param int $limit The limit of changes
   *
   * @return array The result
   */
  public function getChangesForCalendar($calid, $syncToken, $syncLevel, $limit = null)
  {
      // Current synctoken
      $currentToken = $this->getSyncTokenForCalendar($calid);

      if ($currentToken === false) return null;

      $result = array(
          'syncToken' => $currentToken,
          'added'     => array(),
          'modified'  => array(),
          'deleted'   => array(),
      );
      $sqlite = $this->getDB();
      if(!$sqlite)
        return false;

      if ($syncToken)
      {
          $query = "SELECT uri, operation FROM calendarchanges WHERE synctoken >= ? AND synctoken < ? AND calendarid = ? ORDER BY synctoken";
          if ($limit > 0) $query .= " LIMIT " . (int)$limit;

          // Fetching all changes
          $arr = $sqlite->queryAll($query, array($syncToken, $currentToken, $calid));
          $changes = array();

          // This loop ensures that any duplicates are overwritten, only the
          // last change on a node is relevant.
          foreach($arr as $row)
          {
              $changes[$row['uri']] = $row['operation'];
          }

          foreach ($changes as $uri => $operation)
          {
              switch ($operation)
              {
                  case 1 :
                      $result['added'][] = $uri;
                      break;
                  case 2 :
                      $result['modified'][] = $uri;
                      break;
                  case 3 :
                      $result['deleted'][] = $uri;
                      break;
              }
          }
      }
      else
      {
          // No synctoken supplied, this is the initial sync.
          $query = "SELECT uri FROM calendarobjects WHERE calendarid = ?";
          $arr = $sqlite->queryAll($query, array($calid));

          $result['added'] = $arr;
      }
      return $result;
  }

  /**
   * Generate a DokuWiki page ID for a given calendar event
   *
   * @param string $calendarPage The calendar's page ID
   * @param string $title The event title
   * @param string $date The occurrence date (YYYY-MM-DD)
   *
   * @return mixed The page ID or false
   */
  public function getEventPageId($calendarPage, $title, $date)
  {
      $namespaceMap = array(
          'calendars:meeting_calendar'          => 'meetings',
          'calendars:opportunities_calendar'    => 'opportunities',
          'calendars:events_workshops_calendar' => 'events_workshops',
          'calendars:appearances_calendar'      => 'appearances_cons',
      );

      if(!isset($namespaceMap[$calendarPage]))
          return false;

      $namespace = $namespaceMap[$calendarPage];

      // Build slug from date and title
      $slug = $date . '_' . $title;
      $slug = strtolower($slug);
      $slug = preg_replace('/[^a-z0-9_\-]/', '_', $slug);
      $slug = preg_replace('/_+/', '_', $slug);
      $slug = trim($slug, '_');

      return $namespace . ':' . $slug;
  }

  /**
   * Create a DokuWiki page for a given calendar event
   *
   * @param string $calendarPage The calendar's page ID
   * @param array $eventData The event data array
   *
   * @return mixed The new page ID on success, otherwise false
   */
  public function createEventPage($calendarPage, $eventData)
  {
      // Normalize keys — raw form uses eventname/eventfrom, createEventPage callers use title/date
      if(empty($eventData['title']) && !empty($eventData['eventname']))
          $eventData['title'] = $eventData['eventname'];
      if(empty($eventData['date']) && !empty($eventData['eventfrom']))
          $eventData['date'] = $eventData['eventfrom'];
      if(empty($eventData['dateto']) && !empty($eventData['eventto']))
          $eventData['dateto'] = $eventData['eventto'];
      if(empty($eventData['description']) && !empty($eventData['eventdescription']))
          $eventData['description'] = $eventData['eventdescription'];
      if(empty($eventData['location']) && !empty($eventData['eventlocation']))
          $eventData['location'] = $eventData['eventlocation'];

      $pageId = $this->getEventPageId($calendarPage, $eventData['title'], $eventData['date']);
      if($pageId === false)
          return false;

      // Don't overwrite existing pages
      if(page_exists($pageId))
          return $pageId;

      // Build calendar display name
      $calendarNames = array(
          'calendars:meeting_calendar'          => 'Meeting Calendar',
          'calendars:opportunities_calendar'    => 'Opportunities Calendar',
          'calendars:events_workshops_calendar' => 'Events & Workshops Calendar',
          'calendars:appearances_calendar'      => 'Appearances Calendar',
      );
      $calendarName = isset($calendarNames[$calendarPage]) ? $calendarNames[$calendarPage] : $calendarPage;

      // Appearances calendar gets its own page template
      if($calendarPage === 'calendars:appearances_calendar')
          return $this->createAppearanceEventPage($pageId, $calendarPage, $calendarName, $eventData);

      // Day name lookup used by weekly/biweekly/monthly
      $dayNames = array('SU'=>'Sunday','MO'=>'Monday','TU'=>'Tuesday','WE'=>'Wednesday',
                        'TH'=>'Thursday','FR'=>'Friday','SA'=>'Saturday');

      // Build repeats line
      $repeatsLine = 'Does not repeat';
      if(!empty($eventData['recurrence']))
      {
          if($eventData['recurrence'] === 'DAILY')
          {
              $repeatsLine = 'Daily';
          }
          elseif($eventData['recurrence'] === 'WEEKLY')
          {
              if(!empty($eventData['recurrence_weekday']) && isset($dayNames[$eventData['recurrence_weekday']]))
                  $repeatsLine = 'Weekly on ' . $dayNames[$eventData['recurrence_weekday']];
              else
                  $repeatsLine = 'Weekly';
          }
          elseif($eventData['recurrence'] === 'BIWEEKLY')
          {
              if(!empty($eventData['recurrence_weekday']) && isset($dayNames[$eventData['recurrence_weekday']]))
                  $repeatsLine = 'Every two weeks on ' . $dayNames[$eventData['recurrence_weekday']];
              else
                  $repeatsLine = 'Every two weeks';
          }
          elseif($eventData['recurrence'] === 'YEARLY')
          {
              $repeatsLine = 'Yearly';
          }
          elseif($eventData['recurrence'] === 'MONTHLY')
          {
              if(!empty($eventData['recurrence_bysetpos']) && !empty($eventData['recurrence_byday']))
              {
                  $posNames = array('1'=>'First','2'=>'Second','3'=>'Third','4'=>'Fourth','-1'=>'Last');
                  $pos = isset($posNames[$eventData['recurrence_bysetpos']]) ? $posNames[$eventData['recurrence_bysetpos']] : $eventData['recurrence_bysetpos'];
                  $day = isset($dayNames[$eventData['recurrence_byday']]) ? $dayNames[$eventData['recurrence_byday']] : $eventData['recurrence_byday'];
                  $repeatsLine = 'Monthly on the ' . $pos . ' ' . $day;
              }
              else
              {
                  $dtForDate = new \DateTime($eventData['date']);
                  $repeatsLine = 'Monthly on the ' . $dtForDate->format('jS');
              }
          }
      }

      // Build links section
      $linksSection = 'N/A';
      if(!empty($eventData['links']) && is_array($eventData['links']))
      {
          $linkLines = array();
          foreach($eventData['links'] as $link)
          {
              if(!empty($link))
                  $linkLines[] = '  * ' . $link;
          }
          if(!empty($linkLines))
              $linksSection = implode("\n", $linkLines);
      }

      // Build Add to Calendar links
      $uid = isset($eventData['uid']) ? $eventData['uid'] : '';
      $icsUrl = DOKU_URL . 'lib/plugins/davcal/singleevent.php?uid=' . urlencode($uid) .
                '&occurrence_date=' . urlencode($eventData['date']);

      // Format dates for Google/Outlook URLs
      $dtStart = new \DateTime($eventData['date'] . (!empty($eventData['time']) ? 'T' . str_replace(':', '', $eventData['time']) . '00' : ''));
      $dtEnd = clone $dtStart;
      if(!empty($eventData['time']))
      {
          $dtEnd->modify('+1 hour'); // default 1hr if no end time known
          $calStart = $dtStart->format('Ymd\THis\Z');
          $calEnd   = $dtEnd->format('Ymd\THis\Z');
          $outlookStart = $dtStart->format(\DateTime::ATOM);
          $outlookEnd   = $dtEnd->format(\DateTime::ATOM);
      }
      else
      {
          $calStart = $dtStart->format('Ymd');
          $dtEnd->modify('+1 day');
          $calEnd   = $dtEnd->format('Ymd');
          $outlookStart = $dtStart->format('Y-m-d');
          $outlookEnd   = $dtEnd->format('Y-m-d');
      }

      $calTitle    = rawurlencode($eventData['title']);
      $calDesc     = rawurlencode($eventData['description'] ?? '');
      $calLocation = rawurlencode($eventData['location'] ?? '');

      $googleUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE' .
                   '&text=' . $calTitle .
                   '&dates=' . $calStart . '/' . $calEnd .
                   '&details=' . $calDesc .
                   '&location=' . $calLocation;

      $outlook365Url = 'https://outlook.office.com/calendar/0/deeplink/compose?subject=' . $calTitle .
                       '&startdt=' . rawurlencode($outlookStart) .
                       '&enddt=' . rawurlencode($outlookEnd) .
                       '&body=' . $calDesc .
                       '&location=' . $calLocation;

      $outlookLiveUrl = 'https://outlook.live.com/calendar/0/deeplink/compose?subject=' . $calTitle .
                        '&startdt=' . rawurlencode($outlookStart) .
                        '&enddt=' . rawurlencode($outlookEnd) .
                        '&body=' . $calDesc .
                        '&location=' . $calLocation;

      // Build the page content
      $content  = '====== ' . $eventData['title'] . ' ======' . "\n\n";
      $content .= '**Calendar:** [[' . $calendarPage . '|' . $calendarName . ']]' . "\n\n";
      $content .= '**Date:** ' . $eventData['date'];
      if(!empty($eventData['time']))
          $content .= ' ' . $eventData['time'] . ' UTC';
      $content .= "\n\n";
      if(!empty($eventData['location']))
          $content .= '**Location:** ' . $eventData['location'] . "\n\n";
      $content .= '**Repeats:** ' . $repeatsLine . "\n\n";
      $content .= '===== Description =====' . "\n\n";
      $content .= (!empty($eventData['description']) ? $eventData['description'] : '//No description provided//') . "\n\n";
      $content .= '===== Links =====' . "\n\n";
      $content .= $linksSection . "\n\n";
      $content .= '===== Add to Calendar =====' . "\n\n";
      $content .= '  * [[' . $googleUrl . '|Google Calendar]]' . "\n";
      $content .= '  * [[' . $outlook365Url . '|Outlook 365]]' . "\n";
      $content .= '  * [[' . $outlookLiveUrl . '|Outlook Live]]' . "\n";
      $content .= '  * [[' . $icsUrl . '|iCalendar / Other (.ics)]]' . "\n\n";
      $content .= '===== Notes =====' . "\n\n";
      $content .= "\n\n";
      $content .= '===== Recording Links =====' . "\n\n";
      $content .= "\n";

      saveWikiText($pageId, $content, 'Created from calendar event: ' . $eventData['title']);

      return $pageId;
  }

  /**
   * Create a DokuWiki page for an appearances calendar event
   */
  protected function createAppearanceEventPage($pageId, $calendarPage, $calendarName, $eventData)
  {
      // Normalize param keys — may come from raw form (eventname/eventfrom) or normalized (title/date)
      $title       = !empty($eventData['title'])       ? $eventData['title']       : ($eventData['eventname'] ?? '');
      $description = !empty($eventData['description']) ? $eventData['description'] : ($eventData['eventdescription'] ?? '');
      $location    = !empty($eventData['location'])    ? $eventData['location']    : ($eventData['eventlocation'] ?? '');
      $date        = !empty($eventData['date'])        ? $eventData['date']        : ($eventData['eventfrom'] ?? '');
      $time        = !empty($eventData['time'])        ? $eventData['time']        : ($eventData['eventfromtime'] ?? '');
      $uid         = !empty($eventData['uid'])         ? $eventData['uid']         : '';

      $icsUrl = DOKU_URL . 'lib/plugins/davcal/singleevent.php?uid=' . urlencode($uid) .
                '&occurrence_date=' . urlencode($date);

      $dtStart = new \DateTime($date . (!empty($time) ? 'T' . str_replace(':', '', $time) . '00' : ''));
      $dtEnd = clone $dtStart;
      if(!empty($time))
      {
          $dtEnd->modify('+1 hour');
          $calStart     = $dtStart->format('Ymd\THis\Z');
          $calEnd       = $dtEnd->format('Ymd\THis\Z');
          $outlookStart = $dtStart->format(\DateTime::ATOM);
          $outlookEnd   = $dtEnd->format(\DateTime::ATOM);
      }
      else
      {
          $calStart     = $dtStart->format('Ymd');
          $dtEnd->modify('+1 day');
          $calEnd       = $dtEnd->format('Ymd');
          $outlookStart = $dtStart->format('Y-m-d');
          $outlookEnd   = $dtEnd->format('Y-m-d');
      }

      $calTitle    = rawurlencode($title);
      $calDesc     = rawurlencode($description);
      $calLocation = rawurlencode($location);

      $googleUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE' .
                   '&text=' . $calTitle .
                   '&dates=' . $calStart . '/' . $calEnd .
                   '&details=' . $calDesc .
                   '&location=' . $calLocation;

      $outlook365Url = 'https://outlook.office.com/calendar/0/deeplink/compose?subject=' . $calTitle .
                       '&startdt=' . rawurlencode($outlookStart) .
                       '&enddt=' . rawurlencode($outlookEnd) .
                       '&body=' . $calDesc .
                       '&location=' . $calLocation;

      $outlookLiveUrl = 'https://outlook.live.com/calendar/0/deeplink/compose?subject=' . $calTitle .
                        '&startdt=' . rawurlencode($outlookStart) .
                        '&enddt=' . rawurlencode($outlookEnd) .
                        '&body=' . $calDesc .
                        '&location=' . $calLocation;

      // Build links section
      $linksSection = '';
      $links = !empty($eventData['links']) ? $eventData['links'] : (!empty($eventData['attachments']) ? $eventData['attachments'] : []);
      if(!empty($links) && is_array($links))
      {
          foreach($links as $link)
              if(!empty($link)) $linksSection .= '  * ' . $link . "\n";
      }

      $content  = '====== ' . $title . ' ======' . "\n\n";
      $content .= '**Calendar:** [[' . $calendarPage . '|' . $calendarName . ']]' . "\n\n";
      $dateTo = !empty($eventData['dateto']) ? $eventData['dateto'] : (!empty($eventData['eventto']) ? $eventData['eventto'] : '');
      $content .= '**Date:** ' . $date;
      if(!empty($time))
          $content .= ' ' . $time . ' UTC';
      if(!empty($dateTo) && $dateTo !== $date)
          $content .= ' - ' . $dateTo;
      $content .= "\n\n";
      if(!empty($location))
          $content .= '**Location:** ' . $location . "\n\n";
      $content .= '===== Description =====' . "\n\n";
      $content .= (!empty($description) ? $description : '//No description provided//') . "\n\n";
      if(!empty($linksSection))
      {
          $content .= '===== Event Website =====' . "\n\n";
          $content .= $linksSection . "\n";
      }

      // Members section — always present, with submitter row if they checked attending
      $content .= '===== Members at this Event =====' . "\n\n";
      $content .= '//Are you also going to this event? Edit this page to add/remove your info below, or use the "I\'m also going" button on the [[calendars:appearances_calendar|Appearances calendar page]].//' . "\n\n";
      $content .= '^  Name  ^  Table  ^  Panels/Workshops  ^  Stamp Rally  ^' . "\n";
      if(!empty($eventData['submitter_attending']) && $eventData['submitter_attending'] == '1')
      {
          $name      = !empty($eventData['submitter_name'])       ? $eventData['submitter_name']       : 'N/A';
          $table     = !empty($eventData['submitter_table'])      ? $eventData['submitter_table']       : 'N/A';
          $panels    = !empty($eventData['submitter_panels'])     ? $eventData['submitter_panels']      : 'N/A';
          $stamprally = (!empty($eventData['submitter_stamprally']) && $eventData['submitter_stamprally'] == '1') ? 'Y' : 'N';
          $content .= '|  ' . $name . '  |  ' . $table . '  |  ' . $panels . '  |  ' . $stamprally . '  |' . "\n";
      }
      $stampUrl = DOKU_URL . 'lib/plugins/davcal/stamprally.php?page=' . rawurlencode($pageId);
      $content .= '|  [[' . $stampUrl . '|Generate Stamp Rally Sheet]]  ||||' . "\n\n";

      $content .= '===== Add to Calendar =====' . "\n\n";
      $content .= '  * [[' . $googleUrl . '|Google Calendar]]' . "\n";
      $content .= '  * [[' . $outlook365Url . '|Outlook 365]]' . "\n";
      $content .= '  * [[' . $outlookLiveUrl . '|Outlook Live]]' . "\n";
      $content .= '  * [[' . $icsUrl . '|iCalendar / Other (.ics)]]' . "\n\n";

      $content .= '===== Notes =====' . "\n\n\n\n";

      $content .= '<--- [[:calendars:appearances_calendar|Back to Appearances & Cons Calendar]]' . "\n";

      saveWikiText($pageId, $content, 'Created from appearances calendar: ' . $title);

      return $pageId;
  }

  /**
   * Append a member entry to an existing appearances event page
   *
   * @param string $pageId The wiki page ID
   * @param array $memberData name, table, panels
   * @return bool
   */
  public function appendMemberToEventPage($pageId, $memberData)
  {
      if(!page_exists($pageId))
          return false;

      $existing = rawWiki($pageId);

      $name       = !empty($memberData['submitter_name'])       ? $memberData['submitter_name']   : 'N/A';
      $table      = !empty($memberData['submitter_table'])      ? $memberData['submitter_table']  : 'N/A';
      $panels     = !empty($memberData['submitter_panels'])     ? $memberData['submitter_panels'] : 'N/A';
      $stamprally = (!empty($memberData['submitter_stamprally']) && $memberData['submitter_stamprally'] == '1') ? 'Y' : 'N';

      $row = '|  ' . $name . '  |  ' . $table . '  |  ' . $panels . '  |  ' . $stamprally . '  |' . "\n";

      // Insert before the Generate Stamp Rally link row
      $stampRallyRow = '|  [[' . DOKU_URL;
      if(strpos($existing, $stampRallyRow) !== false)
      {
          $pos = strrpos($existing, "\n|  [[" . DOKU_URL);
          if($pos !== false)
              $new = substr($existing, 0, $pos) . "\n" . $row . substr($existing, $pos);
          else
              $new = str_replace('===== Add to Calendar =====', $row . '===== Add to Calendar =====', $existing);
      }
      elseif(strpos($existing, '===== Add to Calendar =====') !== false)
          $new = str_replace('===== Add to Calendar =====', $row . '===== Add to Calendar =====', $existing);
      else
          $new = $existing . $row;

      saveWikiText($pageId, $new, 'Member added: ' . $name);

      return true;
  }

  /**
   * Regenerate an appearances event page, preserving Notes and Recording Links sections
   */
  public function regenAppearanceEventPage($pageId, $eventData)
  {
      if(!page_exists($pageId))
          return false;

      // Preserve Notes and Recording Links from existing page
      $existing = rawWiki($pageId);
      $notes = '';
      $recordingLinks = '';

      if(preg_match('/===== Notes =====\s*(.*?)(?====== |===== |$)/s', $existing, $m))
          $notes = trim($m[1]);
      if(preg_match('/===== Recording Links =====\s*(.*?)(?====== |===== |$)/s', $existing, $m))
          $recordingLinks = trim($m[1]);

      // Preserve existing members table rows
      $memberRows = [];
      if(preg_match_all('/^\|  .+  \|  .+  \|  .+  \|/m', $existing, $m))
          $memberRows = $m[0];

      // Temporarily allow overwriting existing page
      $calendarPage = 'calendars:appearances_calendar';
      $calendarName = 'Appearances Calendar';

      // Generate fresh page using createAppearanceEventPage logic
      // but inject preserved content and member rows
      $eventData['_preserved_notes']          = $notes;
      $eventData['_preserved_recording_links'] = $recordingLinks;
      $eventData['_preserved_member_rows']     = $memberRows;

      return $this->regenAppearanceEventPageContent($pageId, $calendarPage, $calendarName, $eventData);
  }

  /**
   * Internal: write regenerated appearances page content
   */
  protected function regenAppearanceEventPageContent($pageId, $calendarPage, $calendarName, $eventData)
  {
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');

      $title       = !empty($eventData['title'])       ? $eventData['title']       : ($eventData['eventname'] ?? '');
      $description = !empty($eventData['description']) ? $eventData['description'] : ($eventData['eventdescription'] ?? '');
      $location    = !empty($eventData['location'])    ? $eventData['location']    : ($eventData['eventlocation'] ?? '');
      $date        = !empty($eventData['date'])        ? $eventData['date']        : ($eventData['eventfrom'] ?? '');
      $dateTo      = !empty($eventData['dateto'])      ? $eventData['dateto']      : ($eventData['eventto'] ?? '');
      $time        = !empty($eventData['time'])        ? $eventData['time']        : ($eventData['eventfromtime'] ?? '');
      $uid         = !empty($eventData['uid'])         ? $eventData['uid']         : '';

      $icsUrl = DOKU_URL . 'lib/plugins/davcal/singleevent.php?uid=' . urlencode($uid) .
                '&occurrence_date=' . urlencode($date);

      $dtStart = new \DateTime($date);
      $dtEnd   = clone $dtStart;
      $calStart     = $dtStart->format('Ymd');
      $dtEnd->modify('+1 day');
      $calEnd       = $dtEnd->format('Ymd');
      $outlookStart = $dtStart->format('Y-m-d');
      $outlookEnd   = $dtEnd->format('Y-m-d');

      $calTitle    = rawurlencode($title);
      $calDesc     = rawurlencode($description);
      $calLocation = rawurlencode($location);

      $googleUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE' .
                   '&text=' . $calTitle . '&dates=' . $calStart . '/' . $calEnd .
                   '&details=' . $calDesc . '&location=' . $calLocation;

      $outlook365Url = 'https://outlook.office.com/calendar/0/deeplink/compose?subject=' . $calTitle .
                       '&startdt=' . rawurlencode($outlookStart) . '&enddt=' . rawurlencode($outlookEnd) .
                       '&body=' . $calDesc . '&location=' . $calLocation;

      $outlookLiveUrl = str_replace('outlook.office.com', 'outlook.live.com', $outlook365Url);

      $links = !empty($eventData['links']) ? $eventData['links'] : (!empty($eventData['attachments']) ? $eventData['attachments'] : []);
      $linksSection = '';
      if(!empty($links) && is_array($links))
          foreach($links as $link)
              if(!empty($link)) $linksSection .= '  * ' . $link . "\n";

      $content  = '====== ' . $title . ' ======' . "\n\n";
      $content .= '**Calendar:** [[' . $calendarPage . '|' . $calendarName . ']]' . "\n\n";
      $content .= '**Date:** ' . $date;
      if(!empty($time))
          $content .= ' ' . $time . ' UTC';
      if(!empty($dateTo) && $dateTo !== $date) $content .= ' - ' . $dateTo;
      $content .= "\n\n";
      if(!empty($location)) $content .= '**Location:** ' . $location . "\n\n";
      $content .= '===== Description =====' . "\n\n";
      $content .= (!empty($description) ? $description : '//No description provided//') . "\n\n";
      if(!empty($linksSection))
      {
          $content .= '===== Event Website =====' . "\n\n";
          $content .= $linksSection . "\n";
      }

      // Members section — preserve existing rows
      $content .= '===== Members at this Event =====' . "\n\n";
      $content .= '//Are you also going to this event? Edit this page to add/remove your info below, or use the "I\'m also going" button on the [[calendars:appearances_calendar|Appearances calendar page]].//' . "\n\n";
      $content .= '^  Name  ^  Table  ^  Panels/Workshops  ^  Stamp Rally  ^' . "\n";
      if(!empty($eventData['_preserved_member_rows']))
          $content .= implode("\n", $eventData['_preserved_member_rows']) . "\n";
      $stampUrl = DOKU_URL . 'lib/plugins/davcal/stamprally.php?page=' . rawurlencode($pageId);
      $content .= '|  [[' . $stampUrl . '|Generate Stamp Rally Sheet]]  ||||' . "\n\n";

      $content .= '===== Add to Calendar =====' . "\n\n";
      $content .= '  * [[' . $googleUrl . '|Google Calendar]]' . "\n";
      $content .= '  * [[' . $outlook365Url . '|Outlook 365]]' . "\n";
      $content .= '  * [[' . $outlookLiveUrl . '|Outlook Live]]' . "\n";
      $content .= '  * [[' . $icsUrl . '|iCalendar / Other (.ics)]]' . "\n\n";

      $content .= '===== Notes =====' . "\n\n";
      if(!empty($eventData['_preserved_notes']))
          $content .= $eventData['_preserved_notes'] . "\n\n";
      else
          $content .= "\n\n";

      $content .= '===== Recording Links =====' . "\n\n";
      if(!empty($eventData['_preserved_recording_links']))
          $content .= $eventData['_preserved_recording_links'] . "\n\n";
      else
          $content .= "\n";

      $content .= '<--- [[:calendars:appearances_calendar|Back to Appearances & Cons Calendar]]' . "\n";

      saveWikiText($pageId, $content, 'Regenerated from calendar event: ' . $title);

      return true;
  }

  /**
   * Get the UID of the most recently added event for a calendar page
   */
  public function getMostRecentEventUid($calendarPage)
  {
      $sqlite = $this->getDB();
      if(!$sqlite) return false;

      $calid = $this->getCalendarIdForPage($calendarPage);
      if(!$calid) return false;

      $row = $sqlite->queryRecord(
          'SELECT uid FROM calendarobjects WHERE calendarid = ? ORDER BY lastmodified DESC LIMIT 1',
          [$calid]
      );
      return $row ? $row['uid'] : false;
  }

  /**
   * Set X-DAVCAL-MEMBERS to 1 on a calendar entry by UID (idempotent)
   */
  public function setMembersFlag($calendarPage, $uid)
  {
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');

      $sqlite = $this->getDB();
      if(!$sqlite) return false;

      $calid = $this->getCalendarIdForPage($calendarPage);
      if(!$calid) return false;

      $query = 'SELECT uri, calendardata FROM calendarobjects WHERE calendarid = ? AND uid = ?';
      $row = $sqlite->queryRecord($query, [$calid, $uid]);
      if(!$row) return false;

      try {
          $vcalendar = \Sabre\VObject\Reader::read($row['calendardata']);
      } catch(\Exception $e) {
          return false;
      }
      $vevent = $vcalendar->VEVENT;
      if(!$vevent) return false;

      // Only update if not already set
      $current = $vevent->{'X-DAVCAL-MEMBERS'};
      if($current !== null) return true;

      $vevent->add('X-DAVCAL-MEMBERS', '1');
      $newData = $vcalendar->serialize();
      $now = new \DateTime();
      $updateQuery = 'UPDATE calendarobjects SET calendardata = ?, lastmodified = ?, size = ?, etag = ? WHERE calendarid = ? AND uid = ?';
      $sqlite->query($updateQuery, $newData, $now->getTimestamp(), strlen($newData), md5($newData), $calid, $uid);
      $this->updateSyncTokenLog($calid, $row['uri'], 'modified');

      return true;
  }

  /**
   * Return all public events across all calendars as an ICS feed.
   * Events are considered public if they have X-DAVCAL-PUBLIC:1 set.
   *
   * @param string $calendarName Optional name for the feed
   * @return string The ICS feed content
   */
  public function getPublicEventsAsICS($calendarName = 'Public Calendar')
  {
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');

      $sqlite = $this->getDB();
      if(!$sqlite)
          return false;

      // Get all calendar IDs
      $query = "SELECT DISTINCT calendarid FROM calendarobjects WHERE componenttype = 'VEVENT'";
      $rows = $sqlite->queryAll($query, []);

      $out = "BEGIN:VCALENDAR\r\n";
      $out .= "VERSION:2.0\r\n";
      $out .= "PRODID:-//DAVCal//DAVCal for DokuWiki//EN\r\n";
      $out .= "CALSCALE:GREGORIAN\r\n";
      $out .= "METHOD:PUBLISH\r\n";
      $out .= "X-WR-CALNAME:" . $calendarName . "\r\n";

      foreach($rows as $row)
      {
          $calid = $row['calendarid'];

          // Skip disabled calendars
          if(!$this->getCalendarStatus($calid))
              continue;

          $query = "SELECT calendardata FROM calendarobjects WHERE calendarid = ? AND componenttype = 'VEVENT'";
          $events = $sqlite->queryAll($query, [$calid]);

          foreach($events as $event)
          {
              $vcal = \Sabre\VObject\Reader::read($event['calendardata']);
              foreach($vcal->VEVENT as $vevent)
              {
                  $publicProp = $vevent->{'X-DAVCAL-PUBLIC'};
                  if($publicProp !== null && (string)$publicProp === '1')
                  {
                      // For appearances events with members, append members table to description
                      $membersProp = $vevent->{'X-DAVCAL-MEMBERS'};
                      if($membersProp !== null && (string)$membersProp === '1')
                      {
                          try {
                              $summary = (string)$vevent->SUMMARY;
                              $dtstart = $vevent->DTSTART;
                              $dateStr = $dtstart ? $dtstart->getDateTime()->format('Y-m-d') : '';
                              $pageId = $this->getEventPageId('calendars:appearances_calendar', $summary, $dateStr);
                              if($pageId && page_exists($pageId))
                              {
                                  $wikiContent = rawWiki($pageId);
                                  $members = [];
                                  if(preg_match_all('/^\|  (.+?)  \|  (.*?)  \|  (.*?)  \|/m', $wikiContent, $matches, PREG_SET_ORDER))
                                  {
                                      foreach($matches as $match)
                                      {
                                          $name   = trim($match[1]);
                                          $table  = trim($match[2]);
                                          $panels = trim($match[3]);
                                          $line = $name;
                                          if($table && $table !== 'N/A')  $line .= ' | Table: ' . $table;
                                          if($panels && $panels !== 'N/A') $line .= ' | Panels: ' . $panels;
                                          $members[] = $line;
                                      }
                                  }
                                  if(!empty($members))
                                  {
                                      $existing = $vevent->DESCRIPTION;
                                      $desc = $existing ? (string)$existing : '';
                                      $membersText = "\n\nMembers attending:\n" . implode("\n", $members);
                                      $vevent->remove('DESCRIPTION');
                                      $vevent->add('DESCRIPTION', $desc . $membersText);
                                  }
                              }
                          } catch(\Exception $e) {
                              // Silently skip members lookup on error
                          }
                      }
                      $out .= $vevent->serialize();
                  }
              }
          }
      }

      $out .= "END:VCALENDAR\r\n";
      return $out;
  }

  /**
   * Build the public events as a JSON-ready array for the FullCalendar embed.
   *
   * Unlike the ICS feed, this expands recurrences server-side (one concrete
   * occurrence per row via sabre's EventIterator) and emits each occurrence as
   * an offset-stamped instant through convertIcalDataToEntry(). FullCalendar's
   * JSON source honours its timeZone setting (the ICS connector does not), so
   * the embed can display any zone and switch live via a dropdown.
   *
   * Times are emitted in UTC; the client shifts them to the chosen zone. Each
   * entry also carries startUtc/endUtc so the popup and add-to-calendar links
   * read a true instant directly rather than a FullCalendar display marker.
   *
   * @param string $startDate Window start (ISO8601), usually sent by FullCalendar
   * @param string $endDate   Window end (ISO8601)
   * @param string $timezone  Emission zone; defaults to UTC
   *
   * @return array|false Array of event entries, or false on DB error
   */
  public function getPublicEventsAsJSON($startDate = null, $endDate = null, $timezone = 'UTC')
  {
      require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');

      if($timezone !== '' && $timezone !== 'local')
          $tz = new \DateTimeZone($timezone);
      else
          $tz = new \DateTimeZone('UTC');

      $sqlite = $this->getDB();
      if(!$sqlite)
          return false;

      $startTs = ($startDate !== null) ? new \DateTime($startDate) : null;
      $endTs   = ($endDate   !== null) ? new \DateTime($endDate)   : null;

      $data = array();

      $calRows = $sqlite->queryAll("SELECT DISTINCT calendarid FROM calendarobjects WHERE componenttype = 'VEVENT'", array());

      foreach($calRows as $calRow)
      {
          $calid = $calRow['calendarid'];
          if(!$this->getCalendarStatus($calid))
              continue;

          $query = "SELECT calendardata, uid FROM calendarobjects WHERE calendarid = ? AND componenttype = 'VEVENT'";
          $params = array($calid);
          if($startTs !== null) { $query .= " AND lastoccurence > ?";  $params[] = $startTs->getTimestamp(); }
          if($endTs   !== null) { $query .= " AND firstoccurence < ?"; $params[] = $endTs->getTimestamp(); }

          $rows = $sqlite->queryAll($query, $params);

          foreach($rows as $row)
          {
              if(!isset($row['calendardata']))
                  continue;

              $vcal = \Sabre\VObject\Reader::read($row['calendardata']);
              $master = $vcal->VEVENT;   // first VEVENT = series master / single event
              if($master === null)
                  continue;

              // The public flag lives on the master VEVENT. Skip private series
              // before doing any (potentially expensive) expansion.
              $publicProp = $master->{'X-DAVCAL-PUBLIC'};
              if($publicProp === null || (string)$publicProp !== '1')
                  continue;

              if($master->RRULE !== null)
              {
                  $seriesEvents = array();
                  foreach($vcal->VEVENT as $seriesEvent)
                      $seriesEvents[] = $seriesEvent;
                  $rEvents = new \Sabre\VObject\Recur\EventIterator($seriesEvents);
                  $rEvents->rewind();
                  $count = 0;
                  while($rEvents->valid() && $count < 500)
                  {
                      $count++;
                      $occurrenceStart = $rEvents->getDtStart();
                      $occurrenceEnd   = $rEvents->getDtEnd();
                      if(($endTs !== null) && ($occurrenceStart->getTimestamp() > $endTs->getTimestamp()))
                          break;
                      if(($startTs !== null) && ($occurrenceEnd->getTimestamp() < $startTs->getTimestamp()))
                      {
                          $rEvents->next();
                          continue;
                      }
                      $event = $rEvents->getEventObject();
                      $entry = $this->convertIcalDataToEntry($event, 'public', $tz, $row['uid'], null, true);
                      $this->finalizePublicEntry($entry, $event);
                      $data[] = $entry;
                      $rEvents->next();
                  }
              }
              else
              {
                  $entry = $this->convertIcalDataToEntry($master, 'public', $tz, $row['uid'], null);
                  $this->finalizePublicEntry($entry, $master);
                  $data[] = $entry;
              }
          }
      }

      return $data;
  }

  /**
   * Finalize a public JSON entry: attach true-UTC copies of the times and, for
   * appearances events with members, append the members table to the
   * description (mirrors the ICS feed's behaviour).
   *
   * @param array $entry The entry to finalize, modified in place
   * @param \Sabre\VObject\Component $vevent The source event/occurrence
   *
   * @return void
   */
  private function finalizePublicEntry(&$entry, $vevent)
  {
      // Feed is emitted in UTC, so start/end are already true instants. Copy
      // them so the client never has to reconstruct an instant from a marker.
      $entry['startUtc'] = isset($entry['start']) ? $entry['start'] : '';
      $entry['endUtc']   = isset($entry['end'])   ? $entry['end']   : '';

      $membersProp = $vevent->{'X-DAVCAL-MEMBERS'};
      if($membersProp !== null && (string)$membersProp === '1')
      {
          try {
              $summary = (string)$vevent->SUMMARY;
              $dtstart = $vevent->DTSTART;
              $dateStr = $dtstart ? $dtstart->getDateTime()->format('Y-m-d') : '';
              $pageId = $this->getEventPageId('calendars:appearances_calendar', $summary, $dateStr);
              if($pageId && page_exists($pageId))
              {
                  $wikiContent = rawWiki($pageId);
                  $members = array();
                  if(preg_match_all('/^\|  (.+?)  \|  (.*?)  \|  (.*?)  \|/m', $wikiContent, $matches, PREG_SET_ORDER))
                  {
                      foreach($matches as $match)
                      {
                          $name   = trim($match[1]);
                          $table  = trim($match[2]);
                          $panels = trim($match[3]);
                          $line = $name;
                          if($table && $table !== 'N/A')  $line .= ' | Table: ' . $table;
                          if($panels && $panels !== 'N/A') $line .= ' | Panels: ' . $panels;
                          $members[] = $line;
                      }
                  }
                  if(!empty($members))
                  {
                      $existing = isset($entry['description']) ? $entry['description'] : '';
                      $entry['description'] = $existing . "\n\nMembers attending:\n" . implode("\n", $members);
                  }
              }
          } catch(\Exception $e) {
              // Silently skip members lookup on error
          }
      }
  }
}
