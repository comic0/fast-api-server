<?php

require_once BASEPATH."/system/models.php";
require_once BASEPATH."/app/app.php";

class FastApiCore
{
    private $mTable;
    private $mInput;
    private $mCount;
    private $mModel;

    public function __construct()
    {
        $this->_initDB();
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

    private function index()
    {
        $this->_json_result("Your API is ready to use :)");
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

    private function _prepare_input()
    {
        $this->mInput = $this->_prepare_input_item($this->mTable, $this->mInput);
    }

    private function _get_where()
    {
        $where = array();

        foreach ( $_GET as $key=>$value )
        {
            if( substr($key, 0, 6)=='where_' )
            {
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
                        $data[] = $this->_prepare_input_item($key, $item);
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
        header("Content-type: application/json");

        if( isset($_GET['first']) && is_array($data) )
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

        echo json_encode($result);
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
            $query = explode('/', trim($_GET['query'], '/'));
            $method = $_SERVER['REQUEST_METHOD'];

            if( count($query)>0 )
            {
                $table = array_shift($query);

                if( method_exists($this, $table."_".$method) )
                {
                    call_user_func_array(array($this, $table."_".$method), $query);
                }
                else
                {
                    call_user_func_array(array($this, '_execute'), array($method, $table, $query));
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

        return $query;
    }

    private function _includeSubQueries( &$result )
    {
        if( isset($_GET['full']) )
        {
            $model = $this->mModel->getTable($this->mTable);

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

        if( isset($_GET['include']) )
        {
            $tables = explode(",", $_GET['include']);

            foreach ( $result as $i=>$row )
            {
                foreach ( $tables as $table )
                {
                    $model = $this->mModel->getTable($table);

                    if( isset($model->constraints[$this->mTable]) )
                    {
                        $where = $model->constraints[$this->mTable];
                        $query = QB::table($model->name);
                        foreach( $where as $dstField=>$srcField )
                        {
                            $query = $query->where($dstField, $row->$srcField);
                        }

                        $objects = $query->get();
                        foreach ( $objects as $j=>$object )
                        {
                            $objects[$j]->__type = $model->name;
                        }

                        $result[$i]->{$model->name} = $objects;
                    }

                }
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

    private function _execute($method, $table, $data=array() )
    {
        try {

            $this->mTable = $table;
            $this->_prepare_input();

            $result = null;
            $query = QB::table($table);

            switch ( $method )
            {
                case 'GET' : {

                    $returnOne = false;

                    switch (count($data))
                    {
                        case 0 : {

                            $query = $this->_prepareQuery($query)->select('*');

                            $where = $this->_get_where();
                            foreach ( $where as $field=>$value )
                            {
                                $query = $query->where($field, $value);
                            }

                            $this->mCount = $query->count();
                            $result = $query->get();
                            break;
                        }

                        case 1 : {

                            if( is_numeric($data[0]) )
                            {
                                $returnOne = true;
                                $query = $query->where('id', $data[0]);
                                $this->mCount = $query->count();
                                $result = $this->_prepareQuery($query)->get();
                                break;
                            }

                            break;
                        }

                        case 2 : {

                            $query = $query->where($data[0], $data[1]);
                            $this->mCount = $query->count();
                            $result = $this->_prepareQuery($query)->get();
                            break;
                        }
                    }

                    $this->_includeSubQueries($result);

                    foreach( $result as $i=>$row )
                    {
                        $result[$i]->__type = $table;
                    }

                    if( $returnOne )
                    {
                        $result = count($result)>0? $result[0] : null;
                    }

                    break;
                }

                case 'PUT' : {

                    $object = $this->mInput;
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

                    $insertId = $query->insert($object);
                    $result = $this->_prepareQuery($query)->find($insertId);

                    foreach( $batch as $table=>$rows )
                    {
                        $model = $this->mModel->getTable($table);

                        if( isset($model->constraints[$this->mTable]) )
                        {
                            foreach ( $model->constraints[$this->mTable] as $srcField=>$dstField )
                            {
                                foreach ( $rows as $i=>$row )
                                {
                                    $row[$srcField] = $insertId;
                                    $rows[$i] = $row;
                                }
                            }
                        }

                        QB::table($table)->insert($rows);
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

                case 'OPTION' : {

                    echo 'ok';
                    die();
                    break;
                }
            }

            $this->_json_result($result);

        } catch ( Exception $e ) {

            $this->_json_error($e->getMessage());
        }
    }
}