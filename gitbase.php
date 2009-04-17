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

define("OBJ_COMMIT", 1);
define("OBJ_TREE", 2);
define("OBJ_BLOB", 3);
define("OBJ_TAG", 4);
define("OBJ_OFS_DELTA", 6);
define("OBJ_REF_DELTA", 7);
define("GIT_INVALID_INDEX", 0x02);
define("PACK_IDX_SIGNATURE", "\377tOc");

/**
 *  Git Base Class
 *
 *  This class provide a set of fundamentals functions to
 *  manipulate (read only for now) a git repository.
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   César D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 */
abstract class GitBase
{
    private $_dir = false;
    private $_cache_obj;
    private $_index = array();
    protected $branch;
    protected $refs;
    private $_fp;

    // {{{ throwException
    /**
     *  Throw Exception 
     *
     *  This is the only function that throws an Exepction,
     *  used to easy portability to PHP4.
     *
     *  @param string $str Description of the exception
     *
     *  @return class Exception
     */
    final protected function throwException($str)
    {
        throw new Exception ($str);
    }
    // }}}

    // {{{ getFileContents
    /**
     *  Get File contents
     *
     *  This function reads a file and returns its content, 
     *
     *  @param string $path     File path
     *  @param bool   $relative If true, it appends the .git directory
     *  @param bool   $raw      If true, returns as is, otherwise return trimmed
     *
     *  @return mixed  File contents or false if fails. 
     */
    final protected function getFileContents($path, $relative=true, $raw=false)
    {
        if ( $relative ) {
            $path = $this->_dir."/".$path;
        }
        if (!is_file($path)) {
            return false;
        }
        return $raw ? file_get_contents($path) :  trim(file_get_contents($path));
    }
    // }}}

    // {{{ setRepo 
    /** 
     *  set Repository
     *
     *  @param string $dir Directory path
     *
     *  @return mixed True if sucess otherwise an Exception
     */
    final function setRepo($dir)
    {
        if (!is_dir($dir)) {
            $this->throwException("$dir is not a valid dir");
        }
        $this->_dir   = $dir; 
        $this->branch = null;
        if (($head=$this->getFileContents("HEAD")) === false) {
            $this->_dir = false;
            $this->throwException("Invalid repository, there is not HEAD file");
        }
        if (!$this->_loadBranchesInfo()) {
            $this->_dir = false;
            $this->throwException("Imposible to load information about branches");
        }
        return true;
    }
    // }}}

    // {{{ _loadBranchesInfo
    /**
     *  Load Branches Info
     *
     *  This function loads information about the avaliable
     *  branches in the actual repository.
     *
     *  @return boolean True is success, otherwise false.
     */
    final private function _loadBranchesInfo()
    {
        $this->branch = $this->getRefInfo('heads');
        return count($this->branch)!=0;
    }
    // }}} 

    // {{{ getRefInfo
    /** 
     *  Get Ref Information. The Ref is store as file
     *  in folders, or it can be packed.
     *
     *  @param string $path Reference path.
     *
     *  @return array Path with commits Ids.
     */
    final protected function getRefInfo($path="heads")
    {
        $files = glob($this->_dir."/refs/".$path."/*");
        $ref   = array(); 
        // temporary variable to store name
        $oldref = array();
        foreach ($files as $file) {
            $name = substr($file, strrpos($file, "/")+1);
            $id   = $this->getFileContents($file, false);
            if (isset($oldref[$name])) {
                continue;
            }
            $ref[$name]    = $id;
            $oldref[$name] = true;
        }
        $file = $this->getFileContents("packed-refs");
        if ($file !== false) {
            $this->refs = $this->simpleParsing($file, -1, ' ', false);
            $path       = "refs/$path";
            foreach ($this->refs as $name =>$sha1) {
                if (strpos($name, $path) === 0) {
                    $id = substr($name, strrpos($name, "/")+1);
                    if (isset($oldref[$id])) {
                        continue;
                    }
                    $oldref[$id] = $id;
                    $ref[$id]    = $sha1;
                }
            }
        }
        return $ref;
    }
    // }}}

    // {{{ getObject
    /** 
     *  Get Object
     *
     *  This function is main function of the class, it receive
     *  an object ID (sha1) and returns its content. The object
     *  could be store in "loose" format or packed.
     *
     *  @param string $id    SHA1 Object ID.
     *  @param int    &$type By-reference variable which contains the object's type.
     *  @param int    $cast  The readed object could be processed as $cast
     *
     *  @return mixed Object's contents or false.
     */
    final function getObject($id,&$type=null,$cast=null)
    {
        if (isset($this->_cache_obj[$id])) {
            $type = $this->_cache_obj[$id][0];
            return $this->_cache_obj[$id][1];
        }
        $name = substr($id, 0, 2)."/".substr($id, 2);
        if (($content = $this->getFileContents("objects/$name")) !== false) {
            /* the object is in loose format, less work for us */
            $content = gzinflate(substr($content, 2));
            if (strpos($content, chr(0)) !== false) {
                list($type, $content) = explode(chr(0), $content, 2);
                list($type, $size)    = explode(' ', $type);
                switch ($type) {
                case 'blob':
                    $type = OBJ_BLOB;
                    break;
                case 'tree':
                    $type = OBJ_TREE;
                    break;
                case 'commit':
                    $type = OBJ_COMMIT;
                    break;
                case 'tag':
                    $type = OBJ_TAG;
                    break;
                default:
                    $this->throwException("Unknow object type $type");
                }
                $content = substr($content, 0, $size);
            }
        } else {
            $obj = $this->_getPackedObject($id);
            if ($obj === false) {
                return false;
            }
            $content = $obj[1];
            $type    = $obj[0]; 
        }
        

        if ($cast != null) {
            $ttype = $cast;
        } else {
            $ttype = $type;
        }

        switch($ttype) {
        case OBJ_TREE:
            $obj = $this->parseTreeObject($content);
            break;
        case OBJ_COMMIT:
            $obj = $this->parseCommitObject($content);
            break;
        case OBJ_TAG:
            $obj            = $this->simpleParsing($content, 4);
            $obj['comment'] = trim(strstr($content, "\n\n")); 
            if (!isset($obj['object'])) {
                $this->throwException("Internal error, expected object");
            }
            $commit = $this->getObject($obj['object'], $c_type); 
            if ($c_type != OBJ_COMMIT) {
                $this->throwException("Unexpected object type");
            }
            $obj['Tree'] = $this->getObject($commit['tree']);
            break;
        case OBJ_BLOB:
            $obj = & $content;
            break;
        default:
            $this->throwException("Invalid type. Unknown $ttype.");
            return false;
        }
        $this->_cache_obj[$id] = array($type, $obj); 
        return $obj;
    }
    // }}} 

    // {{{ parseCommitObject
    /**
     *  ParseCommitObject
     *
     *  This function parse and returns information about a commit.
     *
     *  @param string $object_text Commit object id to parse.
     *
     *  @return object Commit object.
     */
    final protected function parseCommitObject($object_text)
    {
        $commit            = $this->simpleParsing($object_text, 4);
        $commit['comment'] = trim(strstr($object_text, "\n\n")); 

        $rexp = "/(.*) <?([a-z0-9\+\_\.\-]+@[a-z0-9\_\.\-]+)?> +([0-9]+) +(\+|\-[0-9]+)/i";
        preg_match($rexp, $commit["author"], $data);
        if (count($data) == 5) {
            $data[3]         += (($data[4] / 100) * 3600);
            $commit['author'] = $data[1];
            $commit['email']  = $data[2];
            $commit['time']   = gmdate("d/m/Y H:i:s", $data[3]);
        }
        return $commit;
    }
    // }}}

    // {{{ parseTreeObject
    /**
     *  Pase a Tree object
     *
     *  @param string &$data Object data.
     *
     *  @return object Object's tree
     */
    final protected function parseTreeObject(&$data)
    {
        $data_len = strlen($data);
        $i        = 0;
        $return   = array();
        while ($i < $data_len) {
            $pos = strpos($data, "\0", $i);
            if ($pos === false) {
                return false;
            }

            list($mode, $name) = explode(' ', substr($data, $i, $pos-$i), 2);

            $mode         = intval($mode, 8);
            $node         = new stdClass;
            $node->id     = $this->sha1ToHex(substr($data, $pos+1, 20));
            $node->name   = $name;
            $node->is_dir = !!($mode & 040000); 
            $i            = $pos + 21;

            $return[$node->name] = $node;
        }
        return $return;
    }
    //}}}

    // {{{ hexToSha1
    /**
     *  Transform a Hex-sha1 into its binary equivalent.
     *
     *  @param string $sha1 sha1 string
     *
     *  @return string
     */
    final protected function hexToSha1($sha1)
    {
        if (strlen($sha1) != 40) {
            return false;
        }
        $bin = "";
        for ($i=0; $i < 40; $i+=2) {
            $bin .= chr(hexdec(substr($sha1, $i, 2)));
        }
        return $bin;
    }
    // }}} 

    // {{{ sha1ToHex
    /**
     *  Transform a raw sha1 (20bytes) into it's hex representation
     *
     *  @param string $sha1 Raw sha1
     *
     *  @return string Hex sha1
     */
    final protected function sha1ToHex($sha1)
    {
        $str = "";
        for ($i=0; $i < 20; $i++) {
            $e   = ord($sha1[$i]); 
            $hex = dechex($e);
            if ($e < 16) {
                $hex = "0".$hex;
            }
            $str .= $hex;
        }
        return $str;
    }
    // }}}

    // {{{ getNumber
    /** 
     *  Transform 4bytes into a bigendian number.
     *
     *  @param string $bytes 4 bytes.
     *  
     *  @return int
     */
    final public function getNumber($bytes)
    {
        $c = unpack("N", $bytes);
        return $c[1];
    }
    // }}}

    // {{{ _getIndexInfo
    /**
     *  Loads the pack index file, and parse it.
     *
     *  @param string $path Index file path
     *
     *  @return mixed Index structure (array) or an exception
     */
    final private function _getIndexInfo($path)
    {
        if (isset($this->_index[$path])) {
            return $this->_index[$path];
        }
        $content = $this->getFileContents($path, false, true);
        $version = 1;
        $hoffset = 0;
        if (substr($content, 0, 4) == PACK_IDX_SIGNATURE) {
            $version = $this->getNumber(substr($content, 4, 4));
            if ($version != 2) {
                $this->throwException("The pack-id's version is $version, PHPGit
                        only supports version 1 or 2,please update this 
                        package, or downgrade your git repo");
            }
            $hoffset = 8;
        }
        $indexes = unpack("N*", substr($content, $hoffset, 256*4));
        $nr      = 0;
        for ($i=0; $i < 256; $i++) {
            if (!isset($indexes[$i+1])) {
                continue;
            }
            $n =  $indexes[$i+1];
            if ($n < $nr) {
                $this->throwException("corrupt index file ($n, $nr)\n");
            }
            $nr = $n;
        }   
        $_offset = $hoffset + 256 * 4;
        if ($version == 1) {
            $offset = $_offset;
            for ($i=0; $i < $nr; $i++) {
                $field     = substr($content, $offset, 24);
                $id        = unpack("N", $field);
                $key       = $this->sha1ToHex(substr($field, 4));
                $tmp[$key] = $id[1];
                $offset   += 24;
            }
            $this->_index[$path] = $tmp;
        } else if ($version == 2) {
            $offset = $_offset;
            $keys   = $data = array();
            for ($i=0; $i < $nr;  $i++) {
                $keys[]  = substr($content, $offset, 20);
                $offset += 20;
            } 
            for ($i=0; $i < $nr; $i++) {
                $offset += 4;
            }
            for ($i=0; $i < $nr; $i++) {
                $data[]  = $this->getNumber(substr($content, $offset, 4));
                $offset += 4;
            }
            $this->_index[$path] = array_combine($keys, $data);
        }
        return $this->_index[$path];
    }
    // }}}

    // {{{ _getPackedObject
    /**
     *  Get an object from the pack.
     *
     *  @param string $id sha1 (40bytes). object's id.
     *  
     *  @return mixed Objects content or false otherwise.
     */
    final private function _getPackedObject($id)
    {
        /* load packages */
        foreach (glob($this->_dir."/objects/pack/*.idx") as $findex) {
            $index = $this->_getIndexInfo($findex);
            $id    = $this->hextosha1($id);
            if (isset($index[$id])) {
                $start = $index[$id];
                /* open pack file */
                $pack_file = substr($findex, 0, strlen($findex)-3)."pack";
                if (!isset($this->_fp[$pack_file])) {
                    $this->_fp[$pack_file] = fopen($pack_file, "rb");
                }
                $fp = & $this->_fp[$pack_file];

                $object =  $this->_unpackObject($fp, $start);

                return $object;
            }
        }
        return false;
    }
    // }}}

    // {{{ _unpackObject 
    /**
     *  Unpack an file from the start bytes.
     *
     *  @param resource $fp    Filepointer.
     *  @param int      $start The object start position.
     *
     *  @return mixed Array with type and content or an exception
     */
    final private function _unpackObject($fp, $start)
    {
        /* offset till the start of the object */
        fseek($fp, $start, SEEK_SET);
        /* read first byte, and get info */
        $header  = ord(fread($fp, 1));
        $type    = ($header >> 4) & 7;
        $hasnext = ($header & 128) >> 7; 
        $size    = $header & 0xf;
        $offset  = 4;
        /* read size bytes */
        while ($hasnext) {
            $byte = ord(fread($fp, 1)); 
            $size   |= ($byte & 0x7f) << $offset; 
            $hasnext = ($byte & 128) >> 7; 
            $offset +=7;
        }

        switch ($type) {
        case OBJ_COMMIT:
        case OBJ_TREE:
        case OBJ_BLOB:
        case OBJ_TAG:
            $obj = $this->_unpackCompressed($fp, $size);
            return array($type, $obj);
            break;
        case OBJ_OFS_DELTA:
        case OBJ_REF_DELTA:
            $obj = $this->_unpackDelta($fp, $start, $type, $size);
            return array($type, $obj);
            break;
        default:
            $this->throwException("Unkown object type $type");
        }
    }
    // }}}

    // {{{ _unpackCompressed
    /** 
     *  Unpack a compressed object
     *
     *  @param resource $fp   Filepointer
     *  @param int      $size Object's start position.
     *
     *  @return mixed Object's content or an Exception
     */
    final private function _unpackCompressed($fp, $size)
    {
        $out = "";
        do {
            $cstr         = fread($fp, $size>4096 ? $size : 4096);
            $uncompressed = gzuncompress($cstr);
            if ($uncompressed === false) {

                $this->throwException("fatal error uncompressing $packed/$size");
            } 
            $out .= $uncompressed; 
        } while (strlen($out) < $size);

        if ($size != strlen($out)) {
            $this->throwException("Weird error, the packed object has invalid size");
        }
        return $out;
    }
    // }}}

    // {{{ _unpackDelta
    /** 
     *  Unpack a delta file, and it's other objects and apply the patch.
     *
     *  @param resource $fp        Filepointer
     *  @param int      $obj_start Delta start position.
     *  @param int      &$type     Delta type.
     *  @param int      $size      Delta size.
     *  
     *  @return mixed Object's content or an Exception
     */
    final private function _unpackDelta($fp, $obj_start, &$type, $size)
    {
        $delta_offset = ftell($fp);
        $sha1         = fread($fp, 20);
        if ($type == OBJ_OFS_DELTA) {
            $i      = 0;
            $c      = ord($sha1[$i]);
            $offset = $c & 0x7f;
            while (($c & 0x80) != 0) {
                $c       = ord($sha1[ ++$i ]);
                $offset += 1;
                $offset <<= 7;
                $offset |= $c & 0x7f;
            }
            $offset = $obj_start - $offset;
            $i++;
            /* unpack object */
            list($type, $base) = $this->_unpackObject($fp, $offset);
        } else {
            $base = $this->_getPackedObject($sha1);
            $i    = 20;
        }
        /* get compressed delta */
        fseek($fp, $delta_offset+$i, SEEK_SET);
        $delta = $this->_unpackCompressed($fp, $size); 

        /* patch the base with the delta */
        $obj = $this->patchObject($base, $delta);

        return $obj;
    }
    // }}}

    // {{{ patchDeltaHeaderSize
    /**
     *  Returns the delta's content size.
     *
     *  @param string &$delta Delta contents.
     *  @param int    $pos    Delta offset position.
     *
     *  @return mixed Delta size and position or an Exception.
     */
    final protected function patchDeltaHeaderSize(&$delta, $pos)
    {
        $size = $shift = 0;
        do {
            $byte = ord($delta[$pos++]);
            if ($byte == null) {
                $this->throwException("Unexpected delta's end.");
            }
            $size |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while (($byte & 0x80) != 0);
        return array($size, $pos);
    }
    // }}}

    // {{{ patchObject
    /**
     *  Apply a $base to a $delta
     *
     *  @param string &$base  String to apply to the delta.
     *  @param string &$delta Delta content.
     *
     *  @return mixed Objects content or an Exception
     */
    final protected function patchObject(&$base, &$delta)
    {
        list($src_size, $pos) = $this->patchDeltaHeaderSize($delta, 0);
        if ($src_size != strlen($base)) {
            $this->throwException("Invalid delta data size");
        }
        list($dst_size, $pos) = $this->patchDeltaHeaderSize($delta, $pos);

        $dest       = "";
        $delta_size = strlen($delta);
        while ($pos < $delta_size) {
            $byte = ord($delta[$pos++]);
            if ( ($byte&0x80) != 0 ) {
                $pos--;
                $cp_off = $cp_size = 0;
                /* fetch start position */
                $flags = array(0x01, 0x02, 0x04, 0x08);
                for ($i=0; $i < 4; $i++) {
                    if ( ($byte & $flags[$i]) != 0) {
                        $cp_off |= ord($delta[++$pos]) << ($i * 8);
                    }
                }
                /* fetch length  */
                $flags = array(0x10, 0x20, 0x40);
                for ($i=0; $i < 3; $i++) {
                    if ( ($byte & $flags[$i]) != 0) {
                        $cp_size |= ord($delta[++$pos]) << ($i * 8);
                    }
                }
                /* default length */
                if ($cp_size === 0) {
                    $cp_size = 0x10000;
                }
                $part = substr($base, $cp_off, $cp_size);
                if (strlen($part) != $cp_size) {
                    $this->throwException("Patching error: expecting $cp_size 
                            bytes but only got ".strlen($part));
                }
                $pos++;
            } else if ($byte != 0) {
                $part = substr($delta, $pos, $byte);
                if (strlen($part) != $byte) {
                    $this->throwException("Patching error: expecting $byte
                            bytes but only got ".strlen($part));
                } 
                $pos += $byte;
            } else {
                $this->throwException("Invalid delta data at position $pos");
            }
            $dest .= $part;
        }
        if (strlen($dest) != $dst_size) {
            $this->throwException("Patching error: Expected size and patched
                    size missmatch");
        }
        return $dest;
    }
    /* }}} */

    // {{{ simpleParsing
    /**
     *  Simple parsing
     *
     *  This function implements a simple parsing for configurations
     *  and description files from git.
     *
     *  @param string $text   string to parse
     *  @param int    $limit  lines to proccess.
     *  @param string $sep    separator string.
     *  @param bool   $findex If true the first column is the key if not is the data.
     *  
     *  @return Array 
     */
    final protected function simpleParsing($text, $limit=-1, $sep=' ', $findex=true)
    {
        $return = array();
        $i      = 0;
        foreach (explode("\n", $text) as $line) {
            if ($limit != -1 && $limit < ++$i ) {
                break; 
            }
            $info = explode($sep, $line, 2);
            if (count($info) != 2) {
                continue;
            }
            list($first, $second) = $info; 

            $key          = $findex ? $first : $second;
            $return[$key] = $findex ? $second : $first;
        }
        return $return;
    }
    // }}}

    // {{{ getTreeDiff 
    /**
     *  Get diff between two directories tree. A directory tree can
     *  be a commit or two directories.
     *
     *  @param string $tree1   Tree Id.
     *  @param string $tree2Id Tree Id.
     *  @param string $prefix  Directory prefix, to append to the name.
     *
     *  @return array Diff.
     */
    function getTreeDiff($tree1,$tree2Id=null,$prefix='')
    {
        $tree1 = $this->getObject($tree1);
        if ($tree2Id == null) {
            $tree2 = array();
        } else {
            $tree2 = $this->getObject($tree2Id);
        }

        $new = $changed = $del = array();
        foreach ($tree1 as $key => $desc) {
            $name = $prefix.$key;
            if ( isset($tree2[$key]) ) {
                $file2 = & $tree2[$key];
                if ($tree2[$key]->id != $desc->id) {
                    if ($desc->is_dir) {
                        $diff = $this->getTreeDiff($desc->id, $file2->id, $key.'/');

                        list($c1, $n1, $d1) = $diff;

                        $changed = array_merge($changed, $c1);
                        $new     = array_merge($new, $n1);
                        $del     = array_merge($del, $d1);
                    } else {
                        $changed[] = array($name, $tree2[$key]->id, $desc->id);
                    }
                } 
            } else {
                if ($desc->is_dir) {
                        $diff = $this->getTreeDiff($desc->id, null, $key.'/');

                        list($c1, $n1, $d1) = $diff;

                        $changed = array_merge($changed, $c1);
                        $new     = array_merge($new, $n1);
                        $del     = array_merge($del, $d1);
                } else {
                    $new[] = array($name, $desc->id);
                }
            }
        }
        if ($tree2Id != null) { 
            foreach ($tree2 as $key => $desc) {
                if (!isset($tree1[$key])) {
                    $del[] = array($prefix.$key, $desc->id.'/');
                }
            }
        }
        return array($changed, $new ,$del);
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
