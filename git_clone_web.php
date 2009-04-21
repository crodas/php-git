<?php
require dirname(__FILE__)."/git.php";

define("CE_NAMEMASK", 0x0fff);
define("CE_STAGEMASK", 0x3000);
define("CE_EXTENDED", 0x4000);
define("CE_VALID", 0x8000);
define("CE_STAGESHIFT", 12);

abstract class GitCheckout extends GitBase
{
    final private function _checkoutTree($id, $prefix,&$files)
    {
        $tree = $this->getObject($id);

        foreach ($tree as $file) {
            if (!$file->is_dir) {
                $content          = $this->getObject($file->id);
                $files[$file->id] = $prefix.$file->name;

                file_put_contents($prefix.$file->name, $content);
                chmod($prefix.$file->name, $file->perm);
                echo "$prefix{$file->name}\n";
            } else {
                $dir = "$prefix{$file->name}/";
                @mkdir($dir);
                $this->_checkoutTree($file->id, $dir, $files);
            }
        }
    }

    function checkout($dir, $commit)
    {
        //var_dump($this->getIndexInfo(".git/index"));
        $prevdir = getcwd();

        chdir($dir) or $this->throwException("Imposible to chdir");;
        
        $commit = $this->getObject($commit, $type);
        if ($type != OBJ_COMMIT) {
            return;
        }

        $this->_checkoutTree($commit['tree'], '' , $files);

        $index  = "DIRC";
        $index .= pack("N*", 2, count($files));

        foreach ($files as $id => $file) {
            $stat  = stat($file);
            $finfo = pack(
                    "N*", $stat['ctime'], 0, $stat['mtime'],
                    0, $stat['dev'], $stat['ino'], $stat['mode'],
                    $stat['uid'], $stat['gid'], $stat['size']
                );
            $finfo.= $this->hexToSha1($id);
            $finfo.= pack("n", strlen($file) | (0 << 12) );
            $finfo.= $file.chr(0);
            echo "$file \t\t\t $id (".(strlen($finfo) % 8).")\n";
            while (strlen($finfo) % 8 != 0) {
                $finfo .= chr(0);
            }
            $index .= $finfo;
        }

        $index .= $this->hexToSha1(sha1($index));
        file_put_contents(".git/index", $index);
        chdir($prevdir);
    }

    function getIndexInfo($filename)
    {
        $text = file_get_contents($filename);
        if ($this->sha1ToHex(substr($text, -20)) != sha1(substr($text, 0,strlen($text)-20))) {
            $this->throwException("Index file corrupt");
        }
        if (substr($text,0,4) !== "DIRC") {
            $this->throwException("$filename is not a valid index file");
        }
        $info     = unpack("N*",substr($text,4,8));
        $version  = $info[1];
        $nrofiles = $info[2];

        if ($version != 2 && $version != 3) {
            return false;
        }

        $return = array();
        $offset = 12;
        $null   = chr(0);
        for ($i=0; $i < $nrofiles; $i++) {
            $start     = $offset;
            $info      = unpack("N*", substr($text, $offset,40));
            $offset   += 40;
            $sha1      = $this->sha1ToHex(substr($text, $offset,20));
            $offset   += 20;
            $flags     = unpack("n*", substr($text, $offset,4));
            $offset   += 2;
            if ($flags[1] & CE_EXTENDED) {
                $offset += 2;
            }
            $end       = strpos($text, $null, $offset);
            $file_name = substr($text, $offset, $end - $offset);
            $offset   += strlen($file_name)+1;
            $return[]  = array(
                "ctime"    => $info[1],
                "mtime"    => $info[3],
                "idev"     => $info[5],
                "inode"    => $info[6],
                "mode"     => $info[7],
                "uid"      => $info[8],
                "gid"      => $info[9],
                "size"     => $info[10],
                "sha1"     => $sha1,
                "flags"    => $flags,
                "filename" => $file_name
            );
            while( ($offset - $start) % 8 != 0 && $offset++);
        }
        while ($text[$offset] == $null && $offset++);
        return $return;
    }
}

abstract class GitHttpBase extends GitCheckout
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
        $this->getRemoteFile("description");

        /* get information file */
        $refs = $this->getRemoteFile("info/refs");
        $info = $this->simpleParsing($refs, -1, "\t", false);
        /* unpack the info and store it as local file */
        $rpacked = false;
        foreach ($info as $file => $id) {
            $parts = explode("/", $file);
            if ($parts[1] == "remotes") {
                unset($info[$file]);
                continue;
            }
            try {
                $id          = $this->getRemoteFile($file);
                $info[$file] = trim($id);
            } catch (Exception $e) {
                if (!$rpacked) {
                    $this->getRemoteFile("packed-refs");
                    $rpacked = true;
                }
            }
        }
        /* open actual repo */
        $this->setRepo($base);

        /* iterate over what we got and get all the commits */
        foreach ($info as $branch => $id) {
            try {
                echo "Getting branch [$id] $branch\n";
                $this->fetchCommit($id);
            } catch (Exception $e) {
            }
        }

        /* if it bare?  */
        if (!$this->_bare) {
            $head = $this->simpleParsing($head, 1, ' ', false);
            $dir  = substr($base, 0, strrpos($base, '/'));
            $this->checkout($dir, $info[key($head)]);
        }

        $config   = array();
        $config[] = "[core]";
        $config[] = "repositoryformatversion = 0";
        $config[] = "filemode = true";
        $config[] = "bare = ".($this->_bare ? "true" : "false");
        $config[] = "logallrefupdates = true";
        foreach ($info as $file => $id) {
            $parts = explode("/", $file);
            if ($parts[1] != "heads") {
                continue;
            }
            $config[] = "[branch \"{$parts[2]}\"]";
            $config[] = "merge = $file";
        }
        file_put_contents("tmp/.git/config",implode("\n", $config));
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

    final function saveFile($file, $content)
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

final class GitHttpClone extends GitHttpBase
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
            $error = "Page not found $url";
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

$clone = new GitHttpClone();
$clone->setRepoURL("http://github.com/crodas/phplibtextcat.git");
$clone->setRepoURL("http://localhost/wp/phptc.git");
$clone->setRepoPath("tmp/");
$clone->doClone();

?>
