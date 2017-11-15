<?php
/**
 * DokuWiki Plugin DAVCal (Events Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Böhler <dev@aboehler.at>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_davcal_events extends DokuWiki_Syntax_Plugin {
    
    protected $hlp = null;
    
    // Load the helper plugin
    public function syntax_plugin_davcal_events() {  
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
        $this->Lexer->addSpecialPattern('\{\{davcalevents>[^}]*\}\}',$mode,'plugin_davcal_events');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler){
        global $ID;
        $data = array('id' => array(),
                      'startdate' => 'today',
                      'numdays' => 30,
                      'startisend' => false,
                      'dateformat' => 'Y-m-d H:i',
                      'alldayformat' => 'Y-m-d',
                      'onlystart' => false,
                      'sort' => 'desc',
                      'timezone' => 'local',
                      'timeformat' => null,
                      );

        $lastid = $ID;
        $options = trim(substr($match,15,-2));
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
                case 'onlystart':
                    if(($val === 'on') || ($val === 'true'))
                        $data['onlystart'] = true;
                break;
                case 'startisend':
                    if(($val === 'on') || ($val === 'true'))
                        $data['startisend'] = true;
                break;
                case 'timezone':
                    $tzlist = \DateTimeZone::listIdentifiers(DateTimeZone::ALL);
                    if(in_array($val, $tzlist) || $val === 'no')
                        $data['timezone'] = $val;
                    else
                        msg($this->getLang('error_timezone_not_in_list'), -1);
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

        return $data;
    }

    private static function sort_events_asc($a, $b)
    {
        $from1 = new \DateTime($a['start']);
        $from2 = new \DateTime($b['start']);
        return $from2 < $from1;
    }
    
    private static function sort_events_desc($a, $b)
    {
        $from1 = new \DateTime($a['start']);
        $from2 = new \DateTime($b['start']);
        return $from1 < $from2;
    }
    
    /**
     * Create output
     */
    function render($format, Doku_Renderer $R, $data) {
        if($format == 'metadata')
        {
            $R->meta['plugin_davcal']['events'] = true;
            return true;
        }
        if(($format != 'xhtml') && ($format != 'odt')) return false;
        global $ID;
                
        $events = array();
        $from = $data['startdate'];
        $toStr = null;
        
        // Handle the various options to 'startDate'
        if($from === 'today')
        {
            $from = new \DateTime();
        }
        elseif(strpos($from, 'today-') === 0)
        {
            $days = intval(str_replace('today-', '', $from));
            $from = new \DateTime();
            $from->sub(new \DateInterval('P'.$days.'D'));
        }
        elseif(strpos($from, 'today+') === 0)
        {
            $days = intval(str_replace('today+', '', $from));
            $from = new \DateTime();
            $from->add(new \DateInterval('P'.$days.'D'));
        }
        else
        {
            $from = new \DateTime($from);
        }
        
        // Handle the option 'startisend'
        if($data['startisend'] === true)
        {
            if($data['numdays'] > 0)
            {
                $to = clone $from;
                $to->sub(new \DateInterval('P'.$data['numdays'].'D'));
                $fromStr = $to->format('Y-m-d');
            }
            else
            {
                $fromStr = null;
            }
            $toStr = $from->format('Y-m-d');
        }
        else
        {
            if($data['numdays'] > 0)
            {
                $to = clone $from;
                $to->add(new \DateInterval('P'.$data['numdays'].'D'));
                $toStr = $to->format('Y-m-d');
            }
            else
            {
                $toStr = null;
            }
            $fromStr = $from->format('Y-m-d');
        }

        // Support for timezone
        $timezone = $data['timezone'];
        
        // Filter events by user permissions
        $userEvents = $this->hlp->filterCalendarPagesByUserPermission($data['id']);
        
        // Fetch the events
        foreach($userEvents as $calPage => $color)
        {
            $events = array_merge($events, $this->hlp->getEventsWithinDateRange($calPage,
                                      $user, $fromStr, $toStr, $timezone, null,
                                      array('URL', 'X-COST')));

        }
        // Sort the events
        if($data['sort'] === 'desc')
            usort($events, array("syntax_plugin_davcal_events", "sort_events_desc"));
        else
            usort($events, array("syntax_plugin_davcal_events", "sort_events_asc"));
        
        $R->doc .= '<div class="davcalevents">';
        
        
        foreach($events as $event)
        {
            $from = new \DateTime($event['start']);

            $color = $data['id'][$event['page']];
            $R->doc .= '<div class="tile" style="background-color:'.$color.'">';
            $R->doc .= '<div class="text">';
            if($timezone !== 'local')
            {
                $from->setTimezone(new \DateTimeZone($timezone));
                $to->setTimezone(new \DateTimeZone($timezone));
            }
            if($event['allDay'] === true)
                $R->doc .= '<h1>'.$from->format($data['alldayformat']).'</h1>';
            else
                $R->doc .= '<h1>'.$from->format($data['dateformat']).'</h1>';
            if(!is_null($data['timeformat']) && $event['allDay'] != true)
            {
                $R->doc .= '<h2>'.$from->format($data['timeformat']).'</h2>';
            }
            $R->doc .= $event['title'].'<br>';
            $R->doc .= '<span class="costs">'.$this->getLang('costs').': '.$event['X-COST'].'</span>';
            $R->doc .= '<div class="dots">';
            $R->doc .= '<span></span>';
            $R->doc .= '<span></span>';
            $R->doc .= '<span></span>';
            $R->doc .= '</div>';
            $R->doc .= '</div>';
            $R->doc .= '</div>';
        }
        
        $R->doc .= '</div>';
        
        /*
        // Create tabular output
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
            if($timezone !== 'local')
            {
                $from->setTimezone(new \DateTimeZone($timezone));
                $to->setTimezone(new \DateTimeZone($timezone));
            }
            if($event['allDay'] === true)
                $R->doc .= $from->format($data['alldayformat']);
            else
                $R->doc .= $from->format($data['dateformat']);
            $R->tablecell_close();
            if(!$data['onlystart'])
            {
                $to = new \DateTime($event['end']);
                // Fixup all day events, which have one day in excess
                if($event['allDay'] === true)
                {
                    $to->sub(new \DateInterval('P1D'));
                }
                $R->tablecell_open();
                if($event['allDay'] === true)
                    $R->doc .= $to->format($data['alldayformat']);
                else
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
        */
    }


   
}

// vim:ts=4:sw=4:et:enc=utf-8:
