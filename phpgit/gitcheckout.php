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

define("CE_NAMEMASK", 0x0fff);
define("CE_STAGEMASK", 0x3000);
define("CE_EXTENDED", 0x4000);
define("CE_VALID", 0x8000);
define("CE_STAGESHIFT", 12);

/** 
 *  Git Checkout
 *
 *  This abstract class can be use to do simple checkout with
 *  existent branches or tags.
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   César D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 *
 */
abstract class GitCheckout extends GitBase
{
    /**
     *  _checkoutTree
     *  
     *  This function put files from an $id tree into a $prefix
     *  folder, and store it into the $files array.
     *
     *  @param string $id     Tree id.
     *  @param string $prefix Where to unpack the files.
     *  @param array  &$files All unpacked files are stored here.
     *
     *  @return nothing
     */
    final private function _checkoutTree($id, $prefix,&$files)
    {
        $tree = $this->getObject($id, $type);

        foreach ($tree as $file) {
            if (!$file->is_dir) {
                $content          = $this->getObject($file->id);
                $files[$file->id] = $prefix.$file->name;

                file_put_contents($prefix.$file->name, $content);
                chmod($prefix.$file->name, $file->perm);
            } else {
                $dir = "$prefix{$file->name}/";
                @mkdir($dir);
                $this->_checkoutTree($file->id, $dir, $files);
            }
        }
    }

    /**
     *  Checkout
     *
     *  This function checkouts a given $commit id into $dir.
     *
     *  @param string $dir    Working directory
     *  @param string $commit Commit ID.
     *
     *  @return bool True if success or an exception.
     */
    final function checkout($dir, $commit)
    {
        $prevdir = getcwd();

        chdir($dir) or $this->throwException("Imposible to chdir");;
        
        $commit = $this->getObject($commit, $type);
        if ($type != OBJ_COMMIT) {
            return;
        }

        $this->_checkoutTree($commit['tree'], '', $files);

        $index  = "DIRC";
        $index .= pack("N*", 2, count($files));

        foreach ($files as $id => $file) {
            $stat  = stat($file);
            $finfo = pack("N*", $stat['ctime'], 0, $stat['mtime'],
                    0, $stat['dev'], $stat['ino'], $stat['mode'],
                    $stat['uid'], $stat['gid'], $stat['size']);
                
            $finfo .= $this->hexToSha1($id);
            $finfo .= pack("n", strlen($file) | (0 << 12));
            $finfo .= $file.chr(0);
            while (strlen($finfo) % 8 != 0) {
                $finfo .= chr(0);
            }
            $index .= $finfo;
        }

        $index .= $this->hexToSha1(sha1($index));
        file_put_contents(".git/index", $index);
        chdir($prevdir);
        return true;
    }

    /**
     *  getIndexInfo
     *  
     *  This function read and parse information of the
     *  cache info, the working dir.
     *
     *  @param string $filename Filename
     *
     *  @return array Array of files on the working dir
     *
     *  @experimental
     */
    function getIndexInfo($filename)
    {
        $text     = file_get_contents($filename);
        $file_sha = sha1(substr($text, 0, strlen($text)-20));
        if ($this->sha1ToHex(substr($text, -20)) != $file_sha) {
            $this->throwException("Index file corrupt");
        }
        if (substr($text, 0, 4) !== "DIRC") {
            $this->throwException("$filename is not a valid index file");
        }
        $info     = unpack("N*", substr($text, 4, 8));
        $version  = $info[1];
        $nrofiles = $info[2];

        if ($version != 2 && $version != 3) {
            return false;
        }

        $return = array();
        $offset = 12;
        $null   = chr(0);
        for ($i=0; $i < $nrofiles; $i++) {
            $start   = $offset;
            $info    = unpack("N*", substr($text, $offset, 40));
            $offset += 40;
            $sha1    = $this->sha1ToHex(substr($text, $offset, 20));
            $offset += 20;
            $flags   = unpack("n*", substr($text, $offset, 4));
            $offset += 2;
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

?>
