<?php
require dirname(__FILE__)."/git.php";

abstract class Git_Http_Base extends Git
{
    private $_http;
    private $_repo;
    private $_bare;

    final function setRepoURL($url='')
    {
        if ($url=='') {
            return false;
        }
        $pUrl = parse_url($url);
        if ($pUrl['scheme'] != 'http' && $pUrl['scheme'] != 'https') {
            return false;
        }
        $this->_http = $url;
        return true;
    }

    final function setRepoPath($path)
    {
        if (!is_dir($path)) {
            return false;
        }
        $this->_repo = $path;
    }

    final function setBare($bare)
    {
        $this->_bare = (bool) $bare;
    }

    final function doClone()
    {
        $info = $this->getRemoteFile("info/refs");
    }

    abstract function initMod();
    abstract function getRemoteFile();
}

final class Git_Http_Clone extends Git_Http_Base
{
    private $_http;

    function initMod() {
        $http = & $this->_http;

        $http->timeout     = 30;
        $http->user_agent  = "PHPGit/1.0 Http clone (http://cesar.la/projects/phpgit)" 
        $http->prefer_curl = 0;
    }

    function getRemoteFile($file) {
        $url = $this->
    }
}

$clone = new Git_Http_Clone();
$this->setRepoURL("http://github.com/crodas/phplibtextcat.git");
$this->setRepoPath("/tmp/");
$clone->doClone();

?>
