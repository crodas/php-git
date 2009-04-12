<?php
include("git.php");


$repo = new Git("/home/crodas/projects/playground/phpserver/phplibtextcat/.git");
//$repo = new Git("/home/crodas/projects/bigfs/.git");
var_dump($repo->getBranches());
$history = $repo->getHistory('devel');
var_dump($history);
die();
$commit = $repo->getCommit('b22d9c85cd28af4a4c8059614521cb42d94ade49');
//$commit = $repo->getCommit('fb12298bd8eac7f368d435b1256047d09d4773ef');
var_dump($commit);

$object = $repo->getObject('d7ca87cc92e7007b831f449e0afd9ff92c33dc83');
var_dump($object);

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: sw=4 ts=4 fdm=marker
 * vim<600: sw=4 ts=4
 */
?>
