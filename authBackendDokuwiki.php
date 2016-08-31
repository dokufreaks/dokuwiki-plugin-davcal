<?php

/**
 * DokuWiki SabreDAV Auth Backend
 * 
 * Check a user ID / password combo against DokuWiki's auth system
 */

class DokuWikiSabreAuthBackend extends Sabre\DAV\Auth\Backend\AbstractBasic
{    
    protected function validateUserPass($username, $password)
    {
        global $auth;
        global $conf;
        $ret = $auth->checkPass($username, $password);
        dbglog('---- DAVCAL authBackendDokuwiki.php init');
        dbglog('checkPass called for username '.$username.' with result '.$ret);
        return $ret;
    }
}
