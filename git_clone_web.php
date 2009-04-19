<?php
require dirname(__FILE__)."/git.php";

abstract class Git_Http_Base extends Git
{
    private $_repo;
    private $_bare;
    protected $url;

    final function setRepoURL($url='')
    {
        if ($url=='') {
            return false;
        }
        $pUrl = parse_url($url);
        if ($pUrl['scheme'] != 'http' && $pUrl['scheme'] != 'https') {
            return false;
        }
        $this->url = $url;
        return true;
    }

    final function setRepoPath($path)
    {
        if (!$this->_bare) {
            $path .= ".git";
        }
        $this->_repo = $path;
    }

    final function setBare($bare)
    {
        $this->_bare = (bool) $bare;
    }

    private function _mkdir($dir)
    {
        if (!is_dir($dir)) {
            $ndir = "";
            foreach (explode("/", $dir) as $pdir) {
                $ndir .= "$pdir/";
                if (!is_dir($ndir)) {
                    mkdir($ndir);
                }
            }
        }
    }

    final function doClone()
    {
        $base = $this->_repo;
        $this->_mkdir($base);
        $this->initMod();

        /* fetch head file */
        $head = $this->getRemoteFile("HEAD");

        /* get information file */
        $refs = $this->getRemoteFile("info/refs");
        $info = $this->simpleParsing($refs, -1, "\t", false);
        /* unpack the info and store it as local file */
        foreach ($info as $file => $id) {
            $this->saveFile($file, $id);
        }
        /* open actual repo */
        $this->setRepo($base);

        /* iterate over what we got and get all the commits */
        foreach ($info as $branch => $id) {
            try {
                echo "Getting branch $branch\n";
                $this->fetchCommit($id);
            } catch (Exception $e) {
            }
        }

        /* if it bare?  */
        if (!$this->_bare) {
            $head = $this->simpleParsing($head, 1, ' ', false);
            $dir  = substr($base, 0, strrpos($base, '/'));
            $this->saveToWorkingDir($info[key($head)], $dir);
        }
    }

    final function saveToWorkingDir($id,$prefix)
    {
        $base   = $this->_repo;
        $commit = $this->getObject($id, $type);
        if ($type != OBJ_COMMIT) {
            return;
        }

        $tree = $this->getObject($commit['tree']);
        foreach ($tree as $file) {
            if (!$file->is_dir) {
                $content = $this->getObject($file->id);
                file_put_contents($prefix."/{$file->name}", $content);
            } else {
                $dir = "$prefix/{$file->name}";
                mkdir($dir);
                $this->saveToWorkingDir($file->id, $dir);
            }
        }
    }

    final function fetchObject($id,&$type)
    {
        $object = $this->getObject($id, $type);
        if ($object === false) {
            $name = substr($id, 0, 2)."/".substr($id, 2);
            try {
                $this->getRemoteFile("objects/$name");
            } catch (Exception $e) { 
                $pack = $this->getRemoteFile("objects/info/packs");
                $pack = $this->simpleParsing($pack);
                /* getting pack */
                $pack = "objects/pack/".$pack['P'];
                $this->getRemoteFile($pack);
                $this->getRemoteFile(substr($pack, 0, strlen($pack)-4)."idx");
            }
            $object = $this->getObject($id, $type);
        }
        if ($object === false) {
            $this->throwException("Error, object not found");
        }
        
        return $object;
    }

    final function fetchCommit($id)
    {
        $object = $this->fetchObject($id, $type);
        if ($type != OBJ_COMMIT) {
            $this->throwException("Panic: Repo error, unexpected object type");
        }
        if (isset($object['parent'])) {
            $this->fetchCommit($object['parent']);
        }
        $this->fetchTree($object['tree']);
    }

    final function fetchTree($id)
    {
        $object = $this->fetchObject($id, $type);
        if ($type != OBJ_TREE) {
            $this->throwException("Panic: Repo error, unexpected object type");
        }
        foreach ($object as $file) {
            $this->fetchObject($file->id, $type);
            if ($type == OBJ_TREE) {
                $this->fetchTree($file->id);
            }
        }
    }

    final function getRemoteFile($file)
    {
        if (is_file($this->_repo."/$file")) {
            return file_get_contents($this->_repo."/$file");
        }
        echo "Getting $file\n";
        $content = $this->wgetFile($file);
        $this->saveFile($file, $content);
        return $content;
    }

    final function saveFile($file,$content)
    {
        $file = $this->_repo."/".$file;
        $this->_mkdir(dirname($file));
        $fp = fopen($file, "wb");
        fwrite($fp, $content);
        fclose($fp);
    }

    abstract protected function initMod();
    abstract protected function wgetFile($file);
}

require "contrib/http.php";

final class Git_Http_Clone extends Git_Http_Base
{
    private $_http;

    protected function initMod()
    {
        $http = & $this->_http;
        $http = new http_class;

        $http->timeout     = 30;
        $http->user_agent  = "PHPGit/1.0 (http://cesar.la/projects/phpgit)";
        $http->prefer_curl = 0;
    }

    protected function wgetFile($file)
    {
        $http  = & $this->_http;
        $url   = $this->url."/".$file;
        $error = $http->GetRequestArguments($url, $arguments);
        if ($error!="") {
            $this->throwException($error);
        }
        $error = $http->Open($arguments);
        if ($error!="") {
            $this->throwException($error);
        }
        $error = $http->SendRequest($arguments);
        if ($error!="") {
            $this->throwException($error);
        }
        $error = $http->ReadReplyHeaders($headers);
        if ($error!="") {
            $this->throwException($error);
        }
        if ($http->response_status != 200) {
            $http->Close();
            $error = "Page not found";
            $this->throwException($error);
        }

        $content = "";
        while (true) {
            $error = $http->ReadReplyBody($body, 1000);
            if ($error!="" || strlen($body) == 0) {
                if ($error!="") {
                    $this->throwException($error);
                }
                break;
            }
            $content .= $body;
        }
        if (strlen($content) != $headers['content-length']) {
            $this->throwException("Mismatch size");
        }
        $http->Close();
        return $content;
    }
}

$clone = new Git_Http_Clone();
//$clone->setRepoURL("http://github.com/crodas/phplibtextcat.git");
$clone->setRepoURL("http://localhost/wp/phptc.git");
$clone->setRepoURL("http://git.ischo.com/libs3.git");
$clone->setRepoPath("tmp/");
$clone->doClone();

?>
