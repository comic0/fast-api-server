<?php

class Models
{
    private $mDatabaseName;
    private $mTables = array();

    public function __construct( $database )
    {
        $this->mDatabaseName = $database;
        $this->_initTables();
    }

    public function _initTables()
    {
        $query = QB::query("SHOW TABLES;");
        $fieldName = "Tables_in_".$this->mDatabaseName;
        $tables = (array)$query->get();

        foreach( $tables as $table )
        {
            $name = $table->$fieldName;

            $query = QB::query("SHOW FULL COLUMNS FROM $name;");
            $columns = $query->get();

            $query = QB::query("SHOW CREATE TABLE $name;");
            $result = (array)$query->first();
            $createQuery = $result["Create Table"];

            $object = new Table($name, $columns);

            if( preg_match_all("#CONSTRAINT `.*` FOREIGN KEY \(`(.+)`\) REFERENCES `(.+)` \(`(.+)`\)#i", $createQuery, $matches) )
            {
                foreach( $matches[0] as $i=>$match )
                {
                    $object->addConstraint($matches[1][$i], $matches[2][$i], $matches[3][$i]);
                }
            }

            $this->mTables[$name] = $object;
        }
    }

    public function getTables()
    {
        return $this->mTables;
    }

    public function getTable( $name )
    {
        if( isset($this->mTables[$name]) )
            return $this->mTables[$name];

        return null;
    }

    public function getLinkedTables( $name )
    {
        $tables = array();

        foreach( $this->mTables as $table )
        {
            foreach ( $table->constraints as $constraint )
            {
                if( isset($constraint[$name]) )
                {
                    $tables[] = $table;
                }
            }
        }

        return $tables;
    }
}

class Table
{
    public $name;
    public $columns;
    public $constraints;

    public function __construct( $name, $columns )
    {
        $this->name = $name;
        $this->columns = $columns;
        $this->constraints = array();

        $this->checkColumn("created_at", "DATETIME");
        $this->checkColumn("updated_at", "DATETIME");
        $this->initEvents();
    }

    private function columnExists( $name )
    {
        foreach( $this->columns as $column )
        {
            if( $column->Field==$name )
                return true;
        }
        return false;
    }

    private function checkColumn( $name, $type )
    {
        if( !$this->columnExists($name) )
        {
            QB::query("ALTER TABLE {$this->name} ADD $name $type;");
        }
    }

    private function initEvents()
    {
        /*
        QB::registerEvent('after-insert', $this->name, function($qb, $insertId)
        {

            $qb->table($this->name)->where('id', $insertId)->update(['created_at'=>date("Y-m-d H:i:s")]);
        });*/

        QB::registerEvent('before-*', $this->name, function ($qb)
        {
           $_SESSION['last_query'] = $qb->getQuery()->getRawSql();
        });
    }

    public function addConstraint( $field, $dstTable, $dstField )
    {
        if( !isset($this->constraints[$dstTable]) )
        {
            $this->constraints[$dstTable] = array();
        }

        $this->constraints[$dstTable][$field] = $dstField;
    }

    public function getField( $name )
    {
        foreach ( $this->columns as $column )
        {
            if( $column->Field==$name )
                return $column;
        }

        return null;
    }
}