<?php

require_once dirname(__FILE__)."/git.php";

abstract class Git_Graphic extends Git
{
    protected $height = 700;
    protected $width  = 230;

    function doGraphic() {
        $history = array();
        foreach($this->branch as $branch => $id) {
            $history[$branch] = $this->getHistory($branch);
        }
        $this->modInit();
    }

    protected function setImageSize($width,$height) {
        $this->width  = $width;
        $this->height = $height;
    }

    abstract function modInit();
    abstract function addBranchName();
}

require("contrib/pChart.1.27/pChart/pData.class");
require("contrib/pChart.1.27/pChart/pChart.class");


final class Git_Graphic_pChart extends Git_Graphic
{
    private $_pchart;
    private $_data;
    private $_height;
    private $_width;

    function modInit() {
        $this->_data   = new pData;
        $this->_pchart = new pChart($this->height,$this->width);
    }

    function addBranchName() {
    }

}


$git_graph = new Git_Graphic_pChart(".git");
$git_graph->doGraphic();

?>
