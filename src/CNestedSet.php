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
     * Add the first child node to the root element
     *
     * @param int $parent_id
     * @param array $values
     * @return array
     * @throw NestedSetException
     */
    function addRootChild($values);

    /*
     * Change the tree's parent node.
     *
     * @param int $cur_parent_id
     * @param int $new_parent_id
     * @return void
     * @throw NestedSetException
     */
    function moveTree($cur_parent_id, $new_parent_id);

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

    protected $pdo;

    private $isAutocommit;

    function __construct(\PDO $pdo, $config = []) {
        if ($pdo) {
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo = $pdo;
        }
        else
            throw new NestedSetException('You have to pass PDO connection!');

        $this->isAutocommit = $this->pdo->getAttribute(\PDO::ATTR_AUTOCOMMIT);

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

    function addChild($parent_id, $values = array()) {
        if (!$this->_isNodeExists($parent_id))
            throw new NestedSetException('Node doesn\'t exist!');

        $last_id = FALSE;
        
        if ($this->isAutocommit)
            $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, FALSE);

        If (!$this->pdo->inTransaction()) {
            $isTransaction = TRUE;
            $this->pdo->beginTransaction();
        }
        else
            $isTransaction = FALSE;
            
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

            if ($isTransaction) 
                $this->pdo->commit();
        } catch (\PDOException $ex) {
            if ($isTransaction)
                $this->pdo->rollBack();
            throw new NestedSetException($ex);
        } finally {
            $this->pdo->exec('UNLOCK TABLES');
        }
        
        if ($this->isAutocommit)
            $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, TRUE);
        
        return $last_id;
    }

    function addRootChild($values = array()) {
        $q = "
            SELECT {$this->tbId} AS id
            FROM {$this->tbName}
            ORDER BY {$this->tbLeft}
            LIMIT 1";
        $rootId = $this
            ->pdo
            ->query($q)
            ->fetchAll(\PDO::FETCH_ASSOC)[0]['id'];

        return $this->addChild($rootId, $values);
    }

    function moveTree($cur_parent_id, $new_parent_id) {
        if (!$this->_isNodeExists($cur_parent_id))
            throw new NestedSetException('Current node doesn\'t exist!');
        if (!$this->_isNodeExists($new_parent_id))
            throw new NestedSetException('New parent node doesn\'t exist!');

        if ($cur_parent_id != $new_parent_id) {
            if ($this->isAutocommit)
                $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, FALSE);
    
            If (!$this->pdo->inTransaction()) {
                $isTransaction = TRUE;
                $this->pdo->beginTransaction();
            }
            else
                $isTransaction = FALSE;

            $this->pdo->exec("LOCK TABLES {$this->tbName} WRITE");

            try {
                $q = sprintf("
                    SELECT {$this->tbRight} AS rgt
                    FROM {$this->tbName}
                    WHERE {$this->tbId} = %d",
                    $new_parent_id
                );
                $iNewParentRight = $this
                    ->pdo
                    ->query($q)
                    ->fetchAll(\PDO::FETCH_ASSOC)[0]['rgt'];
    
                $q = sprintf("
                    SELECT 
                        {$this->tbLeft} AS iCurLeft,
                        {$this->tbRight} AS iCurRight
                    FROM {$this->tbName}
                    WHERE {$this->tbId} = %d",
                    $cur_parent_id
                );
                extract($this
                    ->pdo
                    ->query($q)
                    ->fetchAll(\PDO::FETCH_ASSOC)[0]);
    
                unset($q);
                if ($iNewParentRight < $iCurLeft) {
                    $q = "
                        UPDATE {$this->tbName}
                        SET {$this->tbLeft} = {$this->tbLeft} + 
                        CASE
                            WHEN {$this->tbLeft}
                                BETWEEN {$iCurLeft} AND {$iCurRight}
                            THEN {$iNewParentRight} - {$iCurLeft}
                            WHEN {$this->tbLeft}
                                BETWEEN {$iNewParentRight} AND {$iCurLeft} - 1
                            THEN {$iCurRight} - {$iCurLeft} + 1
                            ELSE 0
                        END,
                        {$this->tbRight} = {$this->tbRight} + 
                        CASE
                            WHEN {$this->tbRight}
                                BETWEEN {$iCurLeft} AND {$iCurRight}
                            THEN {$iNewParentRight} - {$iCurLeft}
                            WHEN {$this->tbRight}
                                BETWEEN {$iNewParentRight} AND {$iCurLeft} - 1
                            THEN {$iCurRight} - {$iCurLeft} + 1
                            ELSE 0
                        END
                        WHERE {$this->tbLeft} 
                            BETWEEN {$iNewParentRight} AND {$iCurRight}
                        OR {$this->tbRight}
                            BETWEEN {$iNewParentRight} AND {$iCurRight}";
                }
                else {
                    $q = "
                        UPDATE {$this->tbName}
                        SET {$this->tbLeft} = {$this->tbLeft} + 
                        CASE
                            WHEN {$this->tbLeft}
                                BETWEEN {$iCurLeft} AND {$iCurRight}
                            THEN {$iNewParentRight} - {$iCurRight} - 1
                            WHEN {$this->tbLeft}
                                BETWEEN {$iCurRight} + 1 AND {$iNewParentRight} - 1
                            THEN {$iCurLeft} - {$iCurRight} - 1
                            ELSE 0
                        END,
                        {$this->tbRight} = {$this->tbRight} + 
                        CASE
                            WHEN {$this->tbRight}
                                BETWEEN {$iCurLeft} AND {$iCurRight}
                            THEN {$iNewParentRight} - {$iCurRight} - 1
                            WHEN {$this->tbRight}
                                BETWEEN {$iCurRight} + 1 AND {$iNewParentRight} - 1
                            THEN {$iCurLeft} - {$iCurRight} - 1
                            ELSE 0
                        END
                        WHERE {$this->tbLeft} 
                            BETWEEN {$iCurLeft} AND {$iNewParentRight}
                        OR {$this->tbRight}
                            BETWEEN {$iCurLeft} AND {$iNewParentRight}";
                }
                $this->pdo->query($q);

                if ($isTransaction)
                    $this->pdo->commit();
            } catch (\PDOException $ex) {
                if ($isTransaction)
                    $this->pdo->rollBack();
                throw new NestedSetException($ex);
            } finally {
                $this->pdo->exec('UNLOCK TABLES');
            }

            if ($this->isAutocommit)
                $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, TRUE);
            
        }
    }
    
    function deleteTree($node_id) {
        if (!$this->_isNodeExists($node_id))
            throw new NestedSetException('Node doesn\'t exist!');

        if ($this->isAutocommit)
            $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, FALSE);

        If (!$this->pdo->inTransaction()) {
            $isTransaction = TRUE;
            $this->pdo->beginTransaction();
        }
        else
            $isTransaction = FALSE;

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

            if ($isTransaction)
                $this->pdo->commit();
        } catch (\PDOException $ex) {
            if ($isTransaction)
                $this->pdo->rollBack();
            throw new NestedSetException($ex);
        } finally {
            $this->pdo->exec('UNLOCK TABLES');
        }

        if ($this->isAutocommit)
            $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, TRUE);
    }

    function deleteNode($node_id) {
        if (!$this->_isNodeExists($node_id))
            throw new NestedSetException('Node doesn\'t exist!');

        if ($this->isAutocommit)
            $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, FALSE);

        If (!$this->pdo->inTransaction()) {
            $isTransaction = TRUE;
            $this->pdo->beginTransaction();
        }
        else
            $isTransaction = FALSE;

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

            if ($isTransaction)
                $this->pdo->commit();
        } catch (\PDOException $ex) {
            if ($isTransaction)
                $this->pdo->rollBack();
            throw new NestedSetException($ex);
        } finally {
            $this->pdo->exec('UNLOCK TABLES');
        }

        if ($this->isAutocommit)
            $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, TRUE);
    }
}

?>