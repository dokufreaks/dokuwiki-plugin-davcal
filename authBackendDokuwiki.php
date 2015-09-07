<?php

class DokuWikiSabreAuthBackend extends Sabre\DAV\Auth\Backend\AbstractBasic
{    
    protected function validateUserPass($username, $password)
    {
        global $auth;
        return $auth->checkPass($username, $password);
    }
}
