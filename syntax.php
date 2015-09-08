<?php
/**
 * DokuWiki Plugin tagrevisions (Syntax Component)
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
                      'description' => $this->getLang('created_by_davcal'));
        foreach($options as $option)
        {
            list($key, $val) = explode('=', $option);
            $key = strtolower(trim($key));
            $val = trim($val);
            $data[$key] = $val;
        }
        $this->hlp->setCalendarNameForPage($data['name'], $data['description'], $ID, $_SERVER['REMOTE_USER']);
        
        return array($data);
    }
    
    /**
     * Create output
     */
    function render($format, &$R, $data) {
        if($format != 'xhtml') return false;
        global $ID;
        $tzlist = \DateTimeZone::listIdentifiers(DateTimeZone::ALL);
        
        $R->doc .= '<div id="fullCalendar"></div>';
        $R->doc .= '<div id="fullCalendarTimezoneList" class="fullCalendarTimezoneList" style="display:none">';
        $R->doc .= '<select id="fullCalendarTimezoneDropdown">';
        $R->doc .= '<option value="local">'.$this->getLang('local_time').'</option>';
        foreach($tzlist as $tz)
        {
            $R->doc .= '<option value="'.$tz.'">'.$tz.'</option>';
        }
        $R->doc .= '</select></div>';
        $R->doc .= '<div class="fullCalendarSettings"><a href="#" class="fullCalendarSettings"><img src="'.DOKU_URL.'lib/plugins/davcal/images/settings.png'.'">'.$this->getLang('settings').'</a></div>';
     
    }


   
}

// vim:ts=4:sw=4:et:enc=utf-8:
