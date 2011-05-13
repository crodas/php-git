<?php
/**
 *  GitHttpClone
 *
 *  Simple example showing how to implement a Git clone over http
 *  by extending the GitHttpBase class. In this example it uses
 *  the http class http://www.phpclasses.org/browse/package/3.html
 *
 *  In order to use it, just download the http.php file from package
 *  mentioned above.
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   César D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 */
final class GitHttpClone extends GitClone
{
    private $_http;

    /**
     *  Initialize the http module.
     *
     *  @return nothing
     */
    protected function initMod()
    {
        $http = & $this->_http;
        $http = new http_class;

        $http->timeout     = 30;
        $http->user_agent  = "PHPGit/1.0 (http://cesar.la/projects/phpgit)";
        $http->prefer_curl = 0;
        $http->follow_redirect = 1;
    }

    /**
     *  get a remote file
     *
     *  If it has some problem, it must return an exception,
     *
     *  @param string $file File's URL to get
     *
     *  @return string file contents.
     */
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

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: sw=4 ts=4 fdm=marker
 * vim<600: sw=4 ts=4
 */
?>
