<?php

$database = array(
    'driver'    => 'mysql', // Db driver
    'host'      => '<host>',
    'database'  => '<base>',
    'username'  => '<user>',
    'password'  => '<pass>',
    'charset'   => 'utf8', // Optional
    'collation' => 'utf8_unicode_ci', // Optional
    'prefix'    => '', // Table prefix, optional
    'options'   => array( // PDO constructor options, optional
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_EMULATE_PREPARES => false,
    ),
);