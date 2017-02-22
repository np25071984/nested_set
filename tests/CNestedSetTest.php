<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\DbUnit\TestCaseTrait;
use ghopper\CNestedSet;
use ghopper\NestedSetException;

require_once(__DIR__.'/../vendor/autoload.php');

class CNestedSetTest extends TestCase
{
    use TestCaseTrait;

    /**
     * This is the object that will be tested
     * @var DataPump
     */
    protected $object;

    /**
     * only instantiate pdo once for test clean-up/fixture load
     * @var PDO
     */
    protected $pdo = NULL;

    /**
     * only instantiate PHPUnit_Extensions_Database_DB_IDatabaseConnection once per test
     * @var type 
     */
    private $conn = NULL;

    public function __construct($name = NULL, array $data = array(), $dataName = '') {
        parent::__construct($name, $data, $dataName);
    
        $this->pdo =  new PDO(
            'mysql:host=localhost;dbname=test;charset=utf8',
            'root',
            ''
        );
        $this->pdo->exec('SET foreign_key_checks = 0');  //отключим проверку внешних ключей
        $this->pdo->exec('DROP TABLE IF EXISTS ns_tree');
        $this->pdo->exec(<<<EOD
            CREATE TABLE ns_tree (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lft INT NOT NULL,
                rgt INT NOT NULL,
                -- you can add whatever you want such as 'name','description','link' etc
                name VARCHAR(20) NOT NULL,
                link VARCHAR(20) NOT NULL
            )
EOD
        );
        $this->pdo->exec("
            INSERT INTO ns_tree (lft,rgt,name,link)
            VALUES(1,2,'root','root_link')"
        );
        $this->conn = $this->createDefaultDBConnection($this->pdo, 'test');
    }

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp() {
        $aConf = array(
            'tb_extra_fields'=> array(
                'name',
                'link'
            )
        );
        $this->object = new CNestedSet($this->getConnection()->getConnection(), $aConf);
    }    

    protected function getConnection() {
        return $this->conn;
    }

    protected function getDataSet() {
        return $this->createXMLDataSet('tests/db.xml');
    }
    
    public function testClassesAutoload() {
        $this->assertEquals(TRUE, class_exists('ghopper\CNestedSet'));
        $this->assertEquals(TRUE, class_exists('ghopper\NestedSetException'));
    }

    public function testCheckDataBaseInitState() {
        $query = $this->getConnection()->getConnection()->query('SELECT name FROM ns_tree');
        $results = $query->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEquals(1, count($results));
        $this->assertEquals($results[0], 'root');
    }
    
    public function testAddNode() {
        $dog_id = $this->object->addChild(1, array(
                'name' => 'dog',
                'link' => 'dog_link'
            )
        );
        
        $query = $this->getConnection()->getConnection()->query('SELECT name FROM ns_tree');
        $result = $query->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEquals(2, count($result));
        $this->assertEquals($result[0], 'root');
        $this->assertEquals($result[1], 'dog');

        $query = $this->getConnection()
            ->getConnection()
            ->query(sprintf('SELECT lft FROM ns_tree WHERE id=%d', $dog_id));
        $result = $query->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEquals($result[0], 2);

        $query = $this->getConnection()
            ->getConnection()
            ->query(sprintf('SELECT rgt FROM ns_tree WHERE id=%d', $dog_id));
        $result = $query->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEquals($result[0], 3);

        $query = $this->getConnection()
            ->getConnection()
            ->query('SELECT lft FROM ns_tree WHERE id=1');
        $result = $query->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEquals($result[0], 1);

        $query = $this->getConnection()
            ->getConnection()
            ->query('SELECT rgt FROM ns_tree WHERE id=1');
        $result = $query->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEquals($result[0], 4);
    }
    
    public function testAddNodeWithNonexistentParent() {
        $this->expectException(NestedSetException::class);
        $this->object->addChild(3, array());
        $this->object->addChild('a', array());
        $this->object->addChild(-1, array());
    }

}

?>