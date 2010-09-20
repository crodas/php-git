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

require "../phpgit/git.php";
require "../phpgit/http.php";


$phplibtextcat = new GitHttpClone;
$phplibtextcat->setRepoURL("http://github.com/crodas/php-git.git");
$phplibtextcat->setRepoPath("php-git");
try {
    $phplibtextcat->doClone();
} catch(Exception $e) {
    echo "Alright!, you found a bug, please report it ".$e->getMessage();
        
}
?>
