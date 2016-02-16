<?php
/**
 * DokuWiki Plugin DAVCal (Table Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Böhler <dev@aboehler.at>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_davcal_table extends DokuWiki_Syntax_Plugin {
    
    protected $hlp = null;
    
    // Load the helper plugin
    public function syntax_plugin_davcal_table() {  
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
        $this->Lexer->addSpecialPattern('\{\{davcaltable>[^}]*\}\}',$mode,'plugin_davcal_table');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        global $ID;
        $options = trim(substr($match,14,-2));
        $options = explode(',', $options);

        $data = array('id' => array(),
                      'startdate' => 'today',
                      'numdays' => 30,
                      'dateformat' => 'Y-m-d H:i',
                      'alldayformat' => 'Y-m-d',
                      'onlystart' => false,
                      'sort' => 'desc',
                      'timezone' => 'local'
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
                case 'onlystart':
                    if(($val === 'on') || ($val === 'true'))
                        $data['onlystart'] = true;
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
    function render($format, &$R, $data) {
        if($format == 'metadata')
        {
            $R->meta['plugin_davcal']['table'] = true;
            return true;
        }
        if(($format != 'xhtml') && ($format != 'odt')) return false;
        global $ID;
                
        $events = array();
        $from = $data['startdate'];
        if($from === 'today')
        {
            $from = new \DateTime();
        }
        elseif(strpos($from, 'today-') === 0)
        {
            $days = intval(str_replace('today-', '', str_replace(' ', '', $from)));
            $from = new \DateTime();
            $from->sub(new \DateInterval('P'.$days.'D'));
        }
        else
        {
            $from = new \DateTime($from);
        }
        $to = clone $from;
        $to->add(new \DateInterval('P'.$data['numdays'].'D'));
        $timezone = $data['timezone'];
        foreach($data['id'] as $calPage => $color)
        {
            $events = array_merge($events, $this->hlp->getEventsWithinDateRange($calPage, 
                                      $user, $from->format('Y-m-d'), $to->format('Y-m-d'),
                                      $timezone)); 
        }
        if($data['sort'] === 'desc')
            usort($events, array("syntax_plugin_davcal_table", "sort_events_desc"));
        else
            usort($events, array("syntax_plugin_davcal_table", "sort_events_asc"));
        
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
    }


   
}

// vim:ts=4:sw=4:et:enc=utf-8:
