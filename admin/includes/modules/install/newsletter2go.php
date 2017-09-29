<?php

class Newsletter2Go
{
    public $output;
    public $title;

    public function Newsletter2Go()
    {
        $this->code = 'newsletter2go';
        $this->version = '4.0.00';
        $this->title = 'Newsletter2Go';
        $this->description = 'API for Newsletter2Go';
        $this->enabled = ((MODULE_CSEO_NEWSLETTER2GO_STATUS == 'true') ? true : false);
        $this->sort_order = MODULE_CSEO_NEWSLETTER2GO_SORT_ORDER;

        $this->output = array();
    }

    function process()
    {
        global $order, $xtPrice;
    }

    function check()
    {
        if (!isset($this->_check)) {
            $check_query = xtc_db_query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_CSEO_NEWSLETTER2GO_STATUS'");
            $this->_check = xtc_db_num_rows($check_query);
        }

        return $this->_check;
    }

    function keys()
    {
        return array('MODULE_CSEO_NEWSLETTER2GO_STATUS', 'MODULE_CSEO_NEWSLETTER2GO_SORT_ORDER');
    }

    function install()
    {
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value,configuration_group_id, sort_order, set_function, date_added) VALUES ('MODULE_CSEO_NEWSLETTER2GO_STATUS', 'true', '6', '1','', now())");
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value,configuration_group_id, sort_order, date_added) VALUES ('MODULE_CSEO_NEWSLETTER2GO_SORT_ORDER', '1','6', '2', now())");
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value,configuration_group_id, sort_order, date_added) VALUES ('MODULE_CSEO_NEWSLETTER2GO', 'true','6', '3', now())");
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value,configuration_group_id, sort_order, date_added) VALUES ('MODULE_CSEO_NEWSLETTER2GO_VERSION', '$this->version','6', '3', now())");

        if (column_exists('customers', 'n2go_apiKey') == false && column_exists('customers', 'n2go_api_enabled') == false) {
            xtc_db_query("ALTER TABLE customers ADD COLUMN `n2go_apikey` VARCHAR(100) NULL AFTER `login_time`, "
                    . "ADD COLUMN `n2go_api_enabled` TINYINT(1) DEFAULT 0  NOT NULL AFTER `n2go_apikey`;");
        }
        
        if (column_exists('admin_access', 'newsletter2go') == false) {
            xtc_db_query("ALTER TABLE admin_access ADD newsletter2go INT( 1 ) NOT NULL DEFAULT 0;");
            xtc_db_query("UPDATE admin_access SET newsletter2go = '1' WHERE module_newsletter <> 0;");
        }
        
        $languages = xtc_db_query('SELECT languages_id AS id FROM languages;');
        $n = xtc_db_num_rows($languages);
        for ($i = 0; $i < $n; $i++) {
            $lang = xtc_db_fetch_array($languages);
            xtc_db_query("INSERT INTO admin_navigation (name, title, subsite, filename, languages_id) 
                            VALUES
                        ('newsletter2go', 'Newsletter2Go', 'customers', 'newsletter2go.php', {$lang['id']});");
        }
    }

    function remove()
    {
        xtc_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key in ('" . implode("', '", $this->keys()) . "')");
        xtc_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_CSEO_NEWSLETTER2GO'");
        xtc_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_CSEO_NEWSLETTER2GO_VERSION'");
        xtc_db_query("DELETE FROM admin_navigation WHERE name = 'newsletter2go'");
        xtc_db_query("ALTER TABLE `customers` DROP COLUMN `n2go_apikey`, DROP COLUMN `n2go_api_enabled`;");
        xtc_db_query("ALTER TABLE `admin_access` DROP COLUMN `newsletter2go`;");
    }
}
