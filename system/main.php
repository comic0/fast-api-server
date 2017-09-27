<?php

require_once BASEPATH."/system/models.php";
require_once BASEPATH."/system/push.php";
require_once BASEPATH."/app/app.php";

class FastApiCore
{
    private $mPush;
    private $mApiKey;
    private $mRequestPath;
    private $mTable;
    private $mCount;
    private $mModel;
    private $mSettings;
    private $mSortField;
    private $mSort;
    private $currentUserTable;

    protected $currentUser;
    protected $mInput;

    public function __construct()
    {
        $this->mSortField = "created_at";
        $this->mSort = "asc";

        $this->_initDB();
        $this->_readHeaders();
        $this->_readParams();
        $this->_executeRoute();
    }

    public function callback_password( $value )
    {
        return base64_encode(hash("sha256", $value));
    }

    public function callback_email( $value )
    {
        if( !filter_var($value, FILTER_VALIDATE_EMAIL) )
        {
            $this->error("Email bad format");
        }

        return $value;
    }

    public function callback_phone( $value ){

        if( !empty($value) )
        {
            $locale = Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);;

            if( isset($this->currentUser) && isset($this->currentUser->locale) && !empty($this->currentUser->locale) )
            {
                $locale = $this->currentUser->locale;
            }

            $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();

            try {
                $number = $phoneUtil->parse($value, substr($locale, -2));

                if( $phoneUtil->isValidNumber($number) )
                {
                    return $phoneUtil->format($number, \libphonenumber\PhoneNumberFormat::E164);
                }

                $this->error("Incorrect phone number");

            } catch (\libphonenumber\NumberParseException $e) {
                $this->error("Incorrect phone number");
            }
        }

        return $value;
    }

    private function index()
    {
        $this->_json_result("Your API is ready to use :)");
    }

    private function upload_post()
    {
        $dir = BASEPATH."/uploads";
        if( !file_exists($dir) )
        {
            mkdir($dir, 0777);
        }

        if( isset($_FILES['file']) )
        {
            $name = $_FILES['file']['name'];
            $ext = substr($name, strrpos($name, "."));
            $uploadName = date("YmdHis_").md5(time().rand(0,100)).$ext;

            if( move_uploaded_file($_FILES['file']['tmp_name'], $dir.'/'.$uploadName) )
            {
                return array(
                    "filename"=>$uploadName,
                    "url" => $this->base_url("uploads/".$uploadName)
                );
            }
            else
            {
                $this->error("Error during file upload");
            }
        }

        $this->error("No file provided");
    }

    private function push_post()
    {
        $data = json_decode(file_get_contents('php://input'));

        if( isset($data->src) && isset($data->title) && isset($data->message) )
        {
            $src = explode(":", $data->src);
            $table = $src[0];
            $query = QB::table($table);
            $field = 'token';

            if( count($src)>1 )
            {
                $field = $src[1];
            }

            if( isset($data->where) )
            {
                $where = (array) $data->where;

                foreach( $where as $key=>$value )
                {
                    if( is_array($value) )
                    {
                        if( count($value)>0 )
                        {
                            $query = $query->whereIn($key, $value);
                        }
                    }
                    else
                    {
                        $query = $query->where($key, $value);
                    }
                }
            }

            //$model = $this->mModel->getTable($table);
            //print_r($model);

            $query = $query->groupBy($field);
            $results = $query->get();

            $tokens = [];

            foreach( $results as $result )
            {
                $tokens[] = $result->$field;
            }

            return $this->mPush->sendMessage( $tokens, $data->title, $data->message );
        }
    }

    protected function base_url( $path='' )
    {
        $url = "http" . (($_SERVER['SERVER_PORT'] == 443) ? "s://" : "://") . $_SERVER['HTTP_HOST'] . '/'. $path;

        return $url;
    }

    protected function select( $table, $where=array() )
    {
        $query = QB::table($table);

        foreach ( $where as $field=>$value )
        {
            if( substr($field, 0, 3)!="not" )
            {
                if( is_array($value) )
                {
                    if( count($value)>0 )
                    {
                        $query = $query->whereIn($field, $value);
                    }
                }
                else if( is_null($value) )
                {
                    $query = $query->whereNull($field);
                }
                else
                {
                    $query = $query->where($field, $value);
                }
            }
            else
            {
                $field = substr($field, 4);

                if( is_array($value) )
                {
                    if( count($value)>0 )
                    {
                        $query = $query->whereNotIn($field, $value);
                    }
                }
                else if( is_null($value) )
                {
                    $query = $query->whereNotNull($field);
                }
                else
                {
                    $query = $query->whereNot($field, $value);
                }
            }
        }

        return $query;
    }

    protected function insert( $table, $data )
    {
        $query = QB::table($table);

        if( $this->_isNumericArray($data) )
        {
            foreach ( $data as $key=>$row )
            {
                $data[$key]["created_at"] = date("Y-m-d H:i:s");
            }
        }
        else
        {
            $data["created_at"] = date("Y-m-d H:i:s");
        }

        return $query->insert($data);
    }

    protected function error( $message )
    {
        $this->_json_error($message);
    }

    private function _readParams()
    {
        $data = file_get_contents('php://input');

        if( !empty($data) )
        {
            $this->mInput = json_decode($data, true);
        }
        else
        {
            $this->mInput = [];
        }
    }

    private function _getHeader( $name )
    {
        $headers = getallheaders();

        if( isset($headers[$name]) )
        {
            return $headers[$name];
        }

        if( isset($headers[strtolower($name)]) )
        {
            return $headers[strtolower($name)];
        }

        return false;
    }

    private function _readHeaders()
    {
        $this->mRequestPath = trim($_GET['query'], '/');

        if( !empty($query) )
        {
            if( $apiKey = $this->_getHeader('X-Fast-Api-Key') )
            {
                if( $apiKey!=$this->mApiKey )
                {
                    $this->error("Wrong api key");
                }
            }
            else
            {
                $this->error("No api key provided");
            }
        }

        if( ($loggedId = $this->_getHeader('X-Api-Logged-Id')) && ($loggedType = $this->_getHeader('X-Api-Logged-Type')) )
        {
            $this->currentUserTable = $loggedType;
            $this->currentUser = QB::table($this->currentUserTable)->find(intval($loggedId));
        }
    }

    private function _prepare_input( $table )
    {
        if( $this->_isNumericArray($this->mInput) )
        {
            $rows = array();
            foreach ( $this->mInput as $row )
            {
                $rows[] = $this->_prepare_input_item($table, $row);
            }
            $this->mInput = $rows;
        }
        else
        {
            $this->mInput = $this->_prepare_input_item($table, $this->mInput);
        }
    }

    private function _get_where()
    {
        $where = array();

        foreach ( $_GET as $key=>$value )
        {
            if( substr($key, 0, 6)=='where_' )
            {
                $json = json_decode($value);

                if( json_last_error()==JSON_ERROR_NONE )
                {
                    $value = $json;
                }

                $where[substr($key, 6)] = $value;
            }
        }

        return $this->_prepare_input_item($this->mTable, $where);
    }

    private function _prepare_field_value( $name, $value )
    {
        if( method_exists($this, 'callback_'.$name) )
        {
            $value = call_user_func_array(array($this, 'callback_'.$name), array($value));
        }

        return $value;
    }

    private function _prepare_input_item( $table, $fields )
    {
        $result = array();
        $table = $this->mModel->getTable($table);

        if( !is_null($table) )
        {
            foreach( $fields as $key=>$value )
            {
                if( $this->_isNumericArray($value) )
                {
                    $data = array();
                    foreach ( $value as $item )
                    {
                        if( is_array($item) )
                        {
                            $data[] = $this->_prepare_input_item($key, $item);
                        }
                        else
                        {
                            $field = $table->getField($key);
                            $data[] = $this->_prepare_field_value($field->Comment, $item);
                        }
                    }
                    $result[$key] = $data;
                }
                elseif( is_array($value) )
                {
                    $result[$key] = $this->_prepare_input_item($key, $value);
                }
                else
                {
                    $field = $table->getField($key);
                    $result[$key] = $this->_prepare_field_value($field->Comment, $value);
                }
            }
        }

        return $result;
    }

    private function _json_result( $data )
    {

        $returnOne = in_array('first', $this->mSettings) || in_array('last', $this->mSettings);;

        if( $returnOne && is_array($data) )
        {
            $data = $data[0];
        }

        $result = [
            "success" => true,
            "result" => $data
        ];

        if( !is_null($this->mCount) )
        {
            $result["num_rows"] = $this->mCount;
        }

        $data = json_encode($result);

        header("Content-type: application/json");
        header("Content-length: ".strlen($data));

        echo $data;
        die();
    }

    private function _json_error( $message )
    {
        header("Content-type: application/json");

        echo json_encode([
            "error" => true,
            "message" => $message,
            "last_query" => $_SESSION['last_query']
        ]);
        die();
    }

    private function _initDB()
    {
        if( file_exists(BASEPATH."/app/config/database.php") )
        {
            require_once BASEPATH."/app/config/database.php";

            new \Pixie\Connection('mysql', $database, 'QB');
            $this->mModel = new Models($database['database']);

            require_once BASEPATH . "/app/config/appkeys.php";
            $this->mApiKey = $apikey;

            if( isset($pushkey) && !empty($pushkey) )
            {
                $this->mPush = new Push( $pushkey );
            }
        }
        else
        {
            header("Location: /install.php");
        }
    }

    private function _executeRoute()
    {
        if( isset($_GET['query']) )
        {
            $query = explode('/', $this->mRequestPath);
            $method = $_SERVER['REQUEST_METHOD'];

            if( $method=="OPTIONS" )
            {
                $this->_json_result('ok');
            }

            if( count($query)>0 )
            {
                $settings = explode(':', array_shift($query));
                $table = array_shift($settings);

                $this->mSettings = $settings;
                $this->_prepare_input($table);

                if( method_exists($this, $table."_".$method) )
                {
                    try {

                        $result = call_user_func_array(array($this, $table."_".$method), $query);
                        $this->_json_result($result);

                    } catch ( Exception $exception ){

                        $this->error($exception->getMessage());
                    }
                }
                else
                {
                    $this->mTable = $table;
                    $result = call_user_func_array(array($this, '_execute'), array($method, $table, $query, $this->_get_where()));
                    $this->_json_result($result);
                }
            }
        }
        else
        {
            $this->index();
        }
    }

    private function _prepareQuery( $query )
    {
        if( isset($_GET['limit']) )
        {
            $query = $query->limit(intval($_GET['limit']));
        }

        if( isset($_GET['offset']) )
        {
            $query = $query->offset(intval($_GET['offset']));
        }

        if( isset($_GET['orderBy']) )
        {
            $this->mSortField = $_GET['orderBy'];
        }

        if( isset($_GET['order']) )
        {
            $order = strtoupper($_GET['order']);

            if( in_array($order, ['ASC', 'DESC']) )
            {
                $this->mSort = $order;
            }
        }

        if( in_array('last', $this->mSettings) )
        {
            $query = $query->orderBy($this->mSortField, $this->mSort=='ASC'? 'DESC' : 'ASC');
        }
        else
        {
            $query = $query->orderBy($this->mSortField, $this->mSort);
        }

        return $query;
    }

    private function _fillObjects( $objectTable , &$result )
    {
        $model = $this->mModel->getTable($objectTable);

        foreach ( $model->constraints as $table=>$where )
        {
            foreach ( $result as $i=>$row )
            {
                foreach( $where as $dstField=>$srcField )
                {
                    $query = QB::table($table);
                    $query = $query->where($srcField, $row->$dstField);
                    $fieldName = trim(trim($dstField, "id"), "_");

                    $object = $query->first();
                    $object->__type = $table;
                    $result[$i]->$fieldName = $object;
                }
            }
        }
    }

    private function _addChildren( &$result, $table, $data, $full=false)
    {

        $includes = explode(":", $data);
        $query = array_shift($includes);

        if (preg_match("#([a-z0-9]+)\[(.*)\]#i", $query, $matches))
        {
            $childrenTable = $matches[1];
            $linkField = $matches[2];
        }
        else
        {
            $childrenTable = $query;
            $linkField = null;
        }

        $model = $this->mModel->getTable($childrenTable);

        if( isset($model->constraints[$table]) )
        {
            foreach ( $result as $i=>$row)
            {
                $query = QB::table($model->name);

                if( empty($linkField) )
                {
                    $where = $model->constraints[$table];

                    foreach( $where as $dstField=>$srcField )
                    {
                        $query = $query->where($dstField, $row->$srcField);
                    }
                }
                else
                {
                    $where = $model->constraints[$table];
                    $fields = explode("|", $linkField);

                    foreach( $where as $dstField=>$srcField )
                    {
                        if( in_array($dstField, $fields) )
                        {
                            $query = $query->orWhere($dstField, $row->$srcField);
                        }
                    }
                }

                $objects = $query->get();

                foreach( $includes as $include )
                {
                    $this->_addChildren($objects, $childrenTable, $include);
                }

                if( $full )
                {
                    $this->_fillObjects($childrenTable, $objects);
                }

                foreach ( $objects as $j=>$object )
                {
                    $objects[$j]->__type = $model->name;
                }

                $result[$i]->{$model->name} = $objects;
            }
        }
    }

    private function _includeSubQueries( &$result, $currentTable=null )
    {
        if( is_null($currentTable) )
            $currentTable = $this->mTable;

        $neededFullObjects = isset($_GET['full']) || in_array('full', $this->mSettings);

        if( $neededFullObjects )
        {
            $this->_fillObjects($currentTable, $result);
        }

        if( isset($_GET['include']) )
        {
            $tables = explode(",", $_GET['include']);

            foreach ( $tables as $data )
            {
                $this->_addChildren($result, $currentTable, $data, $neededFullObjects);
            }
        }
    }

    private function _isNumericArray($array)
    {
        if( !is_array($array) )
            return false;

        foreach ( array_keys($array) as $a )
        {
            if (!is_int($a))
            {
                return false;
            }
        }
        return true;
    }

    private function _execute($method, $table, $data=array(), $where=array() )
    {
        try {

            $result = null;
            $query = QB::table($table);

            switch ( $method )
            {
                case 'GET' : {

                    switch (count($data))
                    {
                        case 0 : {

                            $query = $this->_prepareQuery($this->select($table, $where));
                            $this->mCount = $query->count();
                            $result = $query->get();
                            break;
                        }

                        case 1 : {

                            if( is_numeric($data[0]) )
                            {
                                $returnOne = true;

                                $query = $this->_prepareQuery($this->select($table, ["id"=>$data[0]]));
                                $this->mCount = $query->count();
                                $result = $query->get();
                                break;
                            }

                            break;
                        }

                        case 2 : {

                            $query = $this->_prepareQuery($this->select($table, [$data[0]=>$data[1]]));
                            $this->mCount = $query->count();
                            $result = $query->get();
                            break;
                        }
                    }

                    foreach( $result as $i=>$row )
                    {
                        $result[$i]->__type = $table;
                    }

                    $this->_includeSubQueries($result, $table);

                    break;
                }

                case 'PUT' : {

                    $input = $this->mInput;
                    $returnOne = !$this->_isNumericArray($input);
                    $objects = $returnOne? [$input] : $input;
                    $result = array();

                    foreach( $objects as $object )
                    {
                        $batch = array();

                        foreach( $object as $key=>$item )
                        {
                            if( is_array($item) )
                            {
                                if( $this->_isNumericArray($item) )
                                    $batch[$key] = $item;
                                unset($object[$key]);
                            }
                        }

                        $insertId = $this->insert($table, $object);
                        $insertObject = $this->select($table, ['id'=>$insertId])->first();

                        if( $insertObject )
                        {
                            foreach( $batch as $child=>$rows )
                            {
                                $model = $this->mModel->getTable($child);

                                if( isset($model->constraints[$table]) )
                                {
                                    foreach ( $model->constraints[$table] as $srcField=>$dstField )
                                    {
                                        foreach ( $rows as $i=>$row )
                                        {
                                            $row[$srcField] = $insertId;
                                            $rows[$i] = $row;
                                        }
                                    }
                                }

                                $this->insert($child, $rows);
                            }

                            $result[] = $insertObject;
                        }

                        foreach( $result as $i=>$row )
                        {
                            $result[$i]->__type = $table;
                        }

                        if( $returnOne )
                            $result = $result[0];
                    }
                    break;
                }

                case 'POST' : {

                    $object = $this->mInput;

                    foreach( $object as $key=>$item )
                    {
                        if( is_array($item) )
                        {
                            unset($object[$key]);
                        }
                    }

                    $object['updated_at'] = date("Y-m-d H:i:s");

                    $field = null;
                    $value = null;

                    switch ( count($data) )
                    {
                        case 1 : {

                            $field = 'id';
                            $value = $data[0];
                            break;
                        }

                        case 2 : {

                            $field = $data[0];
                            $value = $data[1];
                            break;
                        }

                        default : {

                            break;
                        }
                    }

                    if( !is_null($field) && !is_null($value) )
                    {
                        $query->where($field, $value)->update($object);
                    }
                    else
                    {
                        $query->update($object);
                    }

                    break;
                }

                case 'DELETE' : {

                    $field = null;
                    $value = null;

                    switch ( count($data) )
                    {
                        case 1 : {

                            $field = 'id';
                            $value = $data[0];
                            break;
                        }

                        case 2 : {

                            $field = $data[0];
                            $value = $data[1];
                            break;
                        }

                        default : {

                            break;
                        }
                    }

                    if( !is_null($field) && !is_null($value) )
                    {
                        $query->where($field, $value)->delete();
                    }

                    break;
                }
            }

            return $result;

        } catch ( Exception $e ) {

            $this->_json_error($e->getMessage());
        }
    }
}