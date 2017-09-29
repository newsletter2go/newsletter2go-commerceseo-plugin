<?php
require ('includes/application_top.php');

$id = (isset($_GET['id']) ? xtc_db_prepare_input($_GET['id']) : '');
$action = (isset($_GET['action']) ? xtc_db_prepare_input($_GET['action']) : '');

if (xtc_not_null($id) && xtc_not_null($action)) {
    $user = xtc_db_fetch_array(xtc_db_query('SELECT * FROM customers WHERE customers_status=0 AND customers_id=' . $id));
    switch ($action) {
        case 'Enable':
            if (!$user['n2go_apikey']) {
                $apikey = md5(time() . $id . $user['customers_password']);
                $query = xtc_db_query("UPDATE customers SET n2go_api_enabled=1, n2go_apikey='$apikey' WHERE customers_id=$id");
            } else if (!$user['n2go_api_enabled']) {
                xtc_db_query('UPDATE customers SET n2go_api_enabled=1 WHERE customers_id=' . $id);
            }

            break;
        case 'Disable':
            xtc_db_query('UPDATE customers SET n2go_api_enabled=0 WHERE customers_id=' . $id);
            break;
        case 'Generate':
            $apikey = md5(time() . $id . $user['customers_password']);
            if (!$user['n2go_apikey']) {
                $query = xtc_db_query("UPDATE customers SET n2go_api_enabled=1, n2go_apikey='$apikey' WHERE customers_id=$id");
            } else {
                xtc_db_query("UPDATE customers SET n2go_apikey='$apikey' WHERE customers_id=$id");
            }

            break;
    }
}

$usersQuery = xtc_db_query('SELECT * FROM customers WHERE customers_status = 0');
$n = xtc_db_num_rows($usersQuery);
$users = array();
$selected = 0;
for ($i = 0; $i < $n; $i++) {
    $users[$i] = xtc_db_fetch_array($usersQuery);
    if (xtc_not_null($id) && $users[$i] == $id) {
        $selected = $i;
    }
}
$i = 0;
$table = TABLE_CONFIGURATION;

$query = "SELECT * FROM $table WHERE configuration_key = 'MODULE_CSEO_NEWSLETTER2GO_VERSION'";
$versionQuery = xtc_db_query($query);
$version = xtc_db_fetch_array($versionQuery);

$queryParams['version'] = str_replace('.', '', $version['configuration_value']);
$queryParams['language'] = MagnaDB::gi()->fetchOne("SELECT code FROM ".TABLE_LANGUAGES." WHERE directory = '$_magnaLanguage'");
$queryParams['url'] = HTTP_SERVER . DIR_WS_CATALOG;

foreach ($users as $key => $user) {
    if ($user['n2go_api_enabled'] === '1') {
        $queryParams['apiKey'] = $user['n2go_apikey'];
        $users[$key]['connectUrl'] = 'https://ui-staging.newsletter2go.com/integrations/connect/CSE/' . '?' . http_build_query($queryParams);
    }
}

require(DIR_WS_INCLUDES . 'header.php');
?>

<table class="outerTable" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <table class="table_pageHeading" border="0" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                    <td class="pageHeading"><?php echo HEADING_TITLE; ?></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td>
            <table border="0" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                    <td valign="top">
                        <table width="100%" class="dataTable" cellspacing="0" cellpadding="0">
                            <tr class="dataTableHeadingRow">
                                <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_ID; ?></th>
                                <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_EMAIL; ?></th>
                                <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_APIKEY; ?></th>
                                <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_ENABLED; ?></th>
                            </tr>
                            <?php foreach ($users as $user): ?>
                                <?php
                                $codeToAdd = '';
                                if (isset($id) && $id == $user['customers_id']) {
                                    $codeToAdd = 'class="dataTableRowSelected"';
                                } else {
                                    $codeToAdd = 'class="' . (($i % 2 == 0) ? 'dataTableRow' : 'dataWhite') . '" onclick="document.location.href=\'' . xtc_href_link('newsletter2go.php', 'id=' . $user['customers_id']) . "'";
                                }
                                $i++;
                                ?>
                                <tr class="<?= $codeToAdd ?>">
                                    <td><?php echo $user['customers_id']; ?></td>
                                    <td><?php echo $user['customers_email_address']; ?></td>
                                    <td><?php echo $user['n2go_apikey']; ?></td>
                                    <td><?php echo $user['n2go_api_enabled'] ? '<img src="images/icons/icon_add.png" />' : '<img src="images/icons/exclamation.png" />'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                    <?php
                    if (xtc_not_null($id)) {
                        $heading = array();
                        $contents = array();
                        $heading[] = array('text' => '<b>' . $users[$selected]['customers_firstname'] . ' ' . $users[$selected]['customers_lastname'] . '</b>');
                        $str = ($users[$selected]['n2go_api_enabled'] ? BOX_BUTTON_DISABLE : BOX_BUTTON_ENABLE);
                        $connect = !empty($users[$selected]['connectUrl']) ? '<a class="button" href="' . $users[$selected]['connectUrl'] . '" target="_blank">' . BOX_BUTTON_CONNECT. '</a>' : '';
                        $contents[] = array(
                                'align' => 'center',
                                'text' => $connect . '<a class="button" onClick="this.blur();" href="' . xtc_href_link('newsletter2go.php', 'id=' . $id . '&action=' . ($users[$selected]['n2go_api_enabled'] ? 'Disable' : 'Enable')) . '">' . $str . '</a>' .
                                    '<a class="button" onClick="this.blur();" href="' . xtc_href_link('newsletter2go.php', 'id=' . $id . '&action=Generate') . '">' . BOX_BUTTON_GENERATE . '</a>'
                        );
                        if (xtc_not_null($heading) && xtc_not_null($contents)) {
                            echo '            <td width="25%" valign="top">' . "\n";
                            $box = new box;
                            echo $box->infoBox($heading, $contents);
                            echo '            </td>' . "\n";
                        }
                    }
                    ?>
                </tr>
            </table>
        </td>
    </tr>
</table>
<?php
require(DIR_WS_INCLUDES . 'footer.php');
require(DIR_WS_INCLUDES . 'application_bottom.php');



