<?php

require_once 'includes/application_top.php';
require_once dirname(__FILE__) . '/Nl2go_ResponseHelper.php';

class N2GoApi
{
    private $apikey;

    private $connected = false;

    private $userId;

    /**
     * Asociative array with get parameters
     * @var array
     */
    private $getParams;

    /**
     * Asociative array with post parameters
     * @var array
     */
    private $postParams;

    public function __construct($action, $apikey, $getParams = array(), $postParams = array())
    {
        header('Content-Type: application/json');
        $this->responseHelper = new Nl2go_ResponseHelper();
        if (xtc_not_null($apikey)) {
            $this->apikey = $apikey;
            $this->getParams = $getParams;
            $this->postParams = $postParams;
            $this->connected = $this->checkApiKey();

            try {
                if (!$this->connected['success']) {
                    echo $this->responseHelper->generateErrorResponse($this->connected['message'], Nl2go_ResponseHelper::ERRNO_PLUGIN_CREDENTIALS_WRONG);
                } else {
                    switch ($action) {
                        case 'getCustomers':
                            echo $this->responseHelper->generateSuccessResponse($this->getCustomers());
                            break;
                        case 'getCustomerFields':
                            $fields = $this->getCustomerFields();
                            echo $this->responseHelper->generateSuccessResponse(array('fields' => $fields));
                            break;
                        case 'getCustomerGroups':
                            echo $this->responseHelper->generateSuccessResponse($this->getCustomerGroups());
                            break;
                        case 'getCustomerCount':
                            echo $this->responseHelper->generateSuccessResponse($this->getCustomerCount());
                            break;
                        case 'unsubscribeCustomer':
                            if ($this->unsubscribeCustomer(0)) {
                                echo $this->responseHelper->generateSuccessResponse();
                            } else {
                                echo $this->responseHelper->generateErrorResponse('Unsubscribe customer failed!', 'int-2-404');
                            }
                            break;
                        case 'subscribeCustomer':
                            if ($this->unsubscribeCustomer(1)) {
                                echo $this->responseHelper->generateSuccessResponse();
                            } else {
                                echo $this->responseHelper->generateErrorResponse('Subscribe customer failed!', 'int-2-404');
                            }
                            break;
                        case 'getProduct':
                            echo $this->responseHelper->generateSuccessResponse($this->getProduct());
                            break;
                        case 'getItemFields':
                            $fields = $this->itemFields();
                            echo $this->responseHelper->generateSuccessResponse(array('fields' => $fields));
                            break;
                        case 'testConnection':
                            echo $this->responseHelper->generateSuccessResponse();
                            break;
                        case 'getLanguages':
                            echo $this->responseHelper->generateSuccessResponse($this->getLanguages());
                            break;
                        case 'getPluginVersion':
                            echo $this->responseHelper->generateSuccessResponse($this->getPluginVersion());
                            break;
                        default:
                            echo $this->responseHelper->generateErrorResponse("Unknown action: $action", Nl2go_ResponseHelper::ERRNO_PLUGIN_OTHER);
                            break;
                    }
                }
            } catch (Exception $exc) {
                echo $this->responseHelper->generateErrorResponse($exc->getMessage(), Nl2go_ResponseHelper::ERRNO_PLUGIN_OTHER);
            }
        } else {
            echo $this->responseHelper->generateErrorResponse('Credential missing', Nl2go_ResponseHelper::ERRNO_PLUGIN_CREDENTIALS_MISSING);
        }
    }

    public function getCustomers()
    {
        $hours = (isset($this->postParams['hours']) ? xtc_db_prepare_input($this->postParams['hours']) : '');
        $subscribed = (isset($this->postParams['subscribed']) ? xtc_db_prepare_input($this->postParams['subscribed']) : '');
        $limit = (isset($this->postParams['limit']) ? xtc_db_prepare_input($this->postParams['limit']) : '');
        $offset = (isset($this->postParams['offset']) ? xtc_db_prepare_input($this->postParams['offset']) : '');
        $emails = (isset($this->postParams['emails']) ? xtc_db_prepare_input($this->postParams['emails']) : array());
        $group = (isset($this->postParams['group']) ? xtc_db_prepare_input($this->postParams['group']) : '');
        $fields = (isset($this->postParams['fields']) ? xtc_db_prepare_input($this->postParams['fields']) : array());
        $conditions = array();
        $customers = array();

        $query = $this->buildCustomersQuery($fields);
        $query .= ' FROM ' . TABLE_CUSTOMERS . ' cu
                    LEFT JOIN ' . TABLE_ADDRESS_BOOK . ' ab ON cu.customers_id = ab.customers_id
                    LEFT JOIN ' . TABLE_COUNTRIES . ' co ON ab.entry_country_id = co.countries_id
                    LEFT JOIN ' . TABLE_NEWSLETTER_RECIPIENTS . ' nr ON cu.customers_email_address = nr.customers_email_address';

        if (xtc_not_null($group)) {
            if ($group == 1) {
                return $this->getGuestSubscribers($subscribed, $fields, $limit, $offset, $emails);
            }

            $conditions[] = 'cu.customers_status = ' . $group;
        }

        if (xtc_not_null($hours)) {
            $time = date('Y-m-d H:i:s', time() - 3600 * $hours);
            $conditions[] = "cu.customers_last_modified >= '$time'";
        }

        if (xtc_not_null($subscribed) && $subscribed) {
            $conditions[] = 'nr.mail_status = ' . $subscribed;
        }

        if (!empty($emails)) {
            $conditions[] = "cu.customers_email_address IN ('" . implode("', '", $emails) . "')";
        }

        if (!empty($conditions)) {
            $query .= ' WHERE ' . implode(' AND ', $conditions);
        }

        if (xtc_not_null($limit)) {
            $offset = (xtc_not_null($offset) ? $offset : 0);
            $query .= ' LIMIT ' . $offset . ', ' . $limit;
        }

        // var_dump($fields); die;
        $customersQuery = xtc_db_query($query);
        $n = xtc_db_num_rows($customersQuery);
        for ($i = 0; $i < $n; $i++) {
            $cs = xtc_db_fetch_array($customersQuery);
            foreach ($cs as $key => $value) {
                $cs[$key] = utf8_encode($value);
            }

            $customers[] = $cs;
        }

        return array('customers' => $customers);
    }

    /**
     * @param string $subscribed
     * @param array $fields
     * @param string $limit
     * @param string $offset
     * @param array $emails
     * @return string
     */
    public function getGuestSubscribers($subscribed = '', $fields = array(), $limit = '', $offset = '', $emails = array())
    {
        $map = array(
            'nr.mail_status'             => 'mail_status',
            'cu.customers_email_address' => 'customers_email_address',
            'cu.customers_date_added'    => 'date_added',
        );
        $conditions = array('customers_status = 1');
        $customers = array();
        $query = $this->buildCustomersQuery($fields, $map) . ' FROM ' . TABLE_NEWSLETTER_RECIPIENTS;

        if (xtc_not_null($subscribed) && $subscribed) {
            $conditions[] = 'mail_status = ' . $subscribed;
        }

        if (!empty($emails)) {
            $conditions[] = "customers_email_address IN ('" . implode("', '", $emails) . "')";
        }

        if (!empty($conditions)) {
            $query .= ' WHERE ' . implode(' AND ', $conditions);
        }

        if (xtc_not_null($limit)) {
            $offset = (xtc_not_null($offset) ? $offset : 0);
            $query .= ' LIMIT ' . $offset . ', ' . $limit;
        }

        $customersQuery = xtc_db_query($query);
        $n = xtc_db_num_rows($customersQuery);
        for ($i = 0; $i < $n; $i++) {
            $customers[] = xtc_db_fetch_array($customersQuery);
        }

        return array('customers' => $customers);
    }

    /**
     * Returns json encode customer groups with names in shops default language
     * @return string
     */
    public function getCustomerGroups()
    {
        $groups = array();
        $table = TABLE_CUSTOMERS_STATUS;
        $query = "SELECT customers_status_id as id,
                         customers_status_name as name,
                         '' as description
                  FROM $table
                  WHERE language_id IN (
                       SELECT l.languages_id
                       FROM configuration c
                            LEFT JOIN languages l ON l.code = c.configuration_value
                       WHERE configuration_key = 'DEFAULT_LANGUAGE'
                   )";
        $groupsQuery = xtc_db_query($query);
        $n = xtc_db_num_rows($groupsQuery);
        for ($i = 0; $i < $n; $i++) {
            $groups[] = xtc_db_fetch_array($groupsQuery);
        }

        return array('groups' => $groups);
    }

    /**
     * Returns customer fields array
     * @return array
     */
    public function getCustomerFields()
    {
        $fields = array();
        $fields['cu.customers_id'] = $this->createField('cu.customers_id', 'Customer Id.', 'Integer');
        $fields['cu.customers_gender'] = $this->createField('cu.customers_gender', 'Gender');
        $fields['cu.customers_firstname'] = $this->createField('cu.customers_firstname', 'First name');
        $fields['cu.customers_lastname'] = $this->createField('cu.customers_lastname', 'First name');
        $fields['cu.customers_dob'] = $this->createField('cu.customers_dob', 'Date of birth');
        $fields['cu.customers_email_address'] = $this->createField('cu.customers_email_address', 'E-mail address');
        $fields['cu.customers_telephone'] = $this->createField('cu.customers_telephone', 'Phone number');
        $fields['cu.customers_fax'] = $this->createField('cu.customers_fax', 'Fax');
        $fields['cu.customers_date_added'] = $this->createField('cu.customers_date_added', 'Date created');
        $fields['cu.customers_last_modified'] = $this->createField('cu.customers_last_modified', 'Date last modified');
        $fields['cu.customers_warning'] = $this->createField('cu.customers_warning', 'Warning message');
        $fields['cu.customers_status'] = $this->createField('cu.customers_status', 'Customer group Id.');
        $fields['cu.payment_unallowed'] = $this->createField('cu.payment_unallowed', 'Payment unallowed');
        $fields['cu.shipping_unallowed'] = $this->createField('cu.shipping_unallowed', 'Shipping unallowed');
        $fields['nr.mail_status'] = $this->createField('nr.mail_status', 'Subscribed', 'Boolean');
        $fields['ab.entry_company'] = $this->createField('ab.entry_company', 'Company');
        $fields['ab.entry_street_address'] = $this->createField('ab.entry_street_address', 'Street');
        $fields['ab.entry_city'] = $this->createField('ab.entry_city', 'City');
        $fields['co.countries_name'] = $this->createField('co.countries_name', 'Country');

        return $fields;
    }

    /**
     * Returns json encode customer count based on group and subscribed parameters
     * @return string
     */
    public function getCustomerCount()
    {
        $group = (isset($this->postParams['group']) ? xtc_db_prepare_input($this->postParams['group']) : '');
        $subscribed = (isset($this->postParams['subscribed']) ? xtc_db_prepare_input($this->postParams['subscribed']) : '');
        $conditions = array();
        $query = 'SELECT COUNT(*) AS total FROM customers c';
        if (xtc_not_null($group)) {
            $conditions[] = 'c.customers_status = ' . $group;
        }

        if (xtc_not_null($subscribed) && $subscribed) {
            $query .= ' LEFT JOIN newsletter_recipients n ON n.customers_email_address = c.customers_email_address ';
            $conditions[] = 'n.mail_status = 1';
        }

        $where = empty($conditions) ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $query = $query . $where;
        $countQuery = xtc_db_query($query);
        $result = xtc_db_fetch_array($countQuery);
        $total = $result['total'];

        // Every guest that subscribes will have customer group id 1
        if (!xtc_not_null($group) || $group == 1) {
            $query = 'SELECT COUNT(*) AS total FROM newsletter_recipients WHERE customers_status = 1 AND customers_id = 0';
            $countQuery = xtc_db_query($query);
            $result = xtc_db_fetch_array($countQuery);
            $total += $result['total'];
        }

        return array('customers' => $total);
    }

    public function unsubscribeCustomer($status = 0)
    {
        $result = 0;
        $table = TABLE_NEWSLETTER_RECIPIENTS;
        $email = (isset($this->postParams['email']) ? xtc_db_prepare_input($this->postParams['email']) : '');
        if (xtc_not_null($email)) {
            xtc_db_query("UPDATE $table SET mail_status = $status WHERE customers_email_address = '$email'");
            $result = mysql_affected_rows();
        }

        return $result;
    }

    public function getProduct()
    {
        $id = isset($this->postParams['id']) ? xtc_db_prepare_input($this->postParams['id']) : '';
        $lang = isset($this->postParams['lang']) ? xtc_db_prepare_input($this->postParams['lang']) : '';
        $fields = isset($this->postParams['fields']) ? xtc_db_prepare_input($this->postParams['fields']) : array();

        if (empty($lang)) {
            $langQuery = xtc_db_query('SELECT configuration_value FROM ' . TABLE_CONFIGURATION . ' WHERE configuration_key = "DEFAULT_LANGUAGE"');
            $langResult = xtc_db_fetch_array($langQuery);
            $lang = $langResult['configuration_value'];
        }

        if (!xtc_not_null($id) || !xtc_not_null($lang)) {
            return array('success' => false, 'message' => 'Invalid or missing parameters for getProduct request!');
        }

        $query = $this->buildItemQuery($fields);
        $query .= ' FROM ' . TABLE_PRODUCTS . ' p
                LEFT JOIN ' . TABLE_TAX_RATES . ' tr ON p.products_tax_class_id = tr.tax_class_id
                LEFT JOIN ' . TABLE_PRODUCTS_DESCRIPTION . ' pd ON p.products_id = pd.products_id
                LEFT JOIN ' . TABLE_LANGUAGES . ' ln ON pd.language_id = ln.languages_id
                LEFT JOIN ' . TABLE_SHIPPING_STATUS . ' s ON s.shipping_status_id = p.products_g_shipping_status AND ln.languages_id = s.language_id'
            . " WHERE p.products_id = $id AND ln.code = '$lang'
            GROUP BY p.products_id";

        $productsQuery = xtc_db_query($query);
        $product = xtc_db_fetch_array($productsQuery);
        if ($product['p.products_id'] == $id) {
            $url = HTTP_SERVER . DIR_WS_CATALOG;
            if (array_key_exists('tr.tax_rate', $product) && $product['tr.tax_rate']) {
                if (array_key_exists('oldPrice', $product)) {
                    $product['oldPrice'] = round($product['oldPrice'] * (1 + $product['tr.tax_rate'] * 0.01), 2);
                }
                if (array_key_exists('newPrice', $product)) {
                    $product['newPrice'] = round($product['newPrice'] * (1 + $product['tr.tax_rate'] * 0.01), 2);
                }
                if (in_array('tr.tax_rate', $fields) || empty($fields)) {
                    $product['tr.tax_rate'] = round($product['tr.tax_rate'] * 0.01, 2);
                } else {
                    unset($product['tr.tax_rate']);
                }
            }

            if (array_key_exists('oldPriceNet', $product)) {
                $product['oldPriceNet'] = round($product['oldPriceNet'], 2);
            }
            if (array_key_exists('newPriceNet', $product)) {
                $product['newPriceNet'] = round($product['newPriceNet'], 2);
            }

            if (array_key_exists('images', $product)) {
                $images = array();
                if ($product['images']) {
                    $images[] = $url . DIR_WS_ORIGINAL_IMAGES . $product['images'];
                }

                $query = 'SELECT image_name FROM ' . TABLE_PRODUCTS_IMAGES . ' WHERE products_id = ' . $id;
                $imagesQuery = xtc_db_query($query);
                $n = xtc_db_num_rows($imagesQuery);
                for ($i = 0; $i < $n; $i++) {
                    $image = xtc_db_fetch_array($imagesQuery);
                    $images[] = $url . DIR_WS_ORIGINAL_IMAGES . $image['image_name'];
                }

                $product['images'] = $images;
            }

            if (array_key_exists('url', $product)) {
                $product['url'] = $url;
            }

            $response = array('product' => $product);
        } else {
            $response = array(
                'success' => false,
                'message' => 'Product with given parameters not found.',
                'product' => null,
            );
        }

        return $response;
    }

    /**
     * Returns customer fields array
     * @return array
     */
    public function itemFields()
    {
        $fields = array();
        $fields['p.products_id'] = $this->createField('p.product_id', 'Product Id.', 'Integer');
        $fields['pd.products_name'] = $this->createField('pd.products_name', 'Product name', 'String');
        $fields['pd.products_short_description'] = $this->createField('pd.products_short_description', 'Product short description', 'String');
        $fields['pd.products_description'] = $this->createField('pd.products_description', 'Product description', 'String');
        $fields['pd.products_meta_title'] = $this->createField('pd.products_meta_title', 'Product meta title', 'String');
        $fields['pd.products_meta_description'] = $this->createField('pd.products_meta_description', 'Product meta description', 'String');
        $fields['pd.products_meta_keywords'] = $this->createField('pd.products_meta_keyword', 'Product meta keyword', 'String');
        $fields['p.products_model'] = $this->createField('p.products_model', 'Product model.', 'String');
        $fields['p.products_upc'] = $this->createField('p.products_upc', 'UPC.', 'String', 'Universal Product Code');
        $fields['p.products_ean'] = $this->createField('p.products_ean', 'EAN.', 'String', 'European Article Number');
        $fields['p.products_isbn'] = $this->createField('p.products_isbn', 'ISBN.', 'String', 'International Standard Book Number');
        $fields['p.products_quantity'] = $this->createField('p.products_quantity', 'Quantity', 'String');
        $fields['p.products_g_availability'] = $this->createField('p.products_g_availability', 'Stock Status', 'String');
        $fields['tr.tax_rate'] = $this->createField('tr.tax_rate', 'VAT', 'String', 'Value Added Tax');
        $fields['p.products_brand_name'] = $this->createField('p.products_brand_name', 'Brand name', 'String');
        $fields['images'] = $this->createField('images', 'Product images', 'Array');
        $fields['s.shipping_status_name'] = $this->createField('s.shipping_status_name', 'Shipping status', 'String');
        $fields['p.products_minorder'] = $this->createField('p.products_minorder', 'Minimum order quantity', 'Integer');
        $fields['p.products_maxorder'] = $this->createField('p.products_maxorder', 'Maximum order quantity', 'Integer');
        $fields['p.products_date_available'] = $this->createField('p.products_date_available', 'Date available', 'Date');
        $fields['p.products_status'] = $this->createField('p.products_status', 'Product status', 'Boolean');
        $fields['pd.products_viewed'] = $this->createField('pd.products_viewed', 'Viewed', 'Integer');
        $fields['p.products_date_added'] = $this->createField('p.products_date_added', 'Date added', 'Date');
        $fields['p.products_last_modified'] = $this->createField('p.products_last_modified', 'Date modified', 'Date');
        $fields['oldPrice'] = $this->createField('oldPrice', 'Old price', 'Float');
        $fields['oldPriceNet'] = $this->createField('oldPriceNet', 'Old net price', 'Float');
        $fields['newPrice'] = $this->createField('newPrice', 'New price', 'Float');
        $fields['newPriceNet'] = $this->createField('newPriceNet', 'New net price', 'Float');
        $fields['url'] = $this->createField('url', 'Shop url', 'String');
        $fields['pd.url_text'] = $this->createField('pd.url_text', 'Product relative url', 'String');

        return $fields;
    }

    public function getPluginVersion()
    {
        $table = TABLE_CONFIGURATION;

        $query = "SELECT * FROM $table WHERE configuration_key = 'MODULE_CSEO_NEWSLETTER2GO_VERSION'";
        $versionQuery = xtc_db_query($query);
        $version = xtc_db_fetch_array($versionQuery);

        return array('version' => str_replace('.', '', $version['configuration_value']));
    }

    public function getLanguages()
    {
        $languages = array();

        $langQuery = xtc_db_query('SELECT * FROM ' . TABLE_LANGUAGES);
        $n = xtc_db_num_rows($langQuery);
        for ($i = 0; $i < $n; $i++) {
            $lang = xtc_db_fetch_array($langQuery);
            $languages[$lang['code']] = $lang['name'];
        }

        return array('languages' => $languages);
    }

    /**
     * Checks if there is an enabled user with given api key
     * @return array (
     *      'result'    =>   true|false,
     *      'message'   =>   result message,
     * )
     */
    private function checkApiKey()
    {
        $usersQuery = xtc_db_query("SELECT * FROM customers WHERE n2go_apikey = '$this->apikey' AND customers_status=0");
        $user = xtc_db_fetch_array($usersQuery);
        if (!empty($user)) {
            $this->userId = $user['customers_id'];

            return $user['n2go_api_enabled'] ? array('success' => true) :
                array('success' => false, 'message' => 'Your API key has been revoked! Contact your system administrator.');
        }

        return array('success' => false, 'message' => 'Invalid API key! Contact your system administrator.');
    }

    /**
     * Helper function to create field array
     * @param $id
     * @param $name
     * @param string $type
     * @param string $description
     * @return array
     */
    private function createField($id, $name, $type = 'String', $description = '')
    {
        return array('id' => $id, 'name' => $name, 'description' => $description, 'type' => $type);
    }

    /**
     * @param array $fields
     * @param array $fieldMap
     * @return string
     */
    private function buildCustomersQuery($fields = array(), $fieldMap = array())
    {
        $select = array();
        if (empty($fields)) {
            $fields = array_keys($this->getCustomerFields());
        } else if (!in_array('cu.customers_id', $fields)) {
            //customer Id must always be present
            $fields[] = 'cu.customers_id';
        }
        foreach ($fields as $field) {
            if (empty($fieldMap)) {
                $select[] = "$field AS '$field'";
            } else {
                $value = (array_key_exists($field, $fieldMap) ? $fieldMap[$field] : 'NULL');
                $select[] = "$value AS '$field'";
            }
        }

        return 'SELECT ' . implode(', ', $select);
    }

    /**
     * @param array $fields
     * @return string
     */
    private function buildItemQuery($fields)
    {
        $select = array();
        if (empty($fields)) {
            $fields = array_keys($this->itemFields());
        } else {
            if (!in_array('p.product_id', $fields)) {
                //item Id must always be present
                $fields[] = 'p.product_id';
            }
            if (!in_array('tr.tax_rate', $fields)) {
                $fields[] = 'tr.tax_rate';
            }
        }

        foreach ($fields as $field) {
            switch ($field) {
                case 'url':
                    $select[] = "NULL AS '$field'";
                    break;
                case 'oldPrice':
                case 'oldPriceNet':
                case 'newPrice':
                case 'newPriceNet':
                    $select[] = "p.products_price AS '$field'";
                    break;
                case 'images':
                    $select[] = 'p.products_image AS images';
                    break;
                case 'tr.tax_rate':
                    $select[] = 'MAX(tr.tax_rate) AS \'tr.tax_rate\'';
                    break;
                default:
                    $select[] = "$field AS '$field'";
                    break;
            }
        }

        return 'SELECT ' . implode(', ', $select);
    }

}

$apikey = (isset($_POST['apiKey']) ? $_POST['apiKey'] : '');
$action = (isset($_POST['action']) ? $_POST['action'] : '');

$api = new N2GoApi($action, $apikey, $_GET, $_POST);