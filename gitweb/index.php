<?php
/**
 *  PHP-Git Example
 *
 *  PHP version 5
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   César D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 */


define("GIT_DIR", "/home/crodas/projects/playground/phpgit/.git");
#define("GIT_DIR", "/home/crodas/projects/git/.git");
#define("GIT_DIR","/home/crodas/projects/bigfs/.git/");

require "phpgit/git.php";

try {
    $git = new Git(GIT_DIR);
} catch(Exception $e) {
    die(GIT_DIR." is not a valid git directory");
}

/* commit file list */
if (isset($_GET['commit'])) {
    $commit = $_GET['commit'];
    $commit = $git->getCommit($commit); 
    $file_list = & $commit['Tree'];
} else if (isset($_GET['file'])) {
    /* it is a file */
    $object = $git->getFile($_GET['file'], $type);
    if ($type == OBJ_TREE) {
        $file_list = & $object;
    } else {
        $content = & $object;
    }
} else if (isset($_GET['diff'])) {
    include("phpgit/contrib/diff.php");
    $diff    = $git->getCommitDiff($_GET['diff']);
    $changes = $diff[0];
    foreach($changes as $change) {
        $obj1 = $git->getFile($change[0]);
        $obj2 = $git->getFile($change[1]);
        $str1 = explode("\n",$obj1);
        $str2 = explode("\n",$obj2);
        $diff = phpdiff($obj1,$obj2);
        die("<pre>$diff</pre>");
    }
    var_dump($changes);
    die();
}

if (isset($_GET['tag'])) {
    $tag = $git->getTag($_GET['tag']);
    $file_list = & $tag['Tree'];
}

if (isset($_GET['history'])) {
    $history = $git->getHistory($_GET['history'],200);
}

/* it is a branch  */
if (!isset($content) && !isset($history) && !isset($file_list) && !isset($_GET['branch'])) {
    $_GET['branch'] = 'master';
}
if (isset($_GET['branch'])) {
    try {
        $history = $git->getHistory($_GET['branch'], 1);
    } catch(Exception $e) {
        $history = $git->getHistory('master', 1);
    }
    $commit    = $git->getCommit($history[0]["id"]);
    $file_list = $commit['Tree']; 
    unset($commit, $history);
}


?>
<html>
<head>
    <title>Example - a fast and ugly Git view</title>
    <script src="prettify.js" type="text/javascript"></script>
    <link rel="stylesheet" href="prettify.css" 
    type="text/css" media="screen" />
</head>
<body>
<table>
<tr>
    <th>Branches</th>
    <th>Tags</th>
</tr>
<tr>
    <td>
    <ul>
<?php 
foreach ($git->getBranches() as $branch):
?>
    <li><a href="?branch=<?php echo $branch?>"><?php echo $branch?></a> | <a href="?history=<?php echo $branch?>">history</a> </li>
<?php
endforeach;
?>
    </ul>
    </td>
    <td>
    <ul>
<?php 
foreach ($git->getTags() as $id => $tag):
?>
    <li><a href="?tag=<?php echo $id?>"><?php echo $tag?></a></li>
<?php
endforeach;
?>
    </ul>
    </td>
</tr>
</table>


<?php 
if (isset($history)) :
?>
<table>
<tr>
    <th>Author</th>
    <th>Commit ID</th>
    <th>Comment</th>
    <th>Date</th>
</tr>
<?php
foreach($history as $commit):
?>
<tr>
    <td><?php echo $commit['author']?></td>
    <td><a href="?commit=<?php echo $commit['id']?>"><?php echo $commit['id']?></a></td>
    <td><?php echo $commit['comment']?></td>
    <td><?php echo $commit['time']?></td>
</tr>
<?php
endforeach;
?>
</table>
<?php 
endif;
?>

<?php 
if (isset($file_list)) :
?>
<table>
<tr>
    <th>Permission</th>
    <th>Filename</th>
</tr>
<?php
foreach($file_list as $file):
?>
<tr>
    <td></td>
    <td><a href="?file=<?php echo $file->id?>"><?php echo $file->name?><?php echo $file->is_dir ? "/" : "" ?></a></td>
</tr>
<?php
endforeach;
?>
</table>
<?php 
endif;
?>


<?php
if (isset($content)) :
?>
<pre class="prettyprint">
<?php echo htmlentities($content);?>
</pre>
<script>prettyPrint();</script>

<?php
endif;
?>

</body>
</html>
