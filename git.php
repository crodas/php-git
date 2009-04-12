<?php
define("OBJ_COMMIT",    1);
define("OBJ_TREE",      2);
define("OBJ_BLOB",      3);
define("OBJ_TAG",       4);
define("OBJ_OFS_DELTA", 6);
define("OBJ_REF_DELTA", 7);
define("GIT_INVALID_INDEX", 0x02);
define("PACK_IDX_SIGNATURE","\377tOc");

abstract class GitBase {
    private $dir=false;
    protected $branch;
    protected $refs;
    private $cache_obj;
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
        if (isset($this->cache_obj[$id])) 
            return $this->cache_obj[$id];
        $name = substr($id,0,2)."/".substr($id,2);
        if (($content = $this->getFileContents("objects/$name")) !== false) {
            /* the object is in loose format, less work for us */
            return $this->cache_obj[$id] = $gzinflate(substr($content,2));
        } else {
            $obj = $this->getPackedObject($id);
            if ($obj !== false) {
                return $this->cache_obj[$id] = $obj[1];
            }
        }
        $this->Exception("object not found $id");
        return false;
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

    final public function getNumber($bytes) {
        $c = unpack("N",$bytes);
        return $c[1];
    }

    final private function getIndexInfo($path) {
        if (isset($this->index[$path]))
            return $this->index[$path];
        $content    = $this->getFileContents($path,false,true);
        $version    = 1;
        $hoffset    = 0;
        if (substr($content,0,4) == PACK_IDX_SIGNATURE) {
            $version = $this->getNumber(substr($content,4,4));
            if ($version != 2) {
                $this->Exception("The pack-id's version is $version, PHPGit only supports versio 1 or 2, please update this package, or downgrade your git repo");
            }
            $hoffset = 8;
        }
        $indexes = unpack("N*",substr($content,$hoffset,256*4));
        $nr = 0;
        for($i=0; $i < 256; $i++) {
            if (!isset($indexes[$i+1])) continue;
            $n =  $indexes[$i+1];
            if ($n < $nr) 
                $this->Exception("corrupt index file ($n,$nr)\n");
            $nr = $n;
        }   
        $_offset = $hoffset + 256 * 4;
        if ($version == 1) {
            $offset = $_offset;
            for($i=0; $i < $nr; $i++) {
                $field = substr($content,$offset,24);
                $id    = unpack("N",$field);
                $tmp[ $this->_sha1_to_hex(substr($field,4)) ] = $id[1];
                $offset += 24;
            }
            $this->index[$path] = $tmp;
        } else if ($version == 2) {
            $offset = $_offset;
            $keys = $data = array();
            for($i=0; $i < $nr;  $i++) {
                $keys[] = $this->_sha1_to_hex(substr($content,$offset,20));
                $offset += 20;
            } 
            for ($i=0; $i < $nr; $i++) {
                $offset += 4;
            }
            for ($i=0; $i < $nr; $i++) {
                $data[] = $this->getNumber(substr($content,$offset,4));
                $offset += 4;
            }
            $this->index[$path] = array_combine($keys,$data);
        }
        return $this->index[$path];
    }

    final private function getPackedObject($id,&$type=null) {
        /* load packages */
        foreach(glob($this->dir."/objects/pack/*.idx") as $findex) {
            $index = $this->getIndexInfo($findex);
            if (isset($index[$id]))  {
                $start  = $index[$id];
                /* open pack file */
                $pack_file = substr($findex,0,strlen($findex)-3)."pack";
                $fp     = fopen($pack_file, "rb");

                $object =  $this->unpack_object($fp,$start);

                fclose($fp);

                return $object;
            }
        }
        return false;
    }

    final private function unpack_object($fp,$start) {
        /* offset till the start of the object */
        fseek($fp,$start, SEEK_SET);
        /* read first byte, and get info */
        $header = ord(fread($fp,1));
        $type   = ($header >> 4) & 7;
        $hasnext= ($header & 128) >> 7; 
        $size   = $header & 0xf;
        $offset = 4;
        /* read size bytes */
        while ($hasnext) {
            $byte  = ord(fread($fp,1)); 
            $size |= ($byte & 0x7f) << $offset; 
            $hasnext= ($byte & 128) >> 7; 
            $offset+=7;
        }

        switch ($type) {
            case OBJ_COMMIT:
            case OBJ_TREE:
            case OBJ_BLOB:
            case OBJ_TAG:
                $obj = $this->unpack_compressed($fp,$size);
                return array($type,$obj);
                break;
            case OBJ_OFS_DELTA:
            case OBJ_REF_DELTA:
                $obj = $this->unpack_delta($fp,$start,$type,$size);
                return array($type,$obj);
                break;
            default:
                $this->Exception("Unkown object type $type");
        }
    }

    final private function unpack_compressed($fp, $size) {
        fseek($fp,2,SEEK_CUR);
        $out ="";
        do {
            $cstr = fread($fp,4096);
            $uncompressed = gzinflate($cstr);
            if ($uncompressed === false) {
                $this->Exception("fatal error while uncompressing at position ".ftell($fp));
            }
            $out .= $uncompressed; 
        } while (strlen($out) < $size);
        if ($size != strlen($out)) 
            $this->Exception("Weird error, the packed object size mismatch with the readed size");
        return $out;
    }

    final private function unpack_delta($fp,$obj_start,$type,$size) {
        $delta_offset = ftell($fp);
        $sha1 = fread($fp,20);
        if ($type == OBJ_OFS_DELTA) {
            $i = 0;
            $c = ord($sha1[$i]);
            $offset = $c & 0x7f;
            while (($c & 0x80) != 0)  {
                $c = ord($sha1[ ++$i ]);
                $offset += 1;
                $offset <<= 7;
                $offset |= $c & 0x7f;
            }
            $offset = $obj_start - $offset;
            $i++;
        } else {
            die("not implemented");
        }
        /* unpack object */
        list($type,$base) = $this->unpack_object($fp,$offset);
        /* get compressed delta */
        fseek($fp,$delta_offset+$i,SEEK_SET);
        $delta = $this->unpack_compressed($fp,$size); 

        /* patch the base with the delta */
        $obj = $this->patch_object($base, $delta);

        return $obj;
    }

    final protected function patch_delta_header_size(&$delta,$pos) {
        $size = $shift = 0;
        do {
            $byte = ord($delta[$pos++]);
            if ($byte == null) {
                $this->Exception("Unexpected delta's end.");
            }
            $size |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while (($byte & 0x80) != 0);
        return array($size, $pos);
    }

    final protected function patch_object(&$base,&$delta) {
        list($src_size,$pos) = $this->patch_delta_header_size($delta,0);
        if ($src_size != strlen($base)) {
            $this->Exception("Invalid delta data size ".$src_size." ".strlen($base));
        }
        list($dst_size,$pos) = $this->patch_delta_header_size($delta,$pos);
        $dest = "";
        $delta_size = strlen($delta);
        while ($pos < $delta_size) {
            $byte = ord($delta[$pos++]);
            if ( ($byte&0x80) != 0 ) {
                $pos--;
                $cp_off = $cp_size = 0;
                /* fetch start position */
                $flags = array(0x01,0x02,0x04,0x08);
                for($i=0; $i < 4; $i++) {
                    if ( ($byte & $flags[$i]) != 0) 
                        $cp_off |= ord($delta[++$pos]) << ($i * 8);
                }
                /* fetch length  */
                $flags = array(0x10,0x20,0x40);
                for($i=0; $i < 3; $i++) {
                    if ( ($byte & $flags[$i]) != 0) {
                        $cp_size |= ord($delta[++$pos]) << ($i * 8);
                    }
                }
                /* default length */
                if ($cp_size === 0) 
                    $cp_size = 0x10000;
                $part = substr($base,$cp_off,$cp_size);
                if (strlen($part) != $cp_size) {
                    $this->Exception("Patching error: expecting $cp_size bytes but only got ".strlen($part));
                }
                $pos++;
            } else if ($byte != 0) {
                $part = substr($delta,$pos,$byte);
                if (strlen($part) != $byte) {
                    $this->Exception("Patching error: expecting $byte bytes but only got ".strlen($part));
                } 
                $pos += $byte;
            } else {
                $this->Exception("Invalid delta data at position $pos");
            }
            $dest .= $part;
        }
        if (strlen($dest) != $dst_size) die(strlen($dest)." $dst_size error");
        return $dest;
    }

    final protected function simpleParsing($text,$limit=-1, $sep=' ', $findex=true) {
        $return = array();
        $i = 0;
        foreach(explode("\n",$text) as $line) {
            if ($limit != -1 && $limit < ++$i ) break; 
            $info = explode($sep,$line,2);
            if (count($info) != 2) break;
            list($first,$second) = $info; 
            $return[ $findex ? $first : $second ] = $findex ? $second : $first;
        }
        return $return;
    }
}

class Git extends GitBase {
    private $cache;
    function __construct($path='') {
        if ($path=='') continue;
        $this->setRepo($path);
    }

    function getBranches() {
        return array_keys($this->branch);
    }

    function getHistory($branch) {
        if (isset($this->cache['branch'])) 
            return $this->cache['branch'];
        if (!isset($this->branch[$branch])) {
            $this->Exception("$branch is not a valid branch");
        }
        $object_id = $this->branch[$branch];

        $history = array();
        do { 
            $object_text = $this->getObject($object_id);
            $commit    = $this->simpleParsing($object_text,4);
            $commit['comment'] = trim(strstr($object_text,"\n\n")); 
            $history[$object_id]  = $commit;
            $object_id = isset($commit['parent']) ? $commit['parent'] : false;
        } while (strlen($object_id) > 0);
        return $this->cache['branch'] = $history;
    }    

    function getCommit($id) {
        $found = false;
        foreach($this->getBranches() as $branch) {
            $commits = $this->getHistory($branch);
            foreach($commits as $commit) {
                if (isset($commit['tree']) && $commit['tree'] == $id) {
                    $found=true;
                    break;
                }
            }
        }
        if (!$found) {
            $this->Exception("$id is not a valid commit");
        }

        $data = $this->getObject($id);
        $data_len = strlen($data);
        $i = 0;
        $return = array();
        while ($i < $data_len) {
            $pos = strpos($data, "\0", $i);
            list($mode, $name) = explode(' ', substr($data, $i, $pos-$i), 2);
            $mode = intval($mode,8);
            $node = new stdClass;
            $node->id     = $this->_sha1_to_hex(substr($data,$pos+1,20));
            $node->name   = $name;
            $node->is_dir = !!($mode & 040000); 
            $return[] = $node;
            $i = $pos + 21;
        }
        return $return;
    }
}

$repo = new Git("/home/crodas/projects/playground/phpserver/phplibtextcat/.git");
//$repo = new Git("/home/crodas/projects/bigfs/.git");
var_dump($repo->getBranches());
$history = $repo->getHistory('master');
var_dump($history);
$commit = $repo->getCommit('b22d9c85cd28af4a4c8059614521cb42d94ade49');
//$commit = $repo->getCommit('fb12298bd8eac7f368d435b1256047d09d4773ef');
var_dump($commit);

$object = $repo->getObject('d7ca87cc92e7007b831f449e0afd9ff92c33dc83');
var_dump($object);

?>
