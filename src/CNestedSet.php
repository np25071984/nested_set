<?php
namespace ghopper;

interface INestedSet
{
    /*
     * Get list of the parent's nodes
     *
     * @param int
     * @return array
     */
    function getTree($parent_id);

    /*
     * Add the first child node to the parent
     *
     * @param int $parent_id
     * @param array $values
     * @return array
     * @throw NestedSetException
     */
    function addChild($parent_id, $values);

    /*
     * Delete node with all descendants
     *
     * @param int $node_id
     * @return void
     * @throw NestedSetException
     */
    function deleteTree($node_id);

    /*
     * Delete specific node only
     *
     * @param int $node_id
     * @return void
     * @throw NestedSetException
     */
    function deleteNode($node_id);
}

class CNestedSet implements INestedSet
{
    private $config = array(
        'tb_name' => 'ns_tree',
        'tb_field_index'=> 'id',
        'tb_field_left' => 'lft',
        'tb_field_right' => 'rgt',
        'tb_extra_fields'=> array()
    );

    private $tbName;
    private $tbId;
    private $tblft;
    private $tbrgt;
    private $sFields;

    private $pdo;

    function __construct(\PDO $pdo, $config = []) {
        if ($pdo) {
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo = $pdo;
        }
        else
            throw new NestedSetException('You have to pass PDO connection!');

        $this->config = array_merge($this->config, $config);

        $this->tbName = $this->config['tb_name'];
        $this->tbId = $this->config['tb_field_index'];
        $this->tbLeft = $this->config['tb_field_left'];
        $this->tbRight = $this->config['tb_field_right'];

        if (count($this->config['tb_extra_fields']) > 0)
            $this->sFields =
                ', '.implode(', ', $this->config['tb_extra_fields']);
    }
    
    private function _isNodeExists($node_id) {
        $q = sprintf("
            SELECT COUNT(*) AS cnt
            FROM
                {$this->tbName}
            WHERE {$this->tbId}=%d",
            $node_id
        );

        if (empty($this->pdo->query($q)->fetchAll(\PDO::FETCH_ASSOC)[0]['cnt']))
            return FALSE;
        else
            return TRUE;
    }

    function getTree($parent_id) {
        if (!$this->_isNodeExists($parent_id))
            throw new NestedSetException('Node doesn\'t exist!');

        $q = sprintf("
            SELECT node.*, (COUNT(parent.{$this->tbId}) - 1) AS depth
            FROM
                {$this->tbName} AS node,
                {$this->tbName} AS parent
            WHERE node.{$this->tbLeft}
                BETWEEN parent.{$this->tbLeft} AND parent.{$this->tbRight}
            AND parent.{$this->tbLeft} >= (
                SELECT {$this->tbLeft} 
                FROM {$this->tbName} 
                WHERE {$this->tbId}=%d)
            GROUP BY node.{$this->tbId}
            ORDER BY node.{$this->tbLeft}",
            $parent_id
        );
        return $this->pdo->query($q)->fetchAll(\PDO::FETCH_ASSOC);
    }

    function addChild($parent_id, $values) {
        if (!$this->_isNodeExists($parent_id))
            throw new NestedSetException('Node doesn\'t exist!');

        $last_id = FALSE;
        
        $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, FALSE);

        $this->pdo->beginTransaction();
        $this->pdo->exec("LOCK TABLES {$this->tbName} WRITE");

        try {
            $aValues = array();

            foreach($this->config['tb_extra_fields'] AS $field) {
                if (array_key_exists($field, $values)) {
                    $aValues[$field] = "'{$values[$field]}'";
                }
                else
                    $aValues[$field] = "''";
            }

            $q = sprintf("
                SELECT {$this->tbLeft} AS lft
                FROM {$this->tbName}
                WHERE {$this->tbId}=%d",
                $parent_id
            );
            $iLeft = $this
                ->pdo
                ->query($q)
                ->fetchAll(\PDO::FETCH_ASSOC)[0]['lft'];
            
            $q = sprintf("
                UPDATE {$this->tbName} 
                SET {$this->tbRight} = {$this->tbRight} + 2 
                WHERE {$this->tbRight} > %d",
                $iLeft
            );
            $this->pdo->query($q);
    
            $q = sprintf("
                UPDATE {$this->tbName}
                SET {$this->tbLeft} = {$this->tbLeft} + 2
                WHERE {$this->tbLeft} > %d",
                $iLeft
            );
            $this->pdo->query($q);
            
            $q = sprintf("
                INSERT INTO {$this->tbName}
                    ({$this->tbLeft}, {$this->tbRight}{$this->sFields})
                VALUES (%2\$d + 1, %2\$d + 2%1\$s)",
                (count($aValues)>0) ? ', '.implode(", ",$aValues) : '',
                $iLeft
            );
            $this->pdo->query($q);
            $last_id = $this->pdo->lastInsertId();

            $this->pdo->commit();
        } catch (\PDOException $ex) {
            $this->pdo->rollBack();
            throw new NestedSetException($ex);
        } finally {
            $this->pdo->exec('UNLOCK TABLES');
        }
        
        $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, TRUE);
        
        return $last_id;
    }
    
    function deleteTree($node_id) {
        if (!$this->_isNodeExists($node_id))
            throw new NestedSetException('Node doesn\'t exist!');

        $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, FALSE);

        $this->pdo->beginTransaction();
        $this->pdo->exec("LOCK TABLES {$this->tbName} WRITE");

        try {
            $q = sprintf("
                SELECT
                    {$this->tbLeft} AS lft,
                    {$this->tbRight} AS rgt,
                    {$this->tbRight} - {$this->tbLeft} + 1 AS wdh
                FROM {$this->tbName}
                WHERE {$this->tbId}=%d",
                $node_id
            );
            extract($this->pdo->query($q)->fetchAll(\PDO::FETCH_ASSOC)[0]);
    
            $q = sprintf("
                DELETE FROM {$this->tbName}
                WHERE {$this->tbLeft}
                    BETWEEN %d AND %d",
                $lft,
                $rgt
            );
            $this->pdo->query($q);
    
            $q = sprintf("
                UPDATE {$this->tbName} 
                SET {$this->tbRight} = {$this->tbRight} - %d
                WHERE {$this->tbRight} > %d",
                $wdh,
                $rgt
            );
            $this->pdo->query($q);
    
            $q = sprintf("
                UPDATE {$this->tbName}
                SET {$this->tbLeft} = {$this->tbLeft} - %d
                WHERE {$this->tbLeft} > %d",
                $wdh,
                $rgt
            );
            $this->pdo->query($q);
        
            $this->pdo->commit();
        } catch (\PDOException $ex) {
            $this->pdo->rollBack();
            throw new NestedSetException($ex);
        } finally {
            $this->pdo->exec('UNLOCK TABLES');
        }
        
        $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, TRUE);
    }

    function deleteNode($node_id) {
        if (!$this->_isNodeExists($node_id))
            throw new NestedSetException('Node doesn\'t exist!');

        $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, FALSE);

        $this->pdo->beginTransaction();
        $this->pdo->exec("LOCK TABLES {$this->tbName} WRITE");

        try {
            $q = sprintf("
                SELECT
                    {$this->tbLeft} AS lft,
                    {$this->tbRight} AS rgt
                FROM {$this->tbName}
                WHERE {$this->tbId}=%d",
                $node_id
            );
            extract($this->pdo->query($q)->fetchAll(\PDO::FETCH_ASSOC)[0]);
    
            $q = sprintf("
                DELETE FROM {$this->tbName}
                WHERE {$this->tbId} = %d",
                $node_id
            );
            $this->pdo->query($q);
    
            $q = sprintf("
                UPDATE {$this->tbName}
                SET {$this->tbRight} = {$this->tbRight} - 1,
                    {$this->tbLeft} = {$this->tbLeft} - 1 
                WHERE {$this->tbLeft} BETWEEN %d AND %d",
                $lft,
                $rgt
            );
            $this->pdo->query($q);
    
            $q = sprintf("
                UPDATE {$this->tbName} 
                SET {$this->tbRight} = {$this->tbRight} - 2
                WHERE {$this->tbRight} > %d",
                $rgt
            );
            $this->pdo->query($q);
    
            $q = sprintf("
                UPDATE {$this->tbName}
                SET {$this->tbLeft} = {$this->tbLeft} - 2
                WHERE {$this->tbLeft} > %d",
                $rgt
            );
            $this->pdo->query($q);
            $this->pdo->commit();
        } catch (\PDOException $ex) {
            $this->pdo->rollBack();
            throw new NestedSetException($ex);
        } finally {
            $this->pdo->exec('UNLOCK TABLES');
        }
        
        $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, TRUE);
    }
}

?>