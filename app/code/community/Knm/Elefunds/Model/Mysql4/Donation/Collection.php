<?php

class Knm_Elefunds_Model_Mysql4_Donation_Collection extends Mage_Core_Model_Mysql4_Collection_Abstract
{
    public function _construct()
    {
        $this->_init('elefunds/donation'); 
    }
}