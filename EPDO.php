<?php

// MIT License
//
// Copyright (c) 2017 Gareth Hughes
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

class EPDO extends PDO {
    public function __construct(...$params) {
        parent::__construct(...$params);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function __invoke($sql, ...$params) {
        // flatten $params
        list($anchors, $params) = $this->flattenParams($params);

        $idx = -1;
        $sql = preg_replace_callback('~\?~', function($matches) use (&$idx, &$anchors) {
            $idx++;
            return $anchors[$idx];
        }, $sql);

        $stmt = $this->prepare($sql);
        $stmt->execute($params);

        return new EPDOResult($stmt, $this, $sql);
    }

    public function transaction($func) {
        try {
            $this->beginTransaction();
            $result = $func($this);
            $this->commit();
            return $result;
        } catch (Exception $ex) {
            $this->rollBack();
            throw $ex;
        }
    }

    // misc methods
    protected function isIndexedArray(array &$arr) : bool {
        if (count($arr) === 0) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    protected function flattenParams(array $params, bool $brace = false) : array {
        if (!$this->isIndexedArray($params)) {
            throw new Exception("Params must be an indexed array");
        }

        $anchors = [];
        $newParams = [];

        foreach ($params as $param) {

            if (is_bool($param)) {
                $param = (int)$param;
            }

            // if $param is a scalar, "?" remains "?"
            if (is_scalar($param) || is_null($param)) {
                $anchors[] = "?";
                $newParams[] = $param;
                continue;
            }

            // if $param is a numeric indexed array, substitute "?" for "(?,?,?,...)"
            if ($this->isIndexedArray($param)) {
                list($a,$p) = $this->flattenParams($param, true);
                $anchor = implode(', ', $a);
                $anchors[] = $brace ? "({$anchor})" : $anchor;
                array_push($newParams, ...$p);
                continue;
            }

            // if $param is an object, treat it like an associative array
            if (is_object($param)) {
                $param = (array)$param;
            }

            // if $param is an assoc array, substitute "?" for "`key1` = ?, `key2` = ?, ..."
            if (is_array($param)) {
                $a = [];
                foreach ($param as $k => $v) {
                    if (!is_scalar($v) && !is_null($v)) {
                        throw new Exception("Recursive substitutions of non-scalars are not supported");
                    }
                    $a[] = "`${k}` = ?";
                    $newParams[] = $v;
                }
                $anchors[] = implode(", ", $a);
                continue;
            }
        }

        return [$anchors, $newParams];
    }
}

class EPDOResult implements Iterator, ArrayAccess {
    private $_stmt;
    private $_curIdx;
    private $_rows;
    private $_sql;

    public function __construct(PDOStatement &$stmt, EPDO &$pdo, string $sql) {
        $this->_stmt = $stmt;
        $this->_sql = $sql;
        $this->_curIdx = 0;
        $this->_affectedRows = $stmt->rowCount();
        $this->_lastId = $pdo->lastInsertId();
        $this->_rows = null;
    }

    public function __get($key) {
        switch ($key) {
            case 'first': $this->_fetchResults(); return isset($this->_rows[0]) ? $this->_rows[0] : null;
            case 'all': $this->_fetchResults(); return $this->_rows;
            case 'affectedRows': return $this->_affectedRows;
            case 'lastId': return $this->_lastId;
            case 'count': $this->_fetchResults(); return count($this->_rows);
            case 'value':
                $f = $this->first;
                if (isset($f)) {
                    $vals = array_values((array)$f);
                    if (count($vals)) {
                        return $vals[0];
                    }
                }
                return null;
        }

        return null;
    }

    // what should be returned from print_r() and var_dump() etc...
    public function __debugInfo() {
        $this->_fetchResults();
        return $this->_rows;
    }

    // what should be output when echoing the result object itself
    public function __toString() {
        $rowCount = isset($this->_rows) ? count($this->_rows) : '???';
        $query = $this->indent($this->_sql, true);
        $firstResult = $this->indent(var_export($this->first, true));
        return "\nEPDOResult:\n----------------\nResults        : {$rowCount}\nAffected Rows  : {$this->_affectedRows}\nLast Insert Id : {$this->_lastId}\nQuery          ->\n{$query}
\nFirst Result   ->\n{$firstResult}\n";
    }

    // lazy load results
    private function _fetchResults() {
        if (!isset($this->_rows)) {
            $this->_rows = $this->_stmt->fetchAll(PDO::FETCH_OBJ);
        }
    }

    // methods for Iterator
    public function current() {
        $this->_fetchResults();
        return $this->_rows[$this->_curIdx];
    }
    public function key() {
        $this->_fetchResults();
        return $this->_curIdx;
    }
    public function next() {
        $this->_fetchResults();
        $this->_curIdx++;
    }
    public function rewind() {
        $this->_fetchResults();
        $this->_curIdx = 0;
    }
    public function valid() {
        $this->_fetchResults();
        return isset($this->_rows[$this->_curIdx]);
    }

    // methods for ArrayAccess
    public function offsetExists($idx) {
        $this->_fetchResults();
        return isset($this->_rows[$idx]);
    }
    public function offsetGet($idx) {
        $this->_fetchResults();
        return $this->_rows[$idx];
    }
    public function offsetSet($idx, $value) {
        throw new Exception("Cannot overwrite result");
    }
    public function offsetUnset($idx) {
        throw new Exception("Cannot unset result");
    }

    // misc methods
    private function indent($str, $trim = false, $indent = "    ") {
        $str = explode("\n", $str);
        if ($trim) {
            $str = array_map(function($line){ return trim($line); }, $str);
            $str = array_filter($str);
        }
        $str = array_map(function($line)use($indent){
            return $indent.$line;
        }, $str);
        $str = implode("\n", $str);
        return $str;
    }
}
