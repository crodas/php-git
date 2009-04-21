<?php
/**
 *  PHP Git
 *
 *  Pure-PHP class to read GIT repositories. It allows to
 *  perform read-only operations such as get commit history
 *  get files, get branches, and so forth.
 *
 *  PHP version 5
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   César D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 */


/**
 *  Git Http Base
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   César D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 */
abstract class GitClone extends GitCheckout
{
    private $_repo;
    private $_bare = false;
    protected $url;

    /**
     *  setRepoURL
     *
     *  @param string $url Http URL to clone
     *
     *  @return bool 
     */
    final public function setRepoURL($url='')
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

    /**
     *  setRepoPath
     *
     *  @param string $path Path to clone the remote repo.
     *
     *  @return nothing
     */
    final function setRepoPath($path)
    {
        if (!$this->_bare) {
            $path .= "/.git";
        }
        $this->_repo = $path;
    }

    /**
     *  setBare
     *
     *  Set the repository bared or not, it must be set before
     *  call setRepoURL,
     *
     *  @param bool $bare True if the repository is bared.
     *
     *  @return nothing
     */
    final function setBare($bare)
    {
        $this->_bare = (bool) $bare;
    }

    /**
     *  Safe way to create recursive directories.
     *
     *  @param string $dir Directory to create.
     *
     *  @return nothing
     */
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

    /**
     *  Perform the repository cloning.
     *
     *  @return true if success
     */
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
                    try {
                        $this->getRemoteFile("packed-refs");
                        $rpacked = true;
                    } catch (Exception $e) {
                        $this->saveFile($file, $id);
                    }
                }
            }
        }
        /* open actual repo */
        $this->setRepo($base);

        /* iterate over what we got and get all the commits */
        foreach ($info as $branch => $id) {
            try {
                $this->debug("Getting branch [$id] $branch");
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
        file_put_contents("{$base}/config", implode("\n", $config));
        return true;
    }


    /**
     *  Fetch Object
     *
     *  This function call to GitBase::GetObject, if fails download
     *  the file from the remote repository. After that works
     *  exactly as GitBase::GetObject.
     *
     *  @param string $id    SHA1 Object ID.
     *  @param int    &$type By-reference variable which contains the object's type.
     *
     *  @return mixed Object's contents or false.
     */
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

    /**
     *  fetch or get a Commit
     *
     *  @param string $id SHA1 Object ID.
     *
     *  @return mixed Object's contents or false.
     */
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

    /**
     *  fetch or get a Tree
     *
     *  @param string $id SHA1 Object ID.
     *
     *  @return mixed Object's contents or false.
     */
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

    /**
     *  get a remote file
     *
     *  Try to locate the $file locally if fails, fetch it 
     *  from the remote repo.
     *
     *  @param string $file File to get
     *
     *  @return string File content.
     */
    final function getRemoteFile($file)
    {
        if (is_file($this->_repo."/$file")) {
            return file_get_contents($this->_repo."/$file");
        }
        $this->debug("Getting $file");
        $content = $this->wgetFile($file);
        $this->saveFile($file, $content);
        return $content;
    }

    /**
     *  save File
     *
     *  @param string $file    File name
     *  @param string $content File content.
     *
     *  @return nothing
     */
    final function saveFile($file, $content)
    {
        $file = $this->_repo."/".$file;
        $this->_mkdir(dirname($file));
        $fp = fopen($file, "wb");
        fwrite($fp, $content);
        fclose($fp);
    }

    /**
     *  Simple function to show the progress
     *
     *  @param string $message What is happening
     *
     *  @return nothing
     */
    protected function debug($message) {
        echo "$message\n";
    }

    /**
     *  init the Http client module
     *
     *  Since the phpgit is not tied to any class, even if
     *  it cames with somes as samples, this function is abstract
     *  in order to implement later a http client module.
     *
     *  @return nothing
     */
    abstract protected function initMod();

    /**
     *  Get a file from the remote repo.
     *
     *  Since the phpgit is not tied to any class, even if
     *  it cames with somes as samples, this function is abstract
     *  in order to implement later a http client module.
     *
     *  @param string $url Url to get 
     *
     *  @return string File contents or an exception.
     */
    abstract protected function wgetFile($url);
}

?>
