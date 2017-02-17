# nested_set
Nested set tree management

## Preparing

Create the database table.

DROP TABLE IF EXISTS ns_tree;
CREATE TABLE ns_tree (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lft INT NOT NULL,
    rgt INT NOT NULL,
    -- you can add whatever you want such as 'name','description','link' etc
    name VARCHAR(20) NOT NULL,
    link VARCHAR(20) NOT NULL
);
INSERT INTO ns_tree (lft,rgt,name,link) VALUES(1,2,'root','root_link');

## Configuring

$aConig = array(
    'tb_name' => 'ns_tree',
    'tb_field_index'=> 'id',
    'tb_field_left' => 'lft',
    'tb_field_right' => 'rgt',
    'tb_extra_fields'=> array(
        'name',
        'link'
    )
);

## Usage

$ns = new CNestedSet($pdo, $aConig);

$nd1 = $ns->addChild(1, array(
    'name' => 'Node#1'
    'link' => 'link#1'
));
$ns->addChild($nd1, array(
    'name' => 'SubNode#1'
    'link' => 'sub_link#1'
));
...
printTree($ns->getTree($nd1));
...

Look at the examples folder for more information.
