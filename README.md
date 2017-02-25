# nested_set
Tiny class which provides basic functions for manipulation with "Nested Set" database tree.

## Preparing
Create the database table
```
DROP TABLE IF EXISTS ns_tree;
CREATE TABLE ns_tree (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lft INT NOT NULL,
    rgt INT NOT NULL,
    -- you can add whatever you want with text type
    -- such as 'name','description','link' etc
    name VARCHAR(255),
    link VARCHAR(255)
);
INSERT INTO ns_tree (lft,rgt,name,link) VALUES(1,2,'root','root_link');
```
Setup PDO-connection. The class sets PDO attribute PDO::ATTR_ERRMODE equal to PDO::ERRMODE_EXCEPTION if it isn't set yet
```
...
$pdo = new \PDO(DB_CONN_STRING, DB_USER, DB_PASS,
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);

```

## Configuring
```
$aConfig = array(
    'tb_name' => 'ns_tree',
    'tb_field_index'=> 'id',
    'tb_field_left' => 'lft',
    'tb_field_right' => 'rgt',
    'tb_extra_fields'=> array(
        'name',
        'link'
    )
);
```

## Usage

### create the object
```
$ns = new CNestedSet($pdo, $aConfig);
```

### add new node
Pass array with extra fields' values
```
$aNewNode = array(
    'name' => 'new node name',
    'link' => 'new node link'
);
$nd1 = $ns->addChild(1, $aNewNode); 
```
### print the tree
Returns array with 'depth' values.
```
print_r($ns->getTree($nd1), TRUE);
```

Look at the *examples* folder for more information.

## Available methods
 * getTree($parent_id) - return all descendants of $parent_id node
 * addChild($parent_id, $values) - add a new node to $parent_id which contains $values
 * addRootChild($values) - add new node to the root element
 * moveTree($cur_parent_id, $new_parent_id) - move the whole tree to a new parent
 * deleteTree($node_id) - delete $node_id with all descendants
 * deleteNode($node_id) - delete $node_id only and shift the descendants to level up

## Requirements
 * database has to transactions support
 * database has to locks support
