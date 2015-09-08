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
        return $auth->checkPass($username, $password);
    }
}
