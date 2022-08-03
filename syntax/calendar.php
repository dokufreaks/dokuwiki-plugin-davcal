<?php
/**
 * DokuWiki Plugin DAVCal (Calendar Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Böhler <dev@aboehler.at>
 */

class syntax_plugin_davcal_calendar extends DokuWiki_Syntax_Plugin {
    
    protected $hlp = null;
    
    // Load the helper plugin
    public function __construct() {  
        $this->hlp =& plugin_load('helper', 'davcal');     
    }
    
    
    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'normal';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 165;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('\{\{davcal>[^}]*\}\}',$mode,'plugin_davcal_calendar');
        $this->Lexer->addSpecialPattern('\{\{davcalclient>[^}]*\}\}',$mode,'plugin_davcal_calendar');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler){
        global $ID;
        $data = array('name' => $ID,
                      'description' => $this->getLang('created_by_davcal'),
                      'id' => array(),
                      'settings' => 'show',
                      'view' => 'month',
                      'forcetimezone' => 'no',
                      'forcetimeformat' => 'no',
                      'fcoptions' => array(),
                      );        
        if(strpos($match, '{{davcalclient') === 0)
        {
            $options = trim(substr($match,15,-2));
            $defaultId = $this->getConf('default_client_id');
            if(isset($defaultId) && ($defaultId != ''))
            {
                $data['id'][$defaultId] = null;
                $lastid = $defaultId;
            }  
        }
        else
        {
            $options = trim(substr($match,9,-2));
            $lastid = $ID;
        }
        $options = explode(',', $options);

        foreach($options as $option)
        {
            list($key, $val) = explode('=', $option);
            $key = strtolower(trim($key));
            $val = trim($val);
            switch($key)
            {
                case 'id':
                    $lastid = $val;
                    if(!in_array($val, $data['id']))
                        $data['id'][$val] = null;
                break;
                case 'color':
                    $data['id'][$lastid] = $val;
                break;
                case 'view':
                    if(in_array($val, array('month', 'basicDay', 'basicWeek', 'agendaWeek', 'agendaDay', 'listWeek', 'listDay', 'listMonth', 'listYear')))
                        $data['view'] = $val;
                    else
                        $data['view'] = 'month';
                break;
                case 'fcoptions':
                    $fcoptions = explode(';', $val);
                    foreach($fcoptions as $opt)
                    {
                        list($o, $v) = explode(':', $opt, 2);
                        $data['fcoptions'][$o] = $v;
                    }
                break;
                case 'forcetimezone':
                    $tzlist = \DateTimeZone::listIdentifiers(DateTimeZone::ALL);
                    if(in_array($val, $tzlist) || $val === 'no')
                        $data['forcetimezone'] = $val;
                    else
                        msg($this->getLang('error_timezone_not_in_list'), -1);
                break;
                case 'forcetimeformat':
                    $tfopt = array('lang', '24h', '12h');
                    if(in_array($val, $tfopt) || $val === 'no')
                        $data['forcetimeformat'] = $val;
                    else
                        msg($this->getLang('error_option_error'), -1);
                break;
                default:
                    $data[$key] = $val;
            }
        }
        // Handle the default case when the user didn't enter a different ID
        if(empty($data['id']))
        {
            $data['id'] = array($ID => null);
        }

        // Fix up the colors, if no color information is given
        foreach($data['id'] as $id => $color)
        {
            if(is_null($color))
            {
                // If this is the current calendar or a WebDAV calendar, use the
                // default color
                if(($id === $ID) || (strpos($id, 'webdav://') === 0))
                {
                    $data['id'][$id] = '#3a87ad';
                }
                // Otherwise, retrieve the color information from the calendar settings
                else
                {
                    $calid = $this->hlp->getCalendarIdForPage($ID);
                    $settings = $this->hlp->getCalendarSettings($calid);
                    $color = $settings['calendarcolor'];
                    $data['id'][$id] = $color;
                }
            }
        }

        // Only update the calendar name/description if the ID matches the page ID.
        // Otherwise, the calendar is included in another page and we don't want
        // to interfere with its data.
        if(in_array($ID, array_keys($data['id'])))
        {
            if(isset($_SERVER['REMOTE_USER']) && !is_null($_SERVER['REMOTE_USER']))
                $username = $_SERVER['REMOTE_USER'];
            else
                $username = uniqid('davcal-');
            $this->hlp->setCalendarNameForPage($data['name'], $data['description'], $ID, $username);
            $this->hlp->setCalendarColorForPage($data['id'][$ID], $ID);
            $this->hlp->enableCalendarForPage($ID);
        }

        p_set_metadata($ID, array('plugin_davcal' => $data));

        return $data;
    }
    
    /**
     * Create output
     */
    function render($format, Doku_Renderer $R, $data) {
        if($format != 'xhtml') return false;
        global $ID;
        $tzlist = \DateTimeZone::listIdentifiers(DateTimeZone::ALL);
        
        // Render the Calendar. Timezone list is within a hidden div,
        // the calendar ID is in a data-calendarid tag.
        if($data['forcetimezone'] !== 'no')
            $R->doc .= '<div id="fullCalendarTimezoneWarning">'.sprintf($this->getLang('this_calendar_uses_timezone'), $data['forcetimezone']).'</div>';
        $R->doc .= '<div id="fullCalendar" data-calendarpage="'.$ID.'"></div>';
        $R->doc .= '<div id="fullCalendarTimezoneList" class="fullCalendarTimezoneList" style="display:none">';
        $R->doc .= '<select id="fullCalendarTimezoneDropdown">';
        $R->doc .= '<option value="local">'.$this->getLang('local_time').'</option>';
        foreach($tzlist as $tz)
        {
            $R->doc .= '<option value="'.$tz.'">'.$tz.'</option>';
        }
        $R->doc .= '</select></div>';
        if(($this->getConf('hide_settings') !== 1) && ($data['settings'] !== 'hide'))
        {            
            $R->doc .= '<div class="fullCalendarSettings"><a href="#" class="fullCalendarSettings"><img src="'.DOKU_URL.'lib/plugins/davcal/images/settings.png'.'">'.$this->getLang('settings').'</a></div>';
        }
     
    }


   
}

// vim:ts=4:sw=4:et:enc=utf-8:
