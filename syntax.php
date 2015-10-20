<?php
/**
 * DokuWiki Plugin DAVCal (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Böhler <dev@aboehler.at>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_davcal extends DokuWiki_Syntax_Plugin {
    
    protected $hlp = null;
    
    // Load the helper plugin
    public function syntax_plugin_davcal() {  
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
        $this->Lexer->addSpecialPattern('\{\{davcal>[^}]*\}\}',$mode,'plugin_davcal');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        global $ID;
        $options = trim(substr($match,9,-2));
        $options = explode(',', $options);

        $data = array('name' => $ID,
                      'description' => $this->getLang('created_by_davcal'),
                      'id' => array(),
                      'settings' => 'show',
                      'view' => 'month',
                      'table' => false,
                      'startdate' => 'today',
                      'numdays' => 30,
                      'dateformat' => 'Y-m-d H:i',
                      'onlystart' => false,
                      );
        $lastid = $ID;

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
                        $data['id'][$val] = '#3a87ad';
                break;
                case 'color':
                    $data['id'][$lastid] = $val;
                break;
                case 'view':
                    if(in_array($val, array('month', 'basicDay', 'basicWeek', 'agendaWeek', 'agendaDay')))
                        $data['view'] = $val;
                    else
                        $data['view'] = 'month';
                break;
                case 'table':
                    $data['table'] = true;
                break;
                case 'onlystart':
                    if(($val === 'on') || ($val === 'true'))
                        $data['onlystart'] = true;
                break;
                default:
                    $data[$key] = $val;
            }
        }

        // Handle the default case when the user didn't enter a different ID
        if(empty($data['id']))
        {
            $data['id'] = array($ID => '#3a87ad');
        }
        // Only update the calendar name/description if the ID matches the page ID.
        // Disable this, if we output to table. 
        // Otherwise, the calendar is included in another page and we don't want
        // to interfere with its data.
        if(in_array($ID, array_keys($data['id'])) && ($data['table'] === false))
        {
            if(isset($_SERVER['REMOTE_USER']) && !is_null($_SERVER['REMOTE_USER']))
                $username = $_SERVER['REMOTE_USER'];
            else
                $username = uniqid('davcal-');
            $this->hlp->setCalendarNameForPage($data['name'], $data['description'], $ID, $username);
            $this->hlp->setCalendarColorForPage($data['id'][$ID], $ID);
        }

        p_set_metadata($ID, array('plugin_davcal' => $data));

        return $data;
    }
    
    /**
     * Create output
     */
    function render($format, &$R, $data) {
        if(($format != 'xhtml') && ($format != 'odt')) return false;
        global $ID;
                
        // Handle creating a table
        // For ODT output, always create the table as the calendar doesn't make sense
        if(($data['table'] === true) || ($format == 'odt'))
        {
            $events = array();
            $from = $data['startdate'];
            if($from === 'today')
                $from = new \DateTime();
            else
                $from = new \DateTime($from);
            $to = clone $from;
            $to->add(new \DateInterval('P'.$data['numdays'].'D'));
            foreach($data['id'] as $calPage => $color)
            {
                $events = array_merge($events, $this->hlp->getEventsWithinDateRange($calPage, 
                                          $user, $from->format('Y-m-d'), $to->format('Y-m-d'))); 
            }
            $R->table_open();
            $R->tablethead_open();
            $R->tableheader_open();
            $R->doc .= $data['onlystart'] ? $this->getLang('at') : $this->getLang('from');
            $R->tableheader_close();
            if(!$data['onlystart'])
            {
                $R->tableheader_open();
                $R->doc .= $this->getLang('to');
                $R->tableheader_close();
            }
            $R->tableheader_open();
            $R->doc .= $this->getLang('title');
            $R->tableheader_close();
            $R->tableheader_open();
            $R->doc .= $this->getLang('description');
            $R->tableheader_close();
            $R->tablethead_close();
            foreach($events as $event)
            {
                $R->tablerow_open();
                $R->tablecell_open();
                $from = new \DateTime($event['start']);
                $to = new \DateTime($event['end']);
                $R->doc .= $from->format($data['dateformat']);
                $R->tablecell_close();
                if(!$data['onlystart'])
                {
                    $R->tablecell_open();
                    $R->doc .= $to->format($data['dateformat']);
                    $R->tablecell_close();
                }
                $R->tablecell_open();
                $R->doc .= $event['title'];
                $R->tablecell_close();
                $R->tablecell_open();
                $R->doc .= $event['description'];
                $R->tablecell_close();
                $R->tablerow_close();
            }
            $R->table_close();
        }
        // Handle JavaScript calendar output
        else
        {
            $tzlist = \DateTimeZone::listIdentifiers(DateTimeZone::ALL);
            
            // Render the Calendar. Timezone list is within a hidden div,
            // the calendar ID is in a data-calendarid tag.
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


   
}

// vim:ts=4:sw=4:et:enc=utf-8:
