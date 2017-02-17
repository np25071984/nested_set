<?php
use ghopper\CNestedSet;
use ghopper\NestedSetException;

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once('../vendor/autoload.php');

function printTree($tree) {
    $result = '';
    $currDepth = -1;  // -1 to get the outer <ul>
    while (!empty($tree)) {
      $currNode = array_shift($tree);
      // Level down?
      if ($currNode['depth'] > $currDepth) {
        // Yes, open <ul>
        $result .= '<ul>';
      }
      // Level up?
      if ($currNode['depth'] < $currDepth) {
        // Yes, close n open <ul>
        $result .= str_repeat('</ul>', $currDepth - $currNode['depth']);
      }
      // Always add node
      $result .= '<li>' . $currNode['name'] . '</li>';
      // Adjust current depth
      $currDepth = $currNode['depth'];
      // Are we finished?
      if (empty($tree)) {
        // Yes, close n open <ul>
        $result .= str_repeat('</ul>', $currDepth + 1);
      }
    }
    
    print $result."<hr />";
}

// header("Content-Type: text/plain");
echo "Nested sets class testing<br />";

$dsn = 'mysql:host=localhost;dbname=test;charset=utf8';
try {
    $pdo = new PDO(
        $dsn,
        'root',
        '',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (\Exception $ex) {
  echo "Unable to connect: " . $ex->getMessage();
}

$q = <<<EOD
DROP TABLE IF EXISTS ns_tree;
EOD;
$pdo->query($q);

$q = <<<EOD
CREATE TABLE ns_tree (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lft INT NOT NULL,
    rgt INT NOT NULL,
    -- you can add whatever you want such as 'name','description','link' etc
    name VARCHAR(20) NOT NULL,
    link VARCHAR(20) NOT NULL
);
EOD;
$pdo->query($q);

$q = <<<EOD
INSERT INTO ns_tree (lft,rgt,name,link) VALUES(1,2,'root','root_link');
EOD;
$pdo->query($q);

$aConf = array(
    'tb_extra_fields'=> array(
        'name',
        'link'
    )
);

try {
    $ns = new CNestedSet($pdo, $aConf);

    echo "<br />Initial tree:";
    printTree($ns->getTree(1));
    echo "Add some nodes:";
    // id=2
    $ns->addChild(1, array(
        'name' => 'chicken without link'
    ));
    // id=3
    $dog_id = $ns->addChild(1, array(
        'name' => 'dog',
        'link' => 'dog_link'
    ));
    // id=4
    $ns->addChild(1, array(
        'name' => 'cat',
        'link' => 'cat_link'
    ));
    // id=5
    $cat1_id = $ns->addChild(4, array(
        'name' => 'cat#1',
        'link' => 'cat#1_link'
    ));
    // id=6
    $ns->addChild(5, array(
        'name' => 'cat#2',
        'link' => 'cat#2_link'
    ));
    // id=7
    $ns->addChild(6, array(
        'name' => 'cat#3',
        'link' => 'cat#3_link'
    ));
    // id=8
    $ns->addChild(7, array(
        'name' => 'cat#4',
        'link' => 'cat#4_link'
    ));
    // id=9
    $ns->addChild(3, array(
        'name' => 'white',
        'link' => 'white_link'
    ));
    // id=10
    $ns->addChild(3, array(
        'name' => 'black',
        'link' => 'black_link'
    ));
    // id=11
    $ns->addChild(1, array(
        'name' => 'cow',
        'link' => 'cow_link'
    ));
    // id=12
    $ns->addChild(11, array(
        'name' => 'milk',
        'link' => 'milk_link'
    ));
    // id=13
    $ns->addChild(11, array(
        'name' => 'chease',
        'link' => 'chease_link'
    ));
    // id=14
    $ns->addChild(11, array(
        'name' => 'cream',
        'link' => 'cream_link'
    ));
    printTree($ns->getTree(1));

    echo "Delete 'dog' node:";
    $ns->deleteNode($dog_id);
    printTree($ns->getTree(1));

    echo "Delete 'cat#1' tree:";
    $ns->deleteTree($cat1_id);
    printTree($ns->getTree(1));
} catch (NestedSetException $ex) {
    echo "NestedSetException!<br />";
    var_dump($ex);
}

?>