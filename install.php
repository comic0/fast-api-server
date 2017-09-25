<?php

define("BASEPATH", dirname(__FILE__));

require_once BASEPATH."/vendor/autoload.php";

$error = false;

if( isset($_POST['host']) )
{
    $apikey = substr(str_shuffle(str_repeat("azertyuiopqsdfghjklmwxcvbn0123456789", 10)),-32);

    $database = array(
        'driver'    => 'mysql', // Db driver
        'host'      => $_POST['host'],
        'database'  => $_POST['base'],
        'username'  => $_POST['user'],
        'password'  => $_POST['pass'],
        'charset'   => 'utf8', // Optional
        'collation' => 'utf8_unicode_ci', // Optional
        'prefix'    => '', // Table prefix, optional
        'options'   => array( // PDO constructor options, optional
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
        ),
    );

    try {

        new \Pixie\Connection('mysql', $database, 'QB');

        $databaseFile = file_get_contents(BASEPATH."/app/config/database.sample.php");

        foreach( $_POST as $key=>$value )
        {
            $databaseFile = str_replace('<'.$key.'>', $value, $databaseFile);
        }

        file_put_contents(BASEPATH."/app/config/database.php", $databaseFile);
        file_put_contents(BASEPATH."/app/config/appkeys.php", "<?php\n\$apikey = \"$apikey\";");
        header("Location: /");
        die();

    } catch ( Exception $exception ){

        $error = $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
    <head>
        <title>Fast & Furious API Configuration</title>

        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta/css/bootstrap.min.css" integrity="sha384-/Y6pD6FV/Vv2HJnA6t+vslU6fwYXjCFtcEpHbNJ0lyAFsXTsjBbfaDjzALeQsN6M" crossorigin="anonymous">

    </head>
    <body>

        <div class="container py-2">
            <div class="card card-body">
                <h2>Fast & Furious API Configuration</h2>
                <hr>
                <?php if( $error ): ?>
                <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                <form action="<?= $_SERVER['REQUEST_URI'] ?>" method="post" >
                    <fieldset class="form-group">
                        <input type="text" name="host" placeholder="Database host" class="form-control">
                    </fieldset>
                    <fieldset class="form-group">
                        <input type="text" name="user" placeholder="Database username" class="form-control">
                    </fieldset>
                    <fieldset class="form-group">
                        <input type="password" name="pass" placeholder="Database password" class="form-control">
                    </fieldset>
                    <fieldset class="form-group">
                        <input type="text" name="base" placeholder="Database basename" class="form-control">
                    </fieldset>

                    <hr>
                    <div class="text-right">
                        <input type="submit" class="btn btn-primary" value="Démarrer" />
                    </div>
                </form>
            </div>
        </div>
    </body>
</html>