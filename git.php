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

require dirname(__FILE__)."/gitbase.php";

/**
 *  Git Class
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   César D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 */
class Git extends GitBase
{
    private $_cache;

    // {{{ __construct 
    /**
     *  Class constructor
     *
     *  @param string $path Git path repo.
     *
     *  @return null
     */
    function __construct($path='')
    {
        if ($path=='') {
            return;
        }
        $this->setRepo($path);
    }
    // }}}

    // {{{ getBranches
    /**
     *  Returns all the branches
     *
     *  @return Array list of branches
     */
    function getBranches()
    {
        return array_combine(array_values($this->branch),
                array_keys($this->branch));
            
    }
    // }}}

    // {{{ getHistory
    /**
     *  Returns all commit history on a given branch.
     *
     *  @param string $branch Branch name
     *
     *  @return mixed Array with commits history or exception
     */
    function getHistory($branch)
    {
        if (isset($this->_cache['branch'])) {
            return $this->_cache['branch'];
        }
        if (!isset($this->branch[$branch])) {
            $this->throwException("$branch is not a valid branch");
        }
        $object_id = $this->branch[$branch];

        $history = array();
        do { 
            $object_text         = $this->getObject($object_id);
            $commit              = $this->simpleParsing($object_text, 4);
            $commit['comment']   = trim(strstr($object_text, "\n\n")); 
            $history[$object_id] = $commit;

            $object_id = isset($commit['parent']) ? $commit['parent'] : false;
        } while (strlen($object_id) > 0);
        return $this->_cache['branch'] = $history;
    }    
    // }}} 

    // {{{ getTags
    /**
     *  Get Tags
     *
     *  Returns the avaliable tags on a git repo.
     *
     *  @return array All tags avaliable
     */
    function getTags()
    {
        $tags = $this->getRefInfo('refs/tags');
        return array_combine(array_values($tags),
                array_keys($tags));
            
    }
    // }}}

    // {{{ getCommit
    /**
     *  Get commit list of files.
     *
     *  @param string $id Commit Id.
     *
     *  @return mixed Array with commit's files or an exception
     */
    function getCommit($id)
    {
        $found = false;
        foreach ($this->getBranches() as $branch) {
            $commits = $this->getHistory($branch);
            foreach ($commits as $commit) {
                if (isset($commit['tree']) && $commit['tree'] == $id) {
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            $this->throwException("$id is not a valid commit");
        }

        return  $this->parseTreeObject($this->getObject($id));
    }
    // }}}

    // {{{ getFile
    /**
     *
     *
     */
    function getFile($id,&$type=null)
    {
        $obj = $this->getObject($id, $type);
        if ($type == OBJ_TREE) {
            $obj = $this->parseTreeObject($obj);
        }
        return $obj;
    }
    // }}} 

}

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: sw=4 ts=4 fdm=marker
 * vim<600: sw=4 ts=4
 */
?>
