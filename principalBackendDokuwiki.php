<?php

/**
 * Principal backend for DokuWiki - some functions are not implemented, as they
 * are currently not needed. Only the bare minimum is present.
 */

class DokuWikiSabrePrincipalBackend extends Sabre\DAVACL\PrincipalBackend\AbstractBackend {

    public function getPrincipalsByPrefix($prefixPath) 
    {
        global $auth;
        $users = $auth->retrieveUsers();
        $principals = array();
        foreach($users as $user => $info)
        {
            $principal = 'principals/'.$user;
            if(strpos($principal, $prefixPath) === 0)
                $data = $this->getPrincipalByPath($user);
                if(!empty($data))
                    $principals[] = $data;
        }
        return $principals;
    }
    
    public function getPrincipalByPath($path)
    {
        global $auth;
        $user = str_replace('principals/', '', $path);
        $userData = $auth->getUserData($user);
        if($userData === false)
            return array();
        
        return array('uri' => 'principals/'.$user,
            'email' => $userData['mail'],
            'displayname' => $userData['name'],
            'id' => 0);
    }

    public function updatePrincipal($path, \Sabre\DAV\PropPatch $propPatch)
    {
        
    }
    
    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {

    }
    
    
    public function getGroupMemberSet($principal)
    {
        return array();
    }
    
    public function getGroupMemberShip($principal)
    {
        return array();
    }
    
    public function setGroupMemberSet($principal, array $members)
    {
        throw new Exception\NotImplemented('Not Implemented');
    }

}
