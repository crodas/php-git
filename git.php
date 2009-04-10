<?php
define("OBJ_COMMIT",    16);
define("OBJ_TREE",      32);
define("OBJ_BLOB",      48);
define("OBJ_TAG",       64);
define("OBJ_OFS_DELTA", 96);
define("OBJ_REF_DELTA", 112);
define("GIT_INVALID_INDEX", 0x02);
//define("PACK_IDX_SIGNATURE",0xff744f63);
define("PACK_IDX_SIGNATURE","\377tOc");

abstract class GitBase {
    private $dir=false;
    protected $branch;
    protected $refs;
    private  $index=array();

    final protected function Exception($str) {
        throw new Exception ($str);
    }

    final protected function getFileContents($name,$relative=true,$raw=false) {
        if ( $relative ) {
            $name = $this->dir."/".$name;
        }
        if (!is_file($name)) {
            return false;
        }
        return $raw ? file_get_contents($name) :  trim(file_get_contents($name));
    }

    final function setRepo($dir) {
        if (!is_dir($dir)) {
            $this->Exception("$dir is not a valid dir");
        }
        $this->dir = $dir; 
        $this->branch = null;
        if (($head=$this->getFileContents("HEAD")) === false) {
            $this->dir = false;
            $this->Exception("Invalid repository, there is not HEAD file");
        }
        if (! $this->loadBranchesInfo() ){
            $this->dir = false;
            $this->Exception("Imposible to load information about the branches");
        }
    }

    final private function loadBranchesInfo() {
        $branch = & $this->branch;
        $files = glob($this->dir."/refs/heads/*");
        if (count($files) === 0) {
            $file = $this->getFileContents("packed-refs");
            $this->refs = $this->simpleParsing($file,-1,' ',false);
            foreach($this->refs as $ref=>$sha1) {
                if (strpos($ref,"refs/heads") === 0) {
                    $id = substr($ref,strrpos($ref,"/")+1);
                    $branch[ $id ] = $sha1;
                }
            }
            return count($branch) != 0;
        }
        foreach($files as $file) {
            $id = substr($file,strrpos($file,"/")+1);
            $branch[ $id ] = $this->getFileContents($file,false);
        }
        return true;
    }

    final function getObject($id) {
        $name = substr($id,0,2)."/".substr($id,2);
        if (($content = $this->getFileContents("objects/$name")) !== false) {
            /* the object is in loose format, less work for us */
            return gzinflate(substr($content,2));
        } else {
            $obj = $this->getPackedObject($id);
            die("missing file");
        }
    }

    final protected function _sha1_to_hex($sha1) {
        $str = "";
        for($i=0; $i < 20; $i++) {
            $hex = dechex(ord($sha1[$i]));
            if (strlen($hex)==1) $hex = "0".$hex;
            $str.= $hex;
        }
        return $str;
    }

    final private function getIndexInfo($path) {
        if (isset($this->index[$path]))
            return $this->index[$path];
        $content    = $this->getFileContents($path,false,true);
        $version    = 1;
        if (substr($content,0,4) == PACK_IDX_SIGNATURE) {
            $version = $this->getNumber(substr($content,4,4));
            if ($version != 2) {
                $this->Exception("The pack-id's version is $version, PHPGit only supports versio 1 or 2, please update this package, or downgrade your git repo");
            }
        }
        if ($version == 1) {
            $_offset = 256 * 4;
            $indexes = unpack("N*",substr($content,0,256*4));
            $nr = 0;
            for($i=0; $i < 256; $i++) {
                if (!isset($indexes[$i+1])) continue;
                $n =  $indexes[$i+1];
                if ($n < $nr) 
                    $this->Exception("corrupt index file ($n,$nr)\n");
                $nr = $n;
            }   
            $offset = $_offset;
            for($i=0; $i < $nr; $i++) {
                $field = substr($content,$offset,24);
                $id    = unpack("N",$field);
                $tmp[ $this->_sha1_to_hex(substr($field,4)) ] = $id[1];
                $offset += 24;
            }
            $this->index[$path] = $tmp;
        } else if ($version == 2) {
        }
        return $this->index[$path];
    }

    final private function getPackedObject($id) {
        /* load packages */
        foreach(glob($this->dir."/objects/pack/*.idx") as $findex) {
            $index = $this->getIndexInfo($findex);
            if (isset($index[$id]))  {

            }
        }
        return false;
    }


    final protected function simpleParsing($text,$limit=-1, $sep=' ', $findex=true) {
        $return = array();
        $i = 0;
        foreach(explode("\n",$text) as $line) {
            if ($limit != -1 && $limit < ++$i ) break; 
            list($first,$second) = explode($sep,$line,2);
            $return[ $findex ? $first : $second ] = $findex ? $second : $first;
        }
        return $return;
    }
}

class Git extends GitBase {
    function __construct($path='') {
        if ($path=='') continue;
        $this->setRepo($path);
    }

    function getBranches() {
        return array_keys($this->branch);
    }

    function getHistory($branch) {
        if (!isset($this->branch[$branch])) {
            $this->Exception("$branch is not a valid branch");
        }
        $object_id = $this->branch[$branch];

        do { 
            $commit    = $this->simpleParsing($this->getObject($object_id),4);
            $object[]  = $commit;
            $object_id = $commit['parent'];
        } while (strlen($object_id) > 0);
        var_dump($history);
    }    
}

$repo = new Git("/home/crodas/projects/playground/phpserver/phplibtextcat/.git");
$repo = new Git("/home/crodas/projects/bigfs/.git");
var_dump($repo->getBranches());
var_dump($repo->getHistory('master'));


?>
